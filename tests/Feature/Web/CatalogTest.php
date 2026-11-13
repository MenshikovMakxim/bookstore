<?php

use App\Enums\BookFormat;
use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Review;
use App\Models\Tag;
use App\Models\Warehouse;

/**
 * Titles of the books shown on a catalog page (from the card links).
 *
 * @return list<string>
 */
function catalogTitles(string $url): array
{
    $html = test()->get($url)->assertOk()->getContent();
    preg_match_all('/<a href="[^"]+\/books\/[^"]+" class="font-medium[^"]*">([^<]+)<\/a>/', $html, $matches);

    return array_map(fn ($title) => html_entity_decode(trim($title), ENT_QUOTES), $matches[1]);
}

test('genre filter includes child genres and secondary genres', function () {
    $root = Genre::factory()->create(['slug' => 'fantasy']);
    $child = Genre::factory()->child($root)->create();
    $other = Genre::factory()->create();

    Book::factory()->for($root)->create(['title' => 'Root Book']);
    Book::factory()->for($child)->create(['title' => 'Child Book']);
    $secondary = Book::factory()->for($other)->create(['title' => 'Secondary Book']);
    $secondary->genres()->attach($root);
    Book::factory()->for($other)->create(['title' => 'Other Book']);

    expect(catalogTitles('/books?genre=fantasy'))
        ->toEqualCanonicalizing(['Root Book', 'Child Book', 'Secondary Book']);
});

test('author filter', function () {
    $author = Author::factory()->create(['slug' => 'jane-doe']);
    $book = Book::factory()->create(['title' => 'By Jane']);
    $book->authors()->attach($author, ['role' => 'author']);
    Book::factory()->create(['title' => 'Not By Jane']);

    expect(catalogTitles('/books?author=jane-doe'))->toBe(['By Jane']);
});

test('tag filter shows only books with that tag', function () {
    $tag = Tag::factory()->create(['name' => 'Classics', 'slug' => 'classics']);
    $tagged = Book::factory()->create(['title' => 'Tagged']);
    $tagged->tags()->attach($tag);
    Book::factory()->create(['title' => 'Untagged']);

    expect(catalogTitles('/books?tag=classics'))->toBe(['Tagged']);

    $this->get('/books?tag=classics')
        ->assertOk()
        ->assertSee('data-filter="tag"', false)
        ->assertSee('#Classics');
});

test('tag page lists only books with that tag', function () {
    $tag = Tag::factory()->create(['name' => 'Classics', 'slug' => 'classics']);
    $tagged = Book::factory()->create(['title' => 'Tagged']);
    $tagged->tags()->attach($tag);
    Book::factory()->create(['title' => 'Untagged']);

    expect(catalogTitles('/tags/classics'))->toBe(['Tagged']);
});

test('q searches titles and ISBN prefixes, blank search is ignored', function () {
    Book::factory()->create(['title' => 'Кобзар', 'isbn' => '9786177489124']);
    Book::factory()->create(['title' => 'Інша книга']);

    expect(catalogTitles('/books?q=Кобзар'))->toBe(['Кобзар'])
        ->and(catalogTitles('/books?q=978-617'))->toBe(['Кобзар'])
        ->and(catalogTitles('/books?q=%20%20'))->toHaveCount(2);

    $this->get('/books?q=Кобзар')
        ->assertOk()
        ->assertSee('data-filter="q"', false)
        ->assertSee(__('ui.catalog.search_chip', ['term' => 'Кобзар']));
});

test('in_stock filter requires quantity above reserved in some warehouse', function () {
    $warehouse = Warehouse::factory()->create();
    $available = Book::factory()->create(['title' => 'Available']);
    $reserved = Book::factory()->create(['title' => 'Fully Reserved']);
    Book::factory()->create(['title' => 'Not Stocked']);

    $available->warehouses()->attach($warehouse, ['quantity' => 3, 'reserved' => 1]);
    $reserved->warehouses()->attach($warehouse, ['quantity' => 2, 'reserved' => 2]);

    expect(catalogTitles('/books?in_stock=1'))->toBe(['Available'])
        ->and(catalogTitles('/books'))->toHaveCount(3);
});

test('format filter, invalid values are ignored', function () {
    Book::factory()->format(BookFormat::Ebook)->create(['title' => 'Digital']);
    Book::factory()->format(BookFormat::Hardcover)->create(['title' => 'Printed']);

    expect(catalogTitles('/books?format=ebook'))->toBe(['Digital'])
        ->and(catalogTitles('/books?format=vinyl'))->toHaveCount(2)
        ->and(catalogTitles('/books?genre=nope&sort=bogus'))->toHaveCount(2);
});

test('sorting by price and rating (books without reviews last)', function () {
    $cheap = Book::factory()->create(['title' => 'Cheap', 'price' => 500]);
    $pricey = Book::factory()->create(['title' => 'Pricey', 'price' => 5000]);
    $unrated = Book::factory()->create(['title' => 'Unrated', 'price' => 1000]);

    Review::factory()->approved()->for($cheap)->create(['rating' => 5]);
    Review::factory()->approved()->for($pricey)->create(['rating' => 2]);
    Review::factory()->for($unrated)->create(['rating' => 5]); // not approved => no rating
    // Factories fire no review events: fill books.rating_avg as the seeder does.
    $this->artisan('books:recalculate-ratings', ['--sync' => true])->assertSuccessful();

    expect(catalogTitles('/books?sort=price_asc'))->toBe(['Cheap', 'Unrated', 'Pricey'])
        ->and(catalogTitles('/books?sort=price_desc'))->toBe(['Pricey', 'Unrated', 'Cheap'])
        ->and(catalogTitles('/books?sort=rating'))->toBe(['Cheap', 'Pricey', 'Unrated']);
});

test('pagination keeps the query string', function () {
    Book::factory()->count(30)->format(BookFormat::Ebook)->create();

    $this->get('/books?format=ebook&sort=price_asc')
        ->assertOk()
        ->assertSee('format=ebook&amp;sort=price_asc&amp;page=2', false);

    expect(catalogTitles('/books?format=ebook&sort=price_asc&page=2'))->toHaveCount(6);
});

test('active filters are shown as removable chips', function () {
    $genre = Genre::factory()->create(['slug' => 'horror', 'name' => 'Horror']);
    Book::factory()->for($genre)->format(BookFormat::Paperback)->create();

    $this->get('/books?genre=horror&format=paperback')
        ->assertSee('data-filter="genre"', false)
        ->assertSee('data-filter="format"', false)
        // The genre chip links to the catalog without the genre parameter.
        ->assertSee(route('books.index', ['format' => 'paperback']), false);
});

test('CatalogFilterRequest: invalid filters are dropped, valid ones still apply, no redirect or 422', function () {
    Book::factory()->format(BookFormat::Ebook)->create(['title' => 'Digital']);
    Book::factory()->format(BookFormat::Hardcover)->create(['title' => 'Printed']);

    // format is valid, the rest is garbage: only the format filter applies.
    expect(catalogTitles('/books?format=ebook&genre=nope&author=nobody&tag=nope&in_stock=maybe&sort=bogus'))->toBe(['Digital'])
        ->and(catalogTitles('/books?format[]=ebook&sort[]=x'))->toHaveCount(2);

    $this->get('/books?genre=nope')->assertOk()->assertSessionHasNoErrors();
});

test('CatalogFilterRequest: Rule::exists() constraint drops an author without books', function () {
    Author::factory()->create(['slug' => 'no-books']);
    Book::factory()->count(2)->create();

    expect(catalogTitles('/books?author=no-books'))->toHaveCount(2);
    $this->get('/books?author=no-books')->assertDontSee('data-filter="author"', false);
});
