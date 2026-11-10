<?php

use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Import;
use App\Models\Tag;
use App\Models\Warehouse;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->genre = Genre::factory()->create();
    Genre::factory()->child($this->genre)->create();
    $this->book = Book::factory()->for($this->genre)->withAuthors(2)->inWarehouses(2)->withReviews(2)->create();
    $this->author = $this->book->authors()->first();
    $this->warehouse = Warehouse::first();
    $this->tag = Tag::factory()->create();
});

/**
 * Named GET routes defined by the app (vendor routes such as debugbar.* excluded).
 *
 * @return list<string>
 */
function appRouteNames(): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route) => $route->getName() !== null
            && in_array('GET', $route->methods())
            && ! str_starts_with($route->getName(), 'debugbar.')
            && ! str_starts_with($route->getName(), 'storage.'))
        ->map->getName()
        ->sort()->values()->all();
}

test('every named GET route resolves and returns 200 for an admin', function () {
    $admin = asAdmin();
    $stock = $this->warehouse->stocks()->first();
    $import = Import::create([
        'user_id' => $admin->id, 'warehouse_id' => $this->warehouse->id, 'original_name' => 'books.csv',
        'path' => 'books.csv', 'status' => 'completed', 'total_rows' => 1, 'errors' => [2 => ['Помилка']],
    ]);

    $params = [
        'books.show' => [$this->book],
        'books.reviews.create' => [$this->book], // redirects to the book page's review form
        'authors.show' => [$this->author],
        'authors.books.show' => [$this->author, $this->book],
        'genres.show' => [$this->genre],
        'tags.show' => [$this->tag],
        'admin.books.show' => [$this->book],
        'admin.imports.show' => [$import],
        'admin.imports.progress' => [$import],
        'admin.imports.errors' => [$import],
        'admin.books.edit' => [$this->book],
        'manage.warehouses.stocks.index' => [$this->warehouse],
        'manage.warehouses.movements.index' => [$this->warehouse],
        'manage.warehouses.stocks.edit' => [$this->warehouse, $stock],
    ];
    // Guest-only pages redirect logged-in users; the signed verify link is tested in AuthTest.
    $skip = ['login', 'register', 'verification.notice', 'verification.verify'];

    $tested = [];
    foreach (array_diff(appRouteNames(), $skip) as $name) {
        // genres.show redirects to the filtered catalog; follow it.
        $this->followingRedirects()->get(route($name, $params[$name] ?? []))
            ->assertOk();
        $tested[] = $name;
    }

    expect($tested)->toContain('home', 'books.index', 'books.show', 'authors.index', 'authors.show',
        'authors.books.show', 'genres.index', 'genres.show', 'tags.show', 'about', 'admin.dashboard', 'admin.books.index',
        'admin.books.create', 'admin.books.edit', 'admin.reviews.index', 'manage.warehouses.index',
        'manage.warehouses.stocks.edit', 'reports.stock', 'lab.relations', 'lab.xss', 'books.reviews.create', 'lab.queues', 'admin.imports.index', 'admin.imports.show', 'notifications.index');
});

test('guest-only pages render for guests', function (string $name) {
    $this->get(route($name))->assertOk();
})->with(['login', 'register']);

test('routes bind by slug and by id as declared', function () {
    expect(route('books.show', $this->book))->toEndWith('/books/'.$this->book->slug)
        ->and(route('authors.books.show', [$this->author, $this->book]))
        ->toEndWith("/authors/{$this->author->slug}/books/{$this->book->slug}")
        ->and(route('manage.warehouses.stocks.index', $this->warehouse))->toEndWith("/manage/warehouses/{$this->warehouse->id}/stocks")
        ->and(route('admin.books.show', $this->book))->toEndWith('/admin/books/'.$this->book->id);
});

test('genres.show redirects to the filtered catalog', function () {
    $this->get(route('genres.show', $this->genre))
        ->assertRedirect(route('books.index', ['genre' => $this->genre->slug]));
});

test('genres.index previews the first three books of a genre', function () {
    $genre = Genre::factory()->create(['name' => 'Поезія']);

    foreach (['Книга А', 'Книга Б', 'Книга В', 'Книга Г'] as $title) {
        Book::factory()->for($genre)->create(['title' => $title]);
    }

    $this->get(route('genres.index'))
        ->assertOk()
        ->assertSeeInOrder(['Книга А', 'Книга Б', 'Книга В'])
        ->assertDontSee('Книга Г');
});

test('tags.show renders the catalog filtered by tag', function () {
    $this->get(route('tags.show', $this->tag))
        ->assertOk()
        ->assertSee(__('ui.tags.title', ['tag' => $this->tag->name]));
});

test('unknown slugs return 404', function (string $url) {
    $this->get($url)->assertNotFound();
})->with([
    'book' => '/books/no-such-book',
    'author' => '/authors/no-such-author',
    'genre' => '/genres/no-such-genre',
    'tag' => '/tags/no-such-tag',
]);

test('scoped binding: a book that is not by the author is a 404', function () {
    $otherAuthor = Author::factory()->create();

    $this->get(route('authors.books.show', [$this->author, $this->book]))->assertOk();
    $this->get("/authors/{$otherAuthor->slug}/books/{$this->book->slug}")->assertNotFound();
});

test('soft-deleted book: 404 publicly, 200 in admin (withTrashed)', function () {
    $this->book->delete();
    asAdmin();

    $this->get("/books/{$this->book->slug}")->assertNotFound();
    $this->get("/authors/{$this->author->slug}/books/{$this->book->slug}")->assertNotFound();
    $this->get(route('admin.books.show', $this->book))
        ->assertOk()
        ->assertSee(Str::before(__('ui.admin.soft_deleted'), ':time'));
});

test('fallback route renders the custom 404 page', function () {
    $this->get('/definitely/not/a/page')
        ->assertNotFound()
        ->assertSee(__('ui.errors.not_found.title'))
        ->assertSee(__('ui.errors.not_found.back'));
});
