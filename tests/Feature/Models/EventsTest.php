<?php

use App\Enums\StockMovementReason;
use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Tag;
use App\Models\User;
use App\Models\Warehouse;
use Illuminate\Support\Facades\DB;

test('HasSlug: ASCII slug from title/name, unique suffix on collision', function () {
    $genre = Genre::factory()->create();
    $attributes = ['genre_id' => $genre->id, 'price' => 1000, 'format' => 'ebook'];

    $first = Book::create([...$attributes, 'title' => 'Тіні забутих предків', 'isbn' => '9786170000017']);
    $second = Book::create([...$attributes, 'title' => 'Тіні забутих предків', 'isbn' => '9786170000024']);
    $third = Book::create([...$attributes, 'title' => 'Тіні забутих предків', 'isbn' => '9786170000031']);

    expect([$first->slug, $second->slug, $third->slug])
        ->toBe(['tini-zabutykh-predkiv', 'tini-zabutykh-predkiv-2', 'tini-zabutykh-predkiv-3'])
        ->and(Author::create(['first_name' => 'Леся', 'last_name' => 'Українка'])->slug)->toBe('lesia-ukrayinka')
        ->and(Genre::create(['name' => 'Фентезі'])->slug)->toBe('fentezi')
        ->and(Tag::create(['name' => 'Бестселер'])->slug)->toBe('bestseler');
});

test('HasSlug: soft-deleted books still reserve their slug; an explicit slug is kept', function () {
    $genre = Genre::factory()->create();
    $attributes = ['genre_id' => $genre->id, 'price' => 1000, 'format' => 'ebook', 'title' => 'Кобзар'];

    Book::create([...$attributes, 'isbn' => '9786170000017'])->delete();

    expect(Book::create([...$attributes, 'isbn' => '9786170000024'])->slug)->toBe('kobzar-2')
        ->and(Book::create([...$attributes, 'isbn' => '9786170000031', 'slug' => 'my-slug'])->slug)->toBe('my-slug');
});

test('HasSlug: one query however many suffixes are taken; gaps and look-alikes are handled', function () {
    $genre = Genre::factory()->create();
    $attributes = ['genre_id' => $genre->id, 'price' => 1000, 'format' => 'ebook', 'title' => 'Кобзар'];
    foreach (['kobzar', 'kobzar-2', 'kobzar-3', 'kobzar-5', 'kobzar-taras'] as $i => $slug) {
        Book::create([...$attributes, 'isbn' => '978617000'.(1000 + $i), 'slug' => $slug]);
    }

    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        if (str_contains($query->sql, 'slug') && str_starts_with($query->sql, 'select')) {
            $queries[] = $query->sql;
        }
    });

    // The first free one: 4 (5 is taken, "kobzar-taras" is not a suffix).
    expect(Book::create([...$attributes, 'isbn' => '9786170009999'])->slug)->toBe('kobzar-4')
        ->and($queries)->toHaveCount(1);
});

test('HasSlug: the slug does not change when the title changes', function () {
    $book = Book::factory()->create(['slug' => null, 'title' => 'Кобзар']);

    $book->update(['title' => 'Кобзар. Повне видання']);

    expect($book->fresh()->slug)->toBe('kobzar');
});

/**
 * Record which Stock model events fire while $callback runs.
 *
 * @return list<string>
 */
function stockEvents(Closure $callback): array
{
    $fired = [];

    foreach (['created', 'updated', 'deleted'] as $event) {
        Stock::{$event}(function () use (&$fired, $event) {
            $fired[] = $event;
        });
    }

    $callback();

    return $fired;
}

/**
 * @return list<array{delta: int, reason: string}>
 */
function movementsOf(Book $book): array
{
    return StockMovement::where('book_id', $book->id)->orderBy('id')->get()
        ->map(fn (StockMovement $movement) => ['delta' => $movement->delta, 'reason' => $movement->reason->value])
        ->all();
}

test('pivot operations on the Stock pivot (->using(Stock::class)) fire model events', function () {
    $warehouse = Warehouse::factory()->create();
    $other = Warehouse::factory()->create();
    $book = Book::factory()->create();

    expect(stockEvents(fn () => $book->warehouses()->attach($warehouse, ['quantity' => 5])))->toBe(['created'])
        ->and(stockEvents(fn () => $book->warehouses()->updateExistingPivot($warehouse->id, ['quantity' => 8])))->toBe(['updated'])
        // sync: update for the existing row, create for the new one.
        ->and(stockEvents(fn () => $book->warehouses()->sync([$warehouse->id => ['quantity' => 6], $other->id => ['quantity' => 2]])))->toBe(['updated', 'created'])
        ->and(stockEvents(fn () => $book->warehouses()->detach($other)))->toBe(['deleted'])
        // Unchanged attributes: nothing is dirty, nothing is saved.
        ->and(stockEvents(fn () => $book->warehouses()->updateExistingPivot($warehouse->id, ['quantity' => 6])))->toBe([]);

    expect(movementsOf($book))->toBe([
        ['delta' => 5, 'reason' => 'receipt'],
        ['delta' => 3, 'reason' => 'adjustment'],
        ['delta' => -2, 'reason' => 'adjustment'],
        ['delta' => 2, 'reason' => 'receipt'],
        // detach: "deleted" fires, but the observer does not record removals.
    ]);
});

test('direct Stock save and increment fire updated; reason and user are recorded', function () {
    $warehouse = Warehouse::factory()->create();
    $book = Book::factory()->create();
    $book->warehouses()->attach($warehouse, ['quantity' => 10]);
    $stock = Stock::where('book_id', $book->id)->first();

    $this->actingAs($manager = User::factory()->manager()->create());

    $stock->movementReason = StockMovementReason::Sale;
    $stock->quantity -= 3;
    expect(stockEvents(fn () => $stock->save()))->toBe(['updated']);

    expect(stockEvents(fn () => $stock->increment('quantity', 4)))->toBe(['updated']);

    // Changing another column: updated fires, but there is no quantity delta.
    expect(stockEvents(fn () => $stock->update(['shelf_code' => 'Z-99-9'])))->toBe(['updated']);

    $movements = StockMovement::where('book_id', $book->id)->orderBy('id')->get();

    expect(movementsOf($book))->toBe([
        ['delta' => 10, 'reason' => 'receipt'],
        ['delta' => -3, 'reason' => 'sale'],
        ['delta' => 4, 'reason' => 'adjustment'], // the reason was reset after the sale
    ])->and($movements->last()->user_id)->toBe($manager->id)
        ->and($movements->first()->user_id)->toBeNull(); // attached before actingAs
});

test('a zero-quantity attach records no movement', function () {
    $book = Book::factory()->create();
    $book->warehouses()->attach(Warehouse::factory()->create());

    expect(movementsOf($book))->toBe([]);
});

test('query updates bypass model events: no movement', function () {
    $warehouse = Warehouse::factory()->create();
    $book = Book::factory()->create();
    $book->warehouses()->attach($warehouse, ['quantity' => 10]);
    $stock = Stock::where('book_id', $book->id)->first();

    $events = stockEvents(function () use ($stock) {
        Stock::whereKey($stock->id)->update(['quantity' => 20]);
        Stock::whereKey($stock->id)->get()->toQuery()->update(['quantity' => 30]);
        DB::table('stocks')->where('id', $stock->id)->increment('quantity');
    });

    expect($events)->toBe([])
        ->and($stock->fresh()->quantity)->toBe(31)
        ->and(movementsOf($book))->toHaveCount(1); // only the initial receipt
});

test('saveQuietly and withoutEvents skip the observer', function () {
    $warehouse = Warehouse::factory()->create();
    $book = Book::factory()->create();
    $book->warehouses()->attach($warehouse, ['quantity' => 10]);
    $stock = Stock::where('book_id', $book->id)->first();

    $stock->quantity = 11;
    $stock->saveQuietly();
    Stock::withoutEvents(fn () => $stock->increment('quantity'));

    expect($stock->fresh()->quantity)->toBe(12)
        ->and(movementsOf($book))->toHaveCount(1);
});
