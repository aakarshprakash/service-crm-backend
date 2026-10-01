<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Sequence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Walk-in (counter) bills: a customer comes to the service centre for a repair or to buy
 * parts. Creates an invoice with its own lines, deducts sold parts from the branch's
 * stock, and records what was paid at the counter — all in one transaction.
 */
class WalkInBillService
{
    public function __construct(private InventoryService $inventory, private PaymentService $payments) {}

    /**
     * @param  array{customer_id?: int|null, customer?: array{name: string, phone: string}|null, branch_id?: int|null,
     *               items: list<array{type: string, item_id?: int|null, description?: string|null, quantity: float, unit_price?: int|null}>,
     *               discount?: int|null, notes?: string|null, payment_method?: string|null, amount_paid?: int|null, reference_no?: string|null}  $data
     */
    public function create(array $data, User $by): Invoice
    {
        $invoice = DB::transaction(function () use ($data, $by) {
            $tenant = Tenant::findOrFail($by->tenant_id);
            $customer = $this->customer($data);
            $branchId = $data['branch_id'] ?? $by->branch_id ?? Branch::orderBy('id')->value('id');

            $lines = [];
            $service = 0;
            $parts = 0;
            foreach ($data['items'] as $i => $line) {
                $qty = round((float) $line['quantity'], 3);
                if ($line['type'] === 'part') {
                    $item = InventoryItem::findOrFail($line['item_id']);
                    $price = (int) ($line['unit_price'] ?? $item->unit_price);
                    $description = trim($line['description'] ?? '') ?: "{$item->name} ({$item->code})";
                } else {
                    $item = null;
                    $price = (int) ($line['unit_price'] ?? 0);
                    $description = trim($line['description'] ?? '');
                    if ($description === '') {
                        throw ValidationException::withMessages(["items.$i.description" => 'Describe the service.']);
                    }
                }
                $total = (int) round($qty * $price);
                $line['type'] === 'part' ? $parts += $total : $service += $total;
                $lines[] = compact('item', 'qty', 'price', 'description', 'total') + ['type' => $line['type']];
            }

            $discount = (int) ($data['discount'] ?? 0);
            if ($discount > $service + $parts) {
                throw ValidationException::withMessages(['discount' => 'Discount can’t be more than the bill total.']);
            }
            if ($parts > 0 && ! $branchId) {
                throw ValidationException::withMessages(['branch_id' => 'Choose the branch the parts are sold from.']);
            }

            $invoice = Invoice::create([
                'job_id' => null,
                'source' => 'walk_in',
                'customer_id' => $customer->id,
                'branch_id' => $branchId,
                'invoice_number' => Sequence::formatted($tenant->id, 'invoice', $tenant->setting('invoice_prefix', 'INV')),
                'total_service_charge' => $service,
                'total_spare_charge' => $parts,
                'discount_amount' => $discount,
                'total_amount' => $service + $parts - $discount,
                'is_credit' => ($data['payment_method'] ?? null) === 'credit',
                'notes' => $data['notes'] ?? null,
                'created_by' => $by->id,
                'pay_token' => Str::random(48),
                'generated_at' => now(),
            ]);

            foreach ($lines as $l) {
                InvoiceItem::create([
                    'invoice_id' => $invoice->id, 'type' => $l['type'], 'item_id' => $l['item']?->id,
                    'description' => mb_substr($l['description'], 0, 200), 'quantity' => $l['qty'], 'unit_price' => $l['price'], 'total' => $l['total'],
                ]);
                if ($l['item']) {
                    $this->inventory->sellForInvoice($invoice->id, $l['item'], $branchId, $l['qty'], $by->id);
                }
            }
            $invoice->refreshPaymentTotals();

            // Same transaction: a rejected payment must not leave an unpaid duplicate bill behind.
            $method = $data['payment_method'] ?? null;
            $paid = (int) ($data['amount_paid'] ?? 0);
            if ($method && $method !== 'credit' && $paid > 0) {
                $this->payments->recordOffline($invoice, $paid, $method, [
                    'reference_no' => $data['reference_no'] ?? null,
                    'collected_by' => $by->id,
                    'remarks' => 'Walk-in counter',
                ]);
            }

            return $invoice;
        });

        return $invoice->fresh(['customer', 'items', 'payments', 'branch:id,name']);
    }

    private function customer(array $data): Customer
    {
        if (! empty($data['customer_id'])) {
            return Customer::findOrFail($data['customer_id']);
        }
        $c = $data['customer'] ?? null;
        if (! $c || empty($c['name']) || empty($c['phone'])) {
            throw ValidationException::withMessages(['customer' => 'Choose a customer or enter a name and phone number.']);
        }
        // Reuse the record when this phone is already a customer, instead of creating a duplicate.
        return Customer::where('phone', $c['phone'])->first() ?? Customer::create(['name' => $c['name'], 'phone' => $c['phone']]);
    }
}
