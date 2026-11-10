<?php

use App\Enums\AuthorRole;
use App\Enums\ImageType;
use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Import;
use App\Models\Profile;
use App\Models\Review;
use App\Models\Tag;
use App\Models\User;
use App\Models\Warehouse;
use App\Notifications\LowStockNotification;

/**
 * $count catalog books, each with 2 authors, 2 approved reviews, a cover,
 * stock in a warehouse, a tag and a secondary genre.
 */
function seedCatalogBooks(int $count): void
{
    $warehouse = Warehouse::factory()->create();
    $tag = Tag::factory()->create();
    $secondary = Genre::factory()->create();

    Book::factory()->count($count)->published()->withAuthors(2)->create()->each(function (Book $book) use ($warehouse, $tag, $secondary) {
        Review::factory()->count(2)->approved()->for($book)->create();
        $book->images()->create(['path' => "covers/{$book->slug}.jpg", 'type' => ImageType::Cover]);
        $book->warehouses()->attach($warehouse, ['quantity' => 10, 'reserved' => 1]);
        $book->tags()->attach($tag);
        $book->genres()->attach($secondary);
    });
}

test('home page', function () {
    expectConstantQueries(
        fn (int $n) => seedCatalogBooks($n),
        fn () => $this->get(route('home')),
    );
});

test('catalog (books.index)', function () {
    expectConstantQueries(
        fn (int $n) => seedCatalogBooks($n),
        fn () => $this->get(route('books.index')),
    );
});

test('catalog with filters and rating sort', function () {
    expectConstantQueries(
        function (int $n) {
            seedCatalogBooks($n);

            return Genre::whereNull('parent_id')->value('slug');
        },
        fn (string $genre) => $this->get(route('books.index', ['genre' => $genre, 'in_stock' => 1, 'sort' => 'rating'])),
    );
});

test('tag page (tags.show)', function () {
    expectConstantQueries(
        function (int $n) {
            seedCatalogBooks($n);

            return Tag::has('books')->firstOrFail();
        },
        fn (Tag $tag) => $this->get(route('tags.show', $tag)),
    );
});

test('book page (books.show) with many authors, reviews, tags, genres and warehouses', function () {
    expectConstantQueries(
        function (int $n) {
            $book = Book::factory()->withAuthors($n)->inWarehouses($n)->create();
            $book->tags()->attach(Tag::factory()->count($n)->create());
            $book->genres()->attach(Genre::factory()->count($n)->child()->create());
            $book->images()->create(['path' => 'covers/x.jpg', 'type' => ImageType::Cover]);

            User::factory()->count($n)->has(Profile::factory())->create()
                ->each(fn (User $user) => Review::factory()->approved()->for($book)->for($user)->create());

            return $book;
        },
        fn (Book $book) => $this->get(route('books.show', $book)),
    );
});

test('book page with the review form (reader without a review) and with a pending own review', function () {
    $seed = function (int $n) {
        $book = Book::factory()->withAuthors($n)->create();
        User::factory()->count($n)->create()
            ->each(fn (User $user) => Review::factory()->approved()->for($book)->for($user)->create());

        return $book;
    };

    asReader();
    expectConstantQueries($seed, fn (Book $book) => $this->get(route('books.show', $book))->assertSee('id="review-form"', false));

    $author = asReader();
    expectConstantQueries(function (int $n) use ($seed, $author) {
        $book = $seed($n);
        Review::factory()->for($book)->for($author)->create(); // pending

        return $book;
    }, fn (Book $book) => $this->get(route('books.show', $book))->assertSee(__('ui.reviews.status_pending')));
});

test('author page (authors.show) with books in every role', function () {
    expectConstantQueries(
        function (int $n) {
            $author = Author::factory()->create();
            $author->images()->create(['path' => 'authors/x.jpg', 'type' => ImageType::Photo]);

            Book::factory()->count($n)->create()->each(fn (Book $book, int $i) => $author->books()->attach($book, [
                'role' => AuthorRole::cases()[$i % 3]->value,
                'sort_order' => 0,
            ]));

            return $author;
        },
        fn (Author $author) => $this->get(route('authors.show', $author)),
    );
});

test('manage: warehouse stock list and movements (as the warehouse manager)', function () {
    $seed = function (int $n) {
        $warehouse = Warehouse::factory()->create();
        $this->actingAs(User::factory()->manager()->create(['warehouse_id' => $warehouse->id]));

        // attach() fires StockObserver: one movement per book as well.
        Book::factory()->count($n)->create()
            ->each(fn (Book $book) => $warehouse->books()->attach($book, ['quantity' => 3, 'shelf_code' => 'A-01-1']));

        return $warehouse;
    };

    expectConstantQueries($seed, fn (Warehouse $warehouse) => $this->get(route('manage.warehouses.stocks.index', $warehouse)));
    expectConstantQueries($seed, fn (Warehouse $warehouse) => $this->get(route('manage.warehouses.movements.index', $warehouse)));
});

test('admin: book list (with trashed) and pending reviews', function () {
    asAdmin();

    expectConstantQueries(
        fn (int $n) => Book::factory()->count($n)->create()->each(fn (Book $book, int $i) => $i % 3 === 0 ? $book->delete() : null),
        fn () => $this->get(route('admin.books.index', ['trashed' => 'with'])),
    );

    expectConstantQueries(
        fn (int $n) => Review::factory()->count($n)->create(), // pending by default
        fn () => $this->get(route('admin.reviews.index')),
    );
});

test('staff notifications page (and the bell in the layout)', function () {
    $manager = asManager();

    expectConstantQueries(
        fn (int $n) => collect(range(1, $n))->each(fn (int $i) => $manager->notify(
            new LowStockNotification(1, "Склад {$i}", [], $i, 5),
        )),
        fn () => $this->get(route('notifications.index')),
    );
});

test('admin imports list', function () {
    $admin = asAdmin();

    expectConstantQueries(
        fn (int $n) => collect(range(1, $n))->each(fn () => Import::create([
            'user_id' => $admin->id, 'warehouse_id' => Warehouse::factory()->create()->id, 'original_name' => 'a.csv',
            'path' => 'a.csv', 'status' => 'completed', 'total_rows' => 1,
        ])),
        fn () => $this->get(route('admin.imports.index')),
    );
});

test('genre tree view composer (catalog sidebar) and genres.index', function () {
    $seed = fn (int $n) => Genre::factory()->count($n)->create()
        ->each(fn (Genre $root) => Genre::factory()->count(2)->child($root)->create());

    expectConstantQueries($seed, fn () => $this->get(route('books.index')));
    expectConstantQueries($seed, fn () => $this->get(route('genres.index')));
});

test('genres page with the first three books of every genre', function () {
    expectConstantQueries(
        fn (int $n) => Genre::factory()->count($n)->create()->each(function (Genre $root) {
            Book::factory()->count(4)->for($root)->create();
            Genre::factory()->count(2)->child($root)->create()
                ->each(fn (Genre $child) => Book::factory()->count(4)->for($child)->create());
        }),
        fn () => $this->get(route('genres.index')),
    );
});

test('admin: book page (admin.books.show)', function () {
    asAdmin();

    expectConstantQueries(
        function (int $n) {
            $book = Book::factory()->withAuthors($n)->create();
            User::factory()->count($n)->create()
                ->each(fn (User $user) => Review::factory()->approved()->for($book)->for($user)->create());
            Review::factory()->count($n)->for($book)->create(); // pending: counted without ApprovedScope

            return $book;
        },
        fn (Book $book) => $this->get(route('admin.books.show', $book)),
    );
});

test('index pages (authors, warehouses)', function () {
    expectConstantQueries(
        fn (int $n) => Author::factory()->count($n)->withBooks(2)->create(),
        fn () => $this->get(route('authors.index')),
    );

    asAdmin(); // the admin sees every warehouse

    expectConstantQueries(
        fn (int $n) => Warehouse::factory()->count($n)->create()
            ->each(fn (Warehouse $w) => User::factory()->manager()->create(['warehouse_id' => $w->id])),
        fn () => $this->get(route('manage.warehouses.index')),
    );
});
