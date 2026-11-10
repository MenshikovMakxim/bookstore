<?php

namespace App\Models;

use App\Enums\ImportStatus;
use App\Events\ImportProgressed;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An uploaded CSV file of books + quantities for one warehouse (admin area),
 * processed by the queued chain in Admin\ImportController::store().
 */
#[Fillable(['user_id', 'warehouse_id', 'original_name', 'path', 'status', 'total_rows', 'imported', 'failed', 'errors', 'batch_id', 'finished_at'])]
class Import extends Model
{
    /**
     * Expected CSV header (order does not matter, extra columns are ignored).
     */
    public const COLUMNS = ['isbn', 'title', 'authors', 'genre', 'format', 'price', 'published_at', 'quantity', 'shelf_code'];

    /**
     * Columns ValidateImportFile insists on; the rest may be missing.
     */
    public const REQUIRED_COLUMNS = ['isbn', 'title', 'authors', 'genre', 'format', 'price', 'quantity'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'errors' => 'array',
            'finished_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * @return HasMany<StockMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class);
    }

    /**
     * Gave up (a job's failed() hook). Sticky: the batch's finally callback does not
     * overwrite it. The reason is stored under the "file" key of the errors map.
     */
    public function markFailed(string $reason): void
    {
        $this->update([
            'status' => ImportStatus::Failed,
            'errors' => [...($this->errors ?? []), 'file' => [$reason]],
            'finished_at' => now(),
        ]);

        ImportProgressed::dispatch($this->id);
    }

    /**
     * Batch "then"/"finally" callbacks: settle the final status unless already failed.
     * Static and id-based: batch callbacks are serialized closures and should
     * capture plain values only.
     */
    public static function settle(int $importId): void
    {
        $import = self::find($importId);

        if ($import === null || $import->status->isFinished()) {
            return;
        }

        $import->update([
            'status' => $import->failed > 0 ? ImportStatus::CompletedWithErrors : ImportStatus::Completed,
            'finished_at' => now(),
        ]);

        ImportProgressed::dispatch($import->id);
    }

    /**
     * Row errors only (keys are line numbers); "file"/"chunk …" keys are job-level.
     *
     * @return array<int, list<string>>
     */
    public function rowErrors(): array
    {
        return array_filter($this->errors ?? [], fn ($key) => is_int($key), ARRAY_FILTER_USE_KEY);
    }

    /**
     * Rows processed so far (imported + rejected), for the progress bar.
     */
    public function processedRows(): int
    {
        return $this->imported + $this->failed;
    }
}
