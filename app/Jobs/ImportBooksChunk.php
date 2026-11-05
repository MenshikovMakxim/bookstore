<?php

namespace App\Jobs;

use App\Enums\AuthorRole;
use App\Enums\StockMovementReason;
use App\Events\ImportProgressed;
use App\Http\Requests\Admin\StoreBookRequest;
use App\Imports\BookCsv;
use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Import;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Notifications\ImportFailedNotification;
use App\ValueObjects\Money;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Imports config('warehouse.import.chunk_size') rows of the CSV (one job of the batch).
 *
 * - The payload holds the import and a row range, never the rows: the worker reads
 *   them from the file, so the jobs table stays small.
 * - One DB transaction per chunk: an exception rolls back the whole chunk, and the
 *   retry starts from a clean state.
 * - Idempotent: rows that already have a movement for (import_id, source_row) are
 *   skipped, and the unique index on those columns backs this up. Re-running a chunk
 *   (retry, or a worker that died after COMMIT) books nothing twice.
 * - An invalid row is a row error (imports.errors), not an exception: one bad line
 *   must not fail 499 good ones.
 *
 * Retries: #[Tries(3)] — first run + 2 retries, waiting 10 s, then 60 s (#[Backoff]).
 * #[Timeout(120)]: the worker kills a job running longer; queue retry_after (180 s)
 * must be larger, or a second worker would pick up the still-running job.
 */
#[Tries(3)]
#[Timeout(120)]
#[Backoff(10, 60)]
class ImportBooksChunk implements ShouldQueue
{
    use Batchable, Queueable;

    /**
     * @param  int  $offset  first data row of the chunk (0-based)
     */
    public function __construct(
        public Import $import,
        public int $offset,
        public int $limit,
    ) {}

    public function handle(): void
    {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $csv = new BookCsv(Storage::disk('imports')->path($this->import->path));
        /** @var array<int, array<string, string>> $rows line number => row */
        $rows = iterator_to_array($csv->rows($this->offset, $this->limit));

        // Rows already booked by an earlier run of this chunk.
        $done = StockMovement::where('import_id', $this->import->id)
            ->whereIn('source_row', array_keys($rows))
            ->pluck('source_row')
            ->flip();

        // Prefetch lookups once per chunk instead of once per row.
        $genres = Genre::whereIn('slug', array_unique(array_column($rows, 'genre')))->pluck('id', 'slug');
        $books = Book::withTrashed()
            ->whereIn('isbn', array_map(StoreBookRequest::normalizeIsbn(...), array_column($rows, 'isbn')))
            ->get()
            ->keyBy('isbn');
        // Same idea for authors (was Author::firstOrCreate per name) and for this
        // warehouse's stock rows of the books we already have (was Stock::firstOrNew
        // per row). New books have no stock row yet, so there is nothing to look up.
        $authors = $this->prefetchAuthors($rows);
        $stocks = Stock::where('warehouse_id', $this->import->warehouse_id)
            ->whereIn('book_id', $books->pluck('id'))
            ->get()
            ->keyBy('book_id');

        $errors = [];

        DB::transaction(function () use ($rows, $done, $genres, $books, $authors, $stocks, &$errors) {
            foreach ($rows as $line => $row) {
                if ($done->has($line)) {
                    continue;
                }

                $validator = Validator::make($this->normalize($row), $this->rules($genres->keys()->all()));

                if ($validator->fails()) {
                    $errors[$line] = $validator->errors()->all();

                    continue;
                }

                $error = $this->importRow($line, $validator->validated(), $genres, $books, $authors, $stocks);

                if ($error !== null) {
                    $errors[$line] = [$error];
                }
            }

            $this->recordProgress($errors);
        });

        // After the commit: the page re-reads the counters it is told about.
        ImportProgressed::dispatch($this->import->id);
    }

    /**
     * After the last retry. With allowFailures() the other chunks of the batch go on;
     * the import is marked failed and the uploader is told.
     */
    public function failed(?Throwable $exception): void
    {
        $import = $this->import->fresh() ?? $this->import;
        $reason = __('ui.imports.errors.chunk_failed', [
            'from' => $this->offset + 2,
            'to' => $this->offset + $this->limit + 1,
            'message' => Str::limit($exception?->getMessage() ?? '', 300),
        ]);

        $import->markFailed($reason);
        $import->loadMissing('user')->user?->notify(new ImportFailedNotification($import->id, $import->original_name, $reason));
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, string|null>
     */
    private function normalize(array $row): array
    {
        return [
            ...$row,
            'isbn' => StoreBookRequest::normalizeIsbn($row['isbn'] ?? ''),
            'price' => StoreBookRequest::normalizePrice($row['price'] ?? ''),
            'published_at' => ($row['published_at'] ?? '') !== '' ? $row['published_at'] : null,
            'shelf_code' => ($row['shelf_code'] ?? '') !== '' ? $row['shelf_code'] : null,
        ];
    }

    /**
     * The book's own columns reuse StoreBookRequest's rules; the rest is CSV-specific.
     *
     * @param  list<string>  $genreSlugs
     * @return array<string, mixed>
     */
    private function rules(array $genreSlugs): array
    {
        return [
            ...StoreBookRequest::bookFieldRules(),
            'authors' => ['required', 'string', 'max:500'],
            // Rule::in() over the prefetched slugs: Rule::exists() would run one query per row.
            'genre' => ['required', 'string', Rule::in($genreSlugs)],
            'quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'shelf_code' => ['nullable', 'string', 'max:20'],
        ];
    }

    /**
     * Upsert the book by ISBN, attach authors, book the stock receipt.
     *
     * @param  array<string, mixed>  $data
     * @param  Collection<string, int>  $genres  slug => id
     * @param  Collection<string, Book>  $books  isbn => book (updated in place for later rows)
     * @param  Collection<string, int>  $authors  authorKey() => id (updated in place)
     * @param  Collection<int, Stock>  $stocks  book_id => stock in this warehouse (updated in place)
     * @return string|null a row error
     */
    private function importRow(int $line, array $data, Collection $genres, Collection $books, Collection $authors, Collection $stocks): ?string
    {
        $book = $books->get($data['isbn']) ?? new Book(['isbn' => $data['isbn']]);

        if ($book->trashed()) {
            return __('ui.imports.errors.book_trashed', ['isbn' => $data['isbn']]);
        }

        $isNew = ! $book->exists;
        $publishedAt = $data['published_at'] !== null ? Carbon::parse($data['published_at']) : null;
        $book->fill([
            'title' => $data['title'],
            'price' => Money::fromUah($data['price']),
            'format' => $data['format'],
            'genre_id' => $genres[$data['genre']],
            'published_at' => $publishedAt,
            'published_year' => $publishedAt?->year,
        ])->save(); // new book: slug from HasSlug
        $books->put($book->isbn, $book);

        $credits = collect($this->authorIds($data['authors'], $authors))
            ->mapWithKeys(fn (int $id, int $i) => [$id => ['role' => AuthorRole::Author->value, 'sort_order' => $i]])
            ->all();
        // A new book has no credits yet: attach() is one multi-row INSERT. An existing
        // one may have some: syncWithoutDetaching() reads them and adds only the missing.
        $isNew ? $book->authors()->attach($credits) : $book->authors()->syncWithoutDetaching($credits);

        $stock = $stocks->get($book->id);
        // Transient properties read by StockObserver: the movement it writes gets the
        // "import" reason and the (import_id, source_row) pair that makes re-runs safe.
        $reason = StockMovementReason::Import;
        $source = ['import_id' => $this->import->id, 'source_row' => $line];

        if ($stock === null) {
            $stock = new Stock([
                'warehouse_id' => $this->import->warehouse_id,
                'book_id' => $book->id,
                'quantity' => (int) $data['quantity'],
                'reserved' => 0,
                'shelf_code' => $data['shelf_code'],
            ]);
            $stock->movementReason = $reason;
            $stock->movementSource = $source;
            $stock->save();
            $stocks->put($book->id, $stock);
        } else {
            $stock->movementReason = $reason;
            $stock->movementSource = $source;
            // UPDATE ... SET quantity = quantity + ?: atomic, so two chunks running in
            // parallel on different workers cannot overwrite each other's receipt (a
            // prefetched or firstOrNew'd value may be stale). Fires the model events,
            // so StockObserver still books the movement.
            $stock->increment('quantity', (int) $data['quantity'], $stock->shelf_code === null && $data['shelf_code'] !== null
                ? ['shelf_code' => $data['shelf_code']]
                : []);
        }

        return null;
    }

    /**
     * "Іван Франко; Леся Українка" => author ids, created when missing (last word =
     * last name). Exact-name matching: "І. Франко" would become a new author.
     *
     * @param  Collection<string, int>  $authors  prefetched authorKey() => id, extended here
     * @return list<int>
     */
    private function authorIds(string $names, Collection $authors): array
    {
        return collect($this->parseAuthors($names))
            // Not prefetched (a new author, or a name the DB collation matches but
            // mb_strtolower() does not): firstOrCreate() lets the database decide, as before.
            ->map(fn (array $name) => $authors[$this->authorKey(...$name)]
                ??= Author::firstOrCreate(['first_name' => $name[0], 'last_name' => $name[1]])->id)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return list<array{0: string, 1: string}> [first name, last name] pairs
     */
    private function parseAuthors(string $names): array
    {
        return collect(explode(';', $names))
            ->map(fn (string $name) => preg_split('/\s+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY))
            ->filter()
            ->map(function (array $parts) {
                $last = array_pop($parts);

                return [implode(' ', $parts), $last];
            })
            ->values()
            ->all();
    }

    /**
     * One query for every author named in the chunk (by last name, matched in PHP).
     *
     * @param  array<int, array<string, string>>  $rows
     * @return Collection<string, int> authorKey() => id
     */
    private function prefetchAuthors(array $rows): Collection
    {
        $lastNames = collect($rows)
            ->flatMap(fn (array $row) => $this->parseAuthors($row['authors'] ?? ''))
            ->pluck(1)
            ->unique()
            ->values();

        return Author::whereIn('last_name', $lastNames)
            ->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn (Author $author) => [$this->authorKey($author->first_name, $author->last_name) => $author->id]);
    }

    /**
     * Case-insensitive like the MySQL collation, so "франко" finds "Франко".
     */
    private function authorKey(string $first, string $last): string
    {
        return mb_strtolower($first.'|'.$last);
    }

    /**
     * Merge this chunk's row errors and recount, under a row lock on the import:
     * chunks of the same batch run in parallel and would otherwise overwrite each
     * other's JSON. Counts are recomputed from the data (not incremented), so a
     * re-run cannot double them.
     *
     * @param  array<int, list<string>>  $errors
     */
    private function recordProgress(array $errors): void
    {
        $import = Import::lockForUpdate()->findOrFail($this->import->id);
        $all = array_replace($import->errors ?? [], $errors);

        $import->update([
            'errors' => $all,
            'failed' => count(array_filter(array_keys($all), 'is_int')),
            'imported' => StockMovement::where('import_id', $import->id)->count(),
        ]);
    }
}
