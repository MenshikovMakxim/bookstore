<?php

namespace App\Observers;

use App\Enums\StockMovementReason;
use App\Events\BookStockChanged;
use App\Models\Stock;
use App\Models\StockMovement;

/**
 * Writes the stock_movements ledger whenever a Stock row is created, its
 * quantity changes, or it is deleted through Eloquent (save, attach, sync,
 * updateExistingPivot, increment, detach). Query-level updates (Stock::where()->update(), DB::table) skip
 * model events and therefore leave no movement — see docs/06-attributes-casts.md.
 *
 * Since stage 10 it also broadcasts BookStockChanged, so open book pages update
 * their stock badge live (docs/10-broadcasting.md).
 */
class StockObserver
{
    public function created(Stock $stock): void
    {
        $this->broadcast($stock);
        $this->record($stock, (int) ($stock->quantity ?? 0), $stock->movementReason ?? StockMovementReason::Receipt);
    }

    public function updated(Stock $stock): void
    {
        if ($stock->wasChanged(['quantity', 'reserved'])) {
            $this->broadcast($stock);
        }

        if (! $stock->wasChanged('quantity')) {
            return;
        }

        $delta = (int) $stock->quantity - (int) $stock->getOriginal('quantity');

        $this->record($stock, $delta, $stock->movementReason ?? StockMovementReason::Adjustment);
    }

    /**
     * Removing a stock row (detach, sync, delete) takes its copies out of the ledger:
     * a negative adjustment keeps the running total explainable.
     */
    public function deleted(Stock $stock): void
    {
        $this->broadcast($stock);
        $this->record($stock, -(int) $stock->quantity, $stock->movementReason ?? StockMovementReason::Adjustment);
    }

    /**
     * Before record(): it resets movementSource, which tells an import apart.
     */
    private function broadcast(Stock $stock): void
    {
        BookStockChanged::dispatch($stock->book_id, fromImport: isset($stock->movementSource['import_id']));
    }

    private function record(Stock $stock, int $delta, StockMovementReason $reason): void
    {
        // Reason and source are per operation, not per model: reset them for the next save.
        $source = $stock->movementSource;
        $stock->movementReason = null;
        $stock->movementSource = [];

        if ($delta === 0) {
            return;
        }

        StockMovement::create([
            'warehouse_id' => $stock->warehouse_id,
            'book_id' => $stock->book_id,
            'user_id' => auth()->id(),
            'delta' => $delta,
            'reason' => $reason,
            ...$source,
        ]);
    }
}
