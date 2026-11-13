<?php

use App\Lab\CollectionExamples;
use App\Lab\QueryProfiler;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Profile;
use App\Models\Review;
use App\Models\Scopes\ApprovedScope;
use App\Models\Warehouse;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\DB;

/**
 * The lab's per-model approaches are deliberate N+1s over fixed samples (20 / 200
 * books), so seed above those caps; $n then varies only data the lab aggregates.
 */
function seedCollectionsLab(int $n): void
{
    $genre = Genre::factory()->create();
    $books = Book::factory()->count(200 + $n)->published()->for($genre)->create();
    $warehouse = Warehouse::factory()->create();
    $books->take($n)->each(fn (Book $book) => $warehouse->books()->attach($book, ['quantity' => 5]));
    Review::factory()->count($n)->for($books->first())->create(); // unapproved, < one chunk of 100
    Profile::factory()->count(3)->create();
}

test('every lab section renders', function (string $section) {
    config(['database.seed_scale' => 'small']);
    $this->seed(DatabaseSeeder::class);

    $this->get(route('lab.collections', ['section' => $section]))
        ->assertOk()
        ->assertSee(CollectionExamples::sections()[$section])
        ->assertDontSee(__('lab.common.error').'</strong>', false);
})->with(CollectionExamples::SECTIONS);

test('unknown section is a 404', function () {
    $this->get(route('lab.collections', ['section' => 'nope']))->assertNotFound();
});

test('lab sections run a constant number of queries', function (string $section) {
    expectConstantQueries(
        fn (int $n) => seedCollectionsLab($n),
        fn () => $this->get(route('lab.collections', ['section' => $section])),
    );
})->with(CollectionExamples::SECTIONS);

test('chunk() skips rows when the callback changes the filtered column; chunkById() does not', function () {
    config(['database.seed_scale' => 'small']);
    $this->seed(DatabaseSeeder::class);
    $pending = fn () => Review::withoutGlobalScope(ApprovedScope::class)->where('is_approved', false)->count();
    $unapproved = $pending();

    $examples = new CollectionExamples;
    [$chunk, $chunkById] = $examples->run($examples->comparisons('chunk-pitfall')[0]);

    expect($unapproved)->toBeGreaterThan(200)
        ->and($chunk['profile']->result['unapproved_before'])->toBe($unapproved)
        ->and($chunk['profile']->result['unapproved_after'])->toBeGreaterThan(0)
        ->and($chunkById['profile']->result['unapproved_after'])->toBe(0)
        // Both ran inside rolled-back transactions.
        ->and($pending())->toBe($unapproved);
});

test('lazyById(1000) eager loads the genre per chunk; cursor() cannot and runs N+1', function () {
    $genre = Genre::factory()->create();
    Book::factory()->count(10)->for($genre)->create();

    $lazy = QueryProfiler::run(function () {
        $names = [];
        Book::with('genre')->lazyById(1000)->each(function (Book $book) use (&$names) {
            $names[] = $book->genre->name;
        });

        return count($names);
    });

    // No LazyLoading::allowDuring(): cursor() hydrates one model at a time, so strict
    // mode does not flag the N+1 that happens here.
    $cursor = QueryProfiler::run(function () {
        $names = [];
        foreach (Book::cursor() as $book) {
            $names[] = $book->genre->name;
        }

        return count($names);
    });

    expect($lazy->result)->toBe(10)
        ->and($lazy->queries)->toBe(2) // one book query + one eager load for the genre
        ->and($cursor->result)->toBe(10)
        ->and($cursor->error)->toBeNull()
        ->and($cursor->queries)->toBe(11); // one book query + one genre query per book
});

test('lazy()->each() skips rows too when the callback changes the filtered column', function () {
    Review::factory()->count(5)->create(['is_approved' => false]);
    $pending = fn () => Review::withoutGlobalScope(ApprovedScope::class)->where('is_approved', false)->count();

    DB::beginTransaction();
    try {
        $processed = 0;
        Review::withoutGlobalScope(ApprovedScope::class)->where('is_approved', false)
            ->lazy(2)->each(function (Review $review) use (&$processed) {
                $review->update(['is_approved' => true]);
                $processed++;
            });

        expect($processed)->toBe(3)->and($pending())->toBe(2);
    } finally {
        DB::rollBack();
    }

    DB::beginTransaction();
    try {
        $processed = 0;
        Review::withoutGlobalScope(ApprovedScope::class)->where('is_approved', false)
            ->lazyById(2)->each(function (Review $review) use (&$processed) {
                $review->update(['is_approved' => true]);
                $processed++;
            });

        expect($processed)->toBe(5)->and($pending())->toBe(0);
    } finally {
        DB::rollBack();
    }
});

test('toQuery()->update() only touches the models in the collection', function () {
    $books = Book::factory()->count(5)->create(['published_year' => 1990]);

    $subset = Book::whereIn('id', $books->take(2)->modelKeys())->get();
    $affected = $subset->toQuery()->update(['published_year' => 2000]);

    expect($affected)->toBe(2)
        ->and(Book::where('published_year', 2000)->pluck('id')->sort()->values()->all())
        ->toBe($subset->modelKeys())
        ->and(Book::where('published_year', 1990)->count())->toBe(3);
});

test('mass update comparison: Eloquent updates updated_at, DB::table() does not', function () {
    Book::factory()->count(10)->create(['updated_at' => now()->subDay()]);

    $examples = new CollectionExamples;
    $results = collect($examples->run($examples->comparisons('mass-update')[0]))
        ->mapWithKeys(fn ($run) => [$run['approach']->label => $run['profile']->result]);

    expect($results['$books->toQuery()->increment()'])->toBe(['rows' => 10, 'updated_at_changed' => 10])
        ->and($results['DB::table()->increment()'])->toBe(['rows' => 10, 'updated_at_changed' => 0])
        ->and($results['each->save()'])->toBe(['rows' => 10, 'updated_at_changed' => 10]);
});

test('comparing by key vs by value', function () {
    Book::factory()->count(20)->create();

    $examples = new CollectionExamples;
    [$eloquent, $base] = $examples->run($examples->comparisons('eloquent-collection')[4]);

    expect($eloquent['profile']->result)->toMatchArray(['unique' => 15, 'contains' => true])
        ->and($base['profile']->result)->toMatchArray(['unique' => 16])
        ->and($base['profile']->result['diff'])->toContain(Book::orderBy('id')->skip(5)->value('id'));
});
