<?php

use App\Enums\ImportStatus;
use App\Enums\StockMovementReason;
use App\Imports\BookCsv;
use App\Jobs\CheckLowStock;
use App\Jobs\ImportBooksChunk;
use App\Jobs\SendImportReport;
use App\Jobs\ValidateImportFile;
use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Import;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Notifications\ImportFailedNotification;
use App\Notifications\ImportFinishedNotification;
use Illuminate\Bus\PendingBatch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    app()->setLocale('uk');
    Storage::fake('imports');
    Genre::factory()->create(['slug' => 'proza']);
    Genre::factory()->create(['slug' => 'poeziia']);
    $this->warehouse = Warehouse::factory()->create();
    $this->admin = asAdmin();
});

function fixtureImport(string $fixture): Import
{
    Storage::disk('imports')->put($fixture, file_get_contents(base_path("tests/Fixtures/{$fixture}")));

    return Import::create([
        'user_id' => test()->admin->id,
        'warehouse_id' => test()->warehouse->id,
        'original_name' => $fixture,
        'path' => $fixture,
        'status' => ImportStatus::Processing,
        'total_rows' => BookCsv::countRows(Storage::disk('imports')->path($fixture)),
    ]);
}

function uploadFixture(string $fixture): TestResponse
{
    return test()->post(route('admin.imports.store'), [
        'warehouse_id' => test()->warehouse->id,
        'file' => UploadedFile::fake()->createWithContent($fixture, file_get_contents(base_path("tests/Fixtures/{$fixture}"))),
    ]);
}

test('upload: the chain is Validate → batch of chunks → CheckLowStock → report', function () {
    Bus::fake();
    config(['warehouse.import.chunk_size' => 2]);

    uploadFixture('import-mixed.csv')->assertRedirect();

    $import = Import::sole();
    expect($import->total_rows)->toBe(6)->and($import->status)->toBe(ImportStatus::Pending);
    Storage::disk('imports')->assertExists($import->path);

    Bus::assertChained([
        ValidateImportFile::class,
        Bus::chainedBatch(fn (PendingBatch $batch) => $batch->jobs->count() === 3
            && $batch->allowsFailures()
            && $batch->jobs->map(fn (ImportBooksChunk $job) => [$job->offset, $job->limit])->all() === [[0, 2], [2, 2], [4, 2]]),
        CheckLowStock::class,
        SendImportReport::class,
    ]);
});

test('ImportBooksRequest: csv only, warehouse must exist, file must have rows', function (Closure $payload, array $errors) {
    Bus::fake();

    $this->post(route('admin.imports.store'), $payload())->assertSessionHasErrors($errors);
    Bus::assertNothingDispatched();
})->with([
    'not a csv' => [fn () => ['warehouse_id' => test()->warehouse->id, 'file' => UploadedFile::fake()->image('cover.png')], ['file']],
    'unknown warehouse' => [fn () => ['warehouse_id' => 999, 'file' => UploadedFile::fake()->createWithContent('a.csv', "isbn\n1\n")], ['warehouse_id']],
    'header only' => [fn () => ['warehouse_id' => test()->warehouse->id, 'file' => UploadedFile::fake()->createWithContent('a.csv', "isbn,title\n")], ['file']],
]);

test('chunk: valid rows imported (upsert, authors, receipt movement), invalid rows recorded', function () {
    $import = fixtureImport('import-mixed.csv');

    ImportBooksChunk::dispatchSync($import, 0, 500);

    $import->refresh();
    expect($import->imported)->toBe(2)
        ->and($import->failed)->toBe(4)
        ->and(array_keys($import->rowErrors()))->toBe([3, 5, 6, 7])
        ->and($import->errors[3][0])->toContain('контрольною цифрою')
        ->and(Book::count())->toBe(2);

    $book = Book::firstWhere('isbn', '9780306406157');
    expect($book->price->cents)->toBe(9900)
        ->and($book->authors()->pluck('last_name')->sort()->values()->all())->toBe(['Українка', 'Франко']);

    $movement = StockMovement::where('import_id', $import->id)->where('source_row', 4)->sole();
    expect($movement->reason)->toBe(StockMovementReason::Import)
        ->and($movement->delta)->toBe(2)
        ->and($movement->user_id)->toBe($this->admin->id);
});

test('chunk re-run is idempotent: no duplicate books, movements or errors', function () {
    $import = fixtureImport('import-mixed.csv');

    ImportBooksChunk::dispatchSync($import, 0, 500);
    ImportBooksChunk::dispatchSync($import, 0, 500);

    expect(Book::count())->toBe(2)
        ->and(StockMovement::where('import_id', $import->id)->count())->toBe(2)
        ->and($import->fresh()->only(['imported', 'failed']))->toBe(['imported' => 2, 'failed' => 4])
        ->and((int) $this->warehouse->stocks()->sum('quantity'))->toBe(5);
});

test('chunk: a constant number of queries per row (lookups are prefetched per chunk)', function () {
    $import = fixtureImport('import-valid.csv');
    // An author that already exists (written in another case) and a stock row to add to.
    $franko = Author::factory()->create(['first_name' => 'іван', 'last_name' => 'франко']);
    $kobzar = Book::factory()->create(['isbn' => '9789660340008']);
    $kobzar->warehouses()->attach($this->warehouse, ['quantity' => 5, 'reserved' => 0, 'shelf_code' => null]);

    $count = 0;
    DB::listen(function () use (&$count) {
        $count++;
    });
    ImportBooksChunk::dispatchSync($import, 0, 500);

    // 3 rows: per new book ~1 slug check + book + credits + stock + movement INSERT,
    // plus ~10 per chunk (prefetch, transaction, progress). Before: ~10 per row.
    expect($count)->toBeLessThanOrEqual(3 * 6 + 12)
        ->and(Author::where('last_name', 'франко')->pluck('id')->all())->toBe([$franko->id])
        ->and($kobzar->stocks()->where('warehouse_id', $this->warehouse->id)->sole()->only(['quantity', 'shelf_code']))
        ->toBe(['quantity' => 12, 'shelf_code' => 'B-02-1'])
        ->and(StockMovement::where('import_id', $import->id)->where('book_id', $kobzar->id)->value('delta'))->toBe(7);
});

test('a second import upserts by ISBN and adds stock', function () {
    ImportBooksChunk::dispatchSync(fixtureImport('import-valid.csv'), 0, 500);
    ImportBooksChunk::dispatchSync(fixtureImport('import-mixed.csv'), 0, 500);

    $zakhar = Book::firstWhere('isbn', '9786170000019');
    expect(Book::count())->toBe(3)
        ->and($zakhar->stocks()->value('quantity'))->toBe(13)
        ->and(Book::firstWhere('isbn', '9780306406157')->format->value)->toBe('ebook');
});

test('failed() marks the import and tells the uploader', function () {
    Notification::fake();
    $import = fixtureImport('import-valid.csv');

    (new ImportBooksChunk($import, 0, 500))->failed(new RuntimeException('Deadlock found'));

    $import->refresh();
    expect($import->status)->toBe(ImportStatus::Failed)
        ->and($import->errors['file'][0])->toContain('Deadlock found');
    Notification::assertSentTo($this->admin, ImportFailedNotification::class);
});

test('a file without the required columns fails validation and stops the chain', function () {
    Notification::fake();
    Storage::disk('imports')->put('bad.csv', "isbn,title\n9786170000019,Кобзар\n");
    $import = Import::create(['user_id' => $this->admin->id, 'warehouse_id' => $this->warehouse->id, 'original_name' => 'bad.csv',
        'path' => 'bad.csv', 'status' => ImportStatus::Pending, 'total_rows' => 1]);

    ValidateImportFile::dispatchSync($import);

    expect($import->fresh()->status)->toBe(ImportStatus::Failed)
        ->and($import->fresh()->errors['file'][0])->toContain('authors');
    Notification::assertSentTo($this->admin, ImportFailedNotification::class);
});

test('end to end on the sync queue: status, batch id, report, low-stock check', function () {
    Notification::fake();
    config(['warehouse.import.chunk_size' => 2]);

    uploadFixture('import-mixed.csv');

    $import = Import::sole();
    expect($import->status)->toBe(ImportStatus::CompletedWithErrors)
        ->and($import->batch_id)->not->toBeNull()
        ->and($import->finished_at)->not->toBeNull()
        ->and($import->only(['imported', 'failed']))->toBe(['imported' => 2, 'failed' => 4]);

    Notification::assertSentTo($this->admin, ImportFinishedNotification::class,
        fn (ImportFinishedNotification $n) => $n->imported === 2 && $n->failed === 4);

    $this->getJson(route('admin.imports.progress', $import))
        ->assertOk()
        ->assertJson(['finished' => true, 'percentage' => 100, 'batch' => ['total_jobs' => 3, 'failed_jobs' => 0]]);

    $csv = $this->get(route('admin.imports.errors', $import))->assertOk()->streamedContent();
    expect($csv)->toContain('Рядок')->toContain('7,"Поле кількість');

    $this->get(route('admin.imports.show', $import))->assertOk()->assertSee('import-mixed.csv');
});

test('import:make-sample writes a CSV with the requested size and share of errors', function () {
    $this->artisan('import:make-sample', ['--rows' => 50, '--errors' => 100, '--name' => 'sample.csv'])->assertSuccessful();

    $path = Storage::disk('imports')->path('sample.csv');
    expect(BookCsv::countRows($path))->toBe(50)
        ->and((new BookCsv($path))->missingColumns())->toBe([]);
});
