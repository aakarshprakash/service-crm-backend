<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\InventoryTransaction;
use App\Models\JobInventoryUsage;
use App\Models\JobVisit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * All stock movements go through here: the stock row is locked, the balance can
 * never go negative, and every change is written to inventory_transactions (§5.7).
 */
class InventoryService
{
    public function __construct(private NotificationService $notifications) {}

    public function stockIn(int $branchId, InventoryItem $item, float $quantity, int $unitCost, array $meta = []): InventoryTransaction
    {
        return DB::transaction(function () use ($branchId, $item, $quantity, $unitCost, $meta) {
            $stock = $this->lockedStock($branchId, $item->id);
            // Weighted-average cost keeps valuation accurate across purchases at different prices.
            $existingValue = max(0, $stock->quantity_available) * $stock->avg_unit_cost;
            $newQty = $stock->quantity_available + $quantity;
            $stock->avg_unit_cost = $newQty > 0 ? (int) round(($existingValue + $quantity * $unitCost) / $newQty) : $unitCost;

            return $this->move($stock, $item, $quantity, 'stock_in', $meta + ['unit_cost' => $unitCost]);
        });
    }

    public function transfer(int $fromBranchId, int $toBranchId, InventoryItem $item, float $quantity, ?string $remarks, int $userId): void
    {
        if ($fromBranchId === $toBranchId) {
            throw ValidationException::withMessages(['to_branch_id' => 'Source and destination branch must differ.']);
        }

        DB::transaction(function () use ($fromBranchId, $toBranchId, $item, $quantity, $remarks, $userId) {
            // Lock in a stable order to avoid deadlocks between concurrent transfers.
            $ids = [$fromBranchId, $toBranchId];
            sort($ids);
            $locked = [];
            foreach ($ids as $id) {
                $locked[$id] = $this->lockedStock($id, $item->id);
            }
            $from = $locked[$fromBranchId];
            $to = $locked[$toBranchId];

            $out = $this->move($from, $item, -$quantity, 'transfer_out', [
                'remarks' => $remarks, 'created_by' => $userId, 'unit_cost' => $from->avg_unit_cost,
            ]);

            $existingValue = max(0, $to->quantity_available) * $to->avg_unit_cost;
            $newQty = $to->quantity_available + $quantity;
            $to->avg_unit_cost = $newQty > 0 ? (int) round(($existingValue + $quantity * $from->avg_unit_cost) / $newQty) : $from->avg_unit_cost;
            $this->move($to, $item, $quantity, 'transfer_in', [
                'remarks' => $remarks, 'created_by' => $userId, 'unit_cost' => $from->avg_unit_cost,
                'reference_type' => 'inventory_transaction', 'reference_id' => $out->id,
            ]);
        });
    }

    /** Manual correction (breakage, loss, stock count) – reason is mandatory (FR-7.8). */
    public function adjust(int $branchId, InventoryItem $item, float $quantityDelta, string $reason, int $userId): InventoryTransaction
    {
        return DB::transaction(fn () => $this->move($this->lockedStock($branchId, $item->id), $item, $quantityDelta, 'adjustment', [
            'remarks' => $reason, 'created_by' => $userId,
        ]));
    }

    /** Technician adds a spare / consumable to the active visit (FR-7.5, FR-7.6). */
    public function consumeForVisit(JobVisit $visit, InventoryItem $item, int $branchId, float $quantity, ?int $unitPrice, int $userId): JobInventoryUsage
    {
        return DB::transaction(function () use ($visit, $item, $branchId, $quantity, $unitPrice, $userId) {
            $stock = $this->lockedStock($branchId, $item->id);
            $price = $unitPrice ?? $item->unit_price;

            $usage = JobInventoryUsage::create([
                'job_id' => $visit->job_id,
                'job_visit_id' => $visit->id,
                'item_id' => $item->id,
                'branch_id' => $branchId,
                'quantity' => $quantity,
                'unit_price' => $price,
                'total_price' => (int) round($quantity * $price),
            ]);

            $this->move($stock, $item, -$quantity, 'stock_out', [
                'unit_cost' => $stock->avg_unit_cost,
                'reference_type' => 'job_visit', 'reference_id' => $visit->id, 'created_by' => $userId,
            ]);

            return $usage;
        });
    }

    /** Part sold over the counter on a walk-in bill. */
    public function sellForInvoice(int $invoiceId, InventoryItem $item, int $branchId, float $quantity, int $userId): void
    {
        DB::transaction(function () use ($invoiceId, $item, $branchId, $quantity, $userId) {
            $stock = $this->lockedStock($branchId, $item->id);
            $this->move($stock, $item, -$quantity, 'sale', [
                'unit_cost' => $stock->avg_unit_cost,
                'reference_type' => 'invoice', 'reference_id' => $invoiceId, 'created_by' => $userId,
                'remarks' => 'Walk-in sale',
            ]);
        });
    }

    /** Undo a usage line while the visit is still open (returns stock). */
    public function returnUsage(JobInventoryUsage $usage, int $userId): void
    {
        DB::transaction(function () use ($usage, $userId) {
            $item = InventoryItem::findOrFail($usage->item_id);
            $this->move($this->lockedStock($usage->branch_id, $usage->item_id), $item, $usage->quantity, 'return', [
                'reference_type' => 'job_visit', 'reference_id' => $usage->job_visit_id, 'created_by' => $userId,
                'remarks' => 'Removed from visit',
            ]);
            $usage->delete();
        });
    }

    public function availability(InventoryItem $item): array
    {
        return InventoryStock::with('branch:id,name')->where('item_id', $item->id)->get()
            ->map(fn ($s) => ['branch_id' => $s->branch_id, 'branch' => $s->branch?->name, 'quantity' => $s->quantity_available])
            ->all();
    }

    private function lockedStock(int $branchId, int $itemId): InventoryStock
    {
        InventoryStock::firstOrCreate(['branch_id' => $branchId, 'item_id' => $itemId], ['quantity_available' => 0]);

        return InventoryStock::where(['branch_id' => $branchId, 'item_id' => $itemId])->lockForUpdate()->firstOrFail();
    }

    private function move(InventoryStock $stock, InventoryItem $item, float $delta, string $type, array $meta): InventoryTransaction
    {
        $delta = round($delta, 3);
        $balance = round($stock->quantity_available + $delta, 3);

        if ($balance < 0) {
            throw ValidationException::withMessages([
                'quantity' => sprintf('Insufficient stock for %s: %s %s available.', $item->name, rtrim(rtrim(number_format($stock->quantity_available, 3, '.', ''), '0'), '.'), $item->unit_of_measure),
            ]);
        }

        $stock->quantity_available = $balance;
        if ($balance > $item->reorder_level) {
            $stock->low_stock_alerted_at = null; // re-arm the alert after restocking
        }
        $stock->save();

        $transaction = InventoryTransaction::create([
            'branch_id' => $stock->branch_id,
            'item_id' => $item->id,
            'type' => $type,
            'quantity' => $delta,
            'balance_after' => $balance,
        ] + array_intersect_key($meta, array_flip(['unit_cost', 'supplier_id', 'invoice_ref', 'reference_type', 'reference_id', 'remarks', 'created_by'])));

        if ($delta < 0 && $item->reorder_level > 0 && $balance <= $item->reorder_level && ! $stock->low_stock_alerted_at) {
            $stock->forceFill(['low_stock_alerted_at' => now()])->save();
            $branch = $stock->branch()->value('name');
            DB::afterCommit(fn () => $this->notifications->notifyRoles(['admin', 'accountant'], 'low_stock', 'Low stock alert',
                "{$item->name} ({$item->code}) at {$branch} is down to {$balance} {$item->unit_of_measure} (reorder level {$item->reorder_level}).",
                ['item_id' => $item->id, 'branch_id' => $stock->branch_id]));
        }

        return $transaction;
    }
}
