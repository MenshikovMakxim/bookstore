<?php

use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Review;
use App\Models\Stock;
use App\Models\Warehouse;
use App\Reports\StockByWarehouseGenreReport;
use App\Reports\TopAuthorsReport;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;

function seedSmall(): void
{
    config(['database.seed_scale' => 'small']);
    test()->seed(DatabaseSeeder::class);
}

// Reports are behind auth + can:view-reports.
beforeEach(fn () => asAdmin());

test('stock report equals the same totals computed with collections', function () {
    seedSmall();
    Book::orderBy('id')->first()->delete(); // soft-deleted books are excluded by both

    $report = new StockByWarehouseGenreReport;
    $rows = $report->rows()->keyBy(fn ($row) => $row->warehouse_id.'-'.$row->genre_id);

    $genres = Genre::all()->keyBy('id');
    $expected = Stock::with('book')->get()
        ->filter(fn (Stock $stock) => $stock->book !== null)
        ->groupBy(fn (Stock $stock) => $stock->warehouse_id.'-'.($genres[$stock->book->genre_id]->parent_id ?? $stock->book->genre_id))
        ->map(fn ($stocks) => [
            'quantity' => $stocks->sum('quantity'),
            'reserved' => $stocks->sum('reserved'),
            'books' => $stocks->pluck('book_id')->unique()->count(),
            'value_cents' => $stocks->sum(fn (Stock $stock) => $stock->book->price->multiply($stock->quantity)->cents),
        ]);

    expect($rows->keys()->sort()->values()->all())->toBe($expected->keys()->sort()->values()->all());

    foreach ($expected as $key => $totals) {
        expect((array) $rows[$key])->toMatchArray($totals);
    }

    expect($report->totals($report->rows()))->toBe([
        'quantity' => $expected->sum('quantity'),
        'reserved' => $expected->sum('reserved'),
        'value_cents' => $expected->sum('value_cents'),
    ]);
});

test('stock report rolls child genres up to their root genre', function () {
    $warehouse = Warehouse::factory()->create();
    $root = Genre::factory()->create(['name' => 'Fantasy']);
    $child = Genre::factory()->child($root)->create(['name' => 'Epic Fantasy']);
    $other = Genre::factory()->create(['name' => 'History']);

    $rootBook = Book::factory()->for($root)->create(['price' => 1000]);
    $childBook = Book::factory()->for($child)->create(['price' => 500]);
    $otherBook = Book::factory()->for($other)->create(['price' => 200]);

    $warehouse->books()->attach($rootBook, ['quantity' => 2, 'reserved' => 1]);
    $warehouse->books()->attach($childBook, ['quantity' => 3, 'reserved' => 0]);
    $warehouse->books()->attach($otherBook, ['quantity' => 7, 'reserved' => 2]);

    $rows = (new StockByWarehouseGenreReport)->rows();

    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('genre')->all())->toBe(['Fantasy', 'History'])
        ->and((array) $rows->firstWhere('genre', 'Fantasy'))->toMatchArray([
            'genre_id' => $root->id,
            'quantity' => 5,
            'reserved' => 1,
            'books' => 2,
            'value_cents' => 2 * 1000 + 3 * 500,
        ])
        ->and((new StockByWarehouseGenreReport($warehouse->id + 1))->rows())->toBeEmpty();
});

test('top authors report equals collection-based statistics', function () {
    seedSmall();

    $reviewsByBook = Review::where('is_approved', true)->get()->groupBy('book_id');
    $stats = DB::table('author_book')->whereIn('role', ['author', 'co_author'])->get()
        ->unique(fn ($credit) => $credit->author_id.'-'.$credit->book_id)
        ->groupBy('author_id')
        ->map(fn ($credits) => $credits->flatMap(fn ($credit) => $reviewsByBook[$credit->book_id] ?? collect()))
        ->map(fn ($reviews) => ['count' => $reviews->count(), 'avg' => round($reviews->avg('rating.value'), 2)])
        ->filter(fn ($stat) => $stat['count'] >= 10);

    $rows = (new TopAuthorsReport)->rows();

    expect($rows)->toHaveCount(min(20, $stats->count()))
        ->and($rows->pluck('avg_rating')->all())
        ->toBe($stats->pluck('avg')->sortDesc()->take($rows->count())->values()->all());

    foreach ($rows as $row) {
        expect($stats[$row->id])->toBe(['count' => $row->reviews_count, 'avg' => $row->avg_rating]);
    }
});

test('top authors report ignores translations and unapproved reviews, uses the threshold', function () {
    $book = Book::factory()->withAuthors(1)->create();
    $author = $book->authors()->first();
    $translator = Author::factory()->create();
    $book->authors()->attach($translator, ['role' => 'translator', 'sort_order' => 1]);

    Review::factory()->count(3)->approved()->for($book)->create(['rating' => 4]);
    Review::factory()->count(2)->for($book)->create(['rating' => 1]); // not approved

    $rows = (new TopAuthorsReport(minReviews: 3))->rows();

    expect($rows->pluck('id')->all())->toBe([$author->id])
        ->and($rows->first()->avg_rating)->toBe(4.0)
        ->and($rows->first()->reviews_count)->toBe(3)
        ->and((new TopAuthorsReport(minReviews: 4))->rows())->toBeEmpty();
});

test('stock CSV streams a header and one line per stock row of non-deleted books', function () {
    seedSmall();
    $deleted = Book::orderBy('id')->first();
    $deleted->delete();

    $response = $this->get(route('reports.stock-csv'))->assertOk();
    $lines = array_values(array_filter(explode("\n", $response->streamedContent())));

    expect($response->headers->get('content-disposition'))->toContain('attachment')->toContain('.csv')
        ->and($lines[0])->toBe('warehouse,shelf,isbn,title,quantity,reserved,price_cents,value_cents')
        ->and(count($lines) - 1)->toBe(Stock::whereNot('book_id', $deleted->id)->count());
});

test('stock report filters by a warehouse bound to the route', function () {
    $first = Warehouse::factory()->create(['name' => 'Склад А']);
    $second = Warehouse::factory()->create(['name' => 'Склад Б']);
    $bookA = Book::factory()->create(['price' => 1000]);
    $bookB = Book::factory()->create(['price' => 2000]);

    $first->books()->attach($bookA, ['quantity' => 2, 'reserved' => 1]);
    $first->books()->attach($bookB, ['quantity' => 3, 'reserved' => 0]);
    $second->books()->attach($bookA, ['quantity' => 7, 'reserved' => 2]);

    $this->get(route('reports.stock', ['warehouse' => $first]))
        ->assertOk()
        ->assertSee('Склад А')
        ->assertDontSee('Склад Б')
        ->assertSee(route('reports.stock', $first->id), false)
        ->assertSee(route('reports.stock-csv', $first->id), false);

    $this->get(route('reports.stock', ['warehouse' => $second->id]))
        ->assertOk()
        ->assertSee('Склад Б')
        ->assertDontSee('Склад А');
});

test('unknown warehouse is a 404 and CSV honours the filter', function () {
    seedSmall();
    $warehouse = Warehouse::factory()->create();
    $book = Book::factory()->create();
    $warehouse->books()->attach($book, ['quantity' => 4]);

    $this->get(route('reports.stock', ['warehouse' => $warehouse->id + 1000]))->assertNotFound();
    $this->get(route('reports.stock-csv', ['warehouse' => $warehouse->id + 1000]))->assertNotFound();

    $response = $this->get(route('reports.stock-csv', ['warehouse' => $warehouse]))->assertOk();
    $lines = array_values(array_filter(explode("\n", $response->streamedContent())));

    expect(count($lines) - 1)->toBe(Stock::where('warehouse_id', $warehouse->id)->count())
        ->and($lines[1])->toContain((string) $book->isbn)
        ->and($response->headers->get('content-disposition'))->toContain('stock-sklad');
});

test('CSV neutralizes formula-like titles', function () {
    $book = Book::factory()->inWarehouses(1)->create(['title' => '=HYPERLINK("http://evil")']);

    $content = $this->get(route('reports.stock-csv'))->streamedContent();

    expect($content)->toContain("'=HYPERLINK")->not->toContain(',"=HYPERLINK');
});

test('only admins can export the stock CSV; managers still see the HTML report', function () {
    asManager();

    $this->get(route('reports.stock'))->assertOk()->assertDontSee(route('reports.stock-csv'), false);
    $this->get(route('reports.stock-csv'))->assertForbidden();

    asAdmin();

    $this->get(route('reports.stock'))->assertOk()->assertSee(route('reports.stock-csv'), false);
    $this->get(route('reports.stock-csv'))->assertOk();
});

test('report pages render', function (string $route) {
    seedSmall();

    $this->get(route($route))->assertOk();
})->with(['reports.stock', 'reports.top-authors']);

test('report pages run a constant number of queries', function () {
    expectConstantQueries(
        fn (int $n) => Book::factory()->count($n)->inWarehouses(1)->withAuthors(1)->create()
            ->each(fn (Book $book) => Review::factory()->count(10)->approved()->for($book)->create()),
        fn () => $this->get(route('reports.stock')),
    );

    expectConstantQueries(
        fn (int $n) => Book::factory()->count($n)->withAuthors(1)->create()
            ->each(fn (Book $book) => Review::factory()->count(10)->approved()->for($book)->create()),
        fn () => $this->get(route('reports.top-authors')),
    );

    expectConstantQueries(
        fn (int $n) => Book::factory()->count($n)->inWarehouses(2)->create(),
        fn () => $this->get(route('reports.stock-csv')),
    );
});
