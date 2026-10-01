<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\WalkInBillService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Counter bills for walk-in customers (repairs at the service centre, parts sold over the counter). */
class WalkInBillController extends Controller
{
    public function __construct(private WalkInBillService $bills) {}

    public function store(Request $request): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $exists = fn (string $t) => Rule::exists($t, 'id')->where('tenant_id', $tenantId);

        $data = $request->validate([
            'customer_id' => ['nullable', 'integer', $exists('customers')->whereNull('deleted_at')],
            'customer' => ['nullable', 'array'],
            'customer.name' => ['required_without:customer_id', 'nullable', 'string', 'max:150'],
            'customer.phone' => ['required_without:customer_id', 'nullable', 'string', 'regex:/^\+?[0-9]{10,15}$/'],
            'branch_id' => ['nullable', 'integer', $exists('branches')],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.type' => ['required', Rule::in(['service', 'part'])],
            'items.*.item_id' => ['required_if:items.*.type,part', 'nullable', 'integer', $exists('inventory_items')],
            'items.*.description' => ['nullable', 'string', 'max:200'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:100000', 'decimal:0,3'],
            'items.*.unit_price' => ['required_if:items.*.type,service', 'nullable', 'integer', 'min:0', 'max:100000000'],
            'discount' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'notes' => ['nullable', 'string', 'max:500'],
            'payment_method' => ['nullable', Rule::in(['cash', 'upi', 'cheque', 'bank_transfer', 'credit'])],
            'amount_paid' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'reference_no' => ['nullable', 'string', 'max:100', Rule::requiredIf(fn () => in_array($request->input('payment_method'), ['upi', 'cheque', 'bank_transfer'], true) && (int) $request->input('amount_paid') > 0)],
        ], [
            'customer.name.required_without' => 'Enter the customer’s name, or pick an existing customer.',
            'customer.phone.required_without' => 'Enter the customer’s phone number, or pick an existing customer.',
            'items.required' => 'Add at least one service or part.',
            'items.*.item_id.required_if' => 'Choose the part.',
            'items.*.unit_price.required_if' => 'Enter the service charge.',
            'reference_no.required' => 'Enter the UPI / cheque / bank reference.',
        ]);

        $invoice = $this->bills->create($data, $request->user());

        return $this->created($invoice, "Bill {$invoice->invoice_number} created.");
    }
}
