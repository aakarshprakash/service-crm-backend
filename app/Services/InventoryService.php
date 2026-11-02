<?php

namespace App\Services;

use App\Models\InventoryItem;
use App\Models\InventoryStock;
use App\Models\InventoryTransaction;
use App\Models\JobInventoryUsage;
use App\Models\JobVisit;
use App\Models\TechnicianStock;
use App\Models\TechnicianStockMove;
use App\Models\User;
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
                'from_technician_stock' => false,
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

    /** The office hands parts to a technician: they leave the branch's stock and join the technician's. */
    public function allocateToTechnician(User $technician, int $branchId, InventoryItem $item, float $quantity, ?string $remarks, int $userId): TechnicianStock
    {
        return DB::transaction(function () use ($technician, $branchId, $item, $quantity, $remarks, $userId) {
            $branchStock = $this->lockedStock($branchId, $item->id);
            $this->move($branchStock, $item, -$quantity, 'allocated', [
                'unit_cost' => $branchStock->avg_unit_cost, 'reference_type' => 'technician', 'reference_id' => $technician->id,
                'remarks' => $remarks ?: "Allocated to {$technician->name}", 'created_by' => $userId,
            ]);
            $stock = $this->lockedTechnicianStock($technician->id, $item->id, $branchId);
            $stock->branch_id = $branchId;
            $this->moveTechnicianStock($stock, $quantity, 'allocated', ['branch_id' => $branchId, 'remarks' => $remarks, 'created_by' => $userId]);

            return $stock;
        });
    }

    /** Unused parts handed back: they rejoin a branch's stock. */
    public function returnFromTechnician(User $technician, int $branchId, InventoryItem $item, float $quantity, ?string $remarks, int $userId): TechnicianStock
    {
        return DB::transaction(function () use ($technician, $branchId, $item, $quantity, $remarks, $userId) {
            $stock = $this->lockedTechnicianStock($technician->id, $item->id, $branchId);
            $this->moveTechnicianStock($stock, -$quantity, 'returned', ['branch_id' => $branchId, 'remarks' => $remarks, 'created_by' => $userId], $item);
            $this->move($this->lockedStock($branchId, $item->id), $item, $quantity, 'allocation_return', [
                'reference_type' => 'technician', 'reference_id' => $technician->id,
                'remarks' => $remarks ?: "Returned by {$technician->name}", 'created_by' => $userId,
            ]);

            return $stock;
        });
    }

    /** How much of an item the technician is holding right now. */
    public function technicianHolds(int $technicianId, int $itemId): float
    {
        return (float) (TechnicianStock::where(['technician_id' => $technicianId, 'item_id' => $itemId])->value('quantity') ?? 0);
    }

    /** Technician uses a part allocated to them: the branch's stock already went down at allocation. */
    public function consumeFromTechnician(JobVisit $visit, InventoryItem $item, int $technicianId, float $quantity, ?int $unitPrice, int $userId): JobInventoryUsage
    {
        return DB::transaction(function () use ($visit, $item, $technicianId, $quantity, $unitPrice, $userId) {
            $stock = TechnicianStock::where(['technician_id' => $technicianId, 'item_id' => $item->id])->lockForUpdate()->first();
            if (! $stock) {
                throw ValidationException::withMessages(['quantity' => "{$item->name} is not allocated to you."]);
            }
            $this->moveTechnicianStock($stock, -$quantity, 'used', [
                'branch_id' => $stock->branch_id, 'job_id' => $visit->job_id, 'job_visit_id' => $visit->id, 'created_by' => $userId,
            ], $item);
            $price = $unitPrice ?? $item->unit_price;

            return JobInventoryUsage::create([
                'job_id' => $visit->job_id,
                'job_visit_id' => $visit->id,
                'item_id' => $item->id,
                'branch_id' => $stock->branch_id,
                'from_technician_stock' => true,
                'quantity' => $quantity,
                'unit_price' => $price,
                'total_price' => (int) round($quantity * $price),
            ]);
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

    /** Undo a usage line while the visit is still open (returns stock to wherever it came from). */
    public function returnUsage(JobInventoryUsage $usage, int $technicianId, int $userId): void
    {
        DB::transaction(function () use ($usage, $technicianId, $userId) {
            $item = InventoryItem::findOrFail($usage->item_id);
            if ($usage->from_technician_stock) {
                $this->moveTechnicianStock($this->lockedTechnicianStock($technicianId, $usage->item_id, $usage->branch_id), $usage->quantity, 'usage_undone', [
                    'branch_id' => $usage->branch_id, 'job_id' => $usage->job_id, 'job_visit_id' => $usage->job_visit_id, 'created_by' => $userId,
                    'remarks' => 'Removed from visit',
                ]);
                $usage->delete();

                return;
            }
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

    private function lockedTechnicianStock(int $technicianId, int $itemId, int $branchId): TechnicianStock
    {
        TechnicianStock::firstOrCreate(['technician_id' => $technicianId, 'item_id' => $itemId], ['branch_id' => $branchId, 'quantity' => 0]);

        return TechnicianStock::where(['technician_id' => $technicianId, 'item_id' => $itemId])->lockForUpdate()->firstOrFail();
    }

    private function moveTechnicianStock(TechnicianStock $stock, float $delta, string $type, array $meta, ?InventoryItem $item = null): void
    {
        $delta = round($delta, 3);
        $balance = round($stock->quantity + $delta, 3);
        if ($balance < 0) {
            $item ??= InventoryItem::findOrFail($stock->item_id);
            throw ValidationException::withMessages([
                'quantity' => sprintf('Only %s %s of %s is allocated to this technician.', rtrim(rtrim(number_format($stock->quantity, 3, '.', ''), '0'), '.'), $item->unit_of_measure, $item->name),
            ]);
        }
        $stock->quantity = $balance;
        $stock->save();

        TechnicianStockMove::create([
            'technician_id' => $stock->technician_id,
            'item_id' => $stock->item_id,
            'type' => $type,
            'quantity' => $delta,
            'balance_after' => $balance,
        ] + $meta);
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
            DB::afterCommit(fn () => $this->notifications->notifyRoles(['admin', 'accountant', 'store_keeper'], 'low_stock', 'Low stock alert',
                "{$item->name} ({$item->code}) at {$branch} is down to {$balance} {$item->unit_of_measure} (reorder level {$item->reorder_level}).",
                ['item_id' => $item->id, 'branch_id' => $stock->branch_id]));
        }

        return $transaction;
    }
}
