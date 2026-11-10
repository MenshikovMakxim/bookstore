<?php

use App\Enums\AuthorRole;
use App\Enums\BookFormat;
use App\Enums\ImageType;
use App\Enums\StockMovementReason;
use App\Enums\UserRole;
use App\Models\Book;
use App\Models\Tag;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Arr;

beforeEach(function () {
    app()->setLocale('uk');
});

test('the app is configured for Ukrainian with English fallback', function () {
    expect(config('app.locale'))->toBe('uk')
        ->and(config('app.fallback_locale'))->toBe('en')
        ->and(config('app.faker_locale'))->toBe('uk_UA');
});

test('pages render in Ukrainian without untranslated keys', function () {
    config(['database.seed_scale' => 'small']);
    $this->seed(DatabaseSeeder::class);

    $book = Book::has('authors')->orderBy('id')->first();
    $author = $book->authors()->first();
    $tag = Tag::has('books')->orderBy('id')->first();

    $guestUrls = [route('home'), route('login'), route('register'), route('books.show', $book), '/no-such-page'];

    $warehouse = Warehouse::has('stocks')->orderBy('id')->first();
    $adminUrls = [
        route('home'), route('books.index'), route('books.index', ['in_stock' => 1, 'format' => 'ebook', 'sort' => 'rating']),
        route('books.show', $book), route('tags.show', $tag), route('authors.index'), route('authors.show', $author),
        route('authors.books.show', [$author, $book]), route('genres.index'), route('about'),
        route('manage.warehouses.index'), route('manage.warehouses.stocks.index', $warehouse),
        route('manage.warehouses.stocks.edit', [$warehouse, $warehouse->stocks()->first()]),
        route('manage.warehouses.movements.index', $warehouse),
        route('admin.dashboard'), route('admin.books.index'), route('admin.books.create'), route('admin.books.show', $book),
        route('admin.books.edit', $book), route('admin.reviews.index'),
        route('lab.relations'), route('lab.n-plus-one'), route('lab.n-plus-one', ['variant' => 'strict']),
        route('lab.collections'), route('lab.models'), route('lab.xss'), route('lab.queues'), route('lab.realtime'), route('admin.imports.index'), route('notifications.index'), route('reports.stock'), route('reports.top-authors'), '/no-such-page',
    ];

    $unverified = User::factory()->unverified()->create();
    $urls = [
        ...array_map(fn ($url) => [null, $url], $guestUrls),
        [$unverified, route('verification.notice')],
        ...array_map(fn ($url) => [User::where('email', 'admin@example.com')->first(), $url], $adminUrls),
    ];

    foreach ($urls as [$user, $url]) {
        $user ? $this->actingAs($user) : auth()->logout();

        $html = preg_replace('#<script\b.*?</script>#s', '', $this->get($url)->getContent());

        expect($html)
            ->toContain('<html lang="uk">')
            ->not->toMatch('/\b(ui|lab|reports|enums|mail)\.[a-z_]+\.[a-z_]+/', "Untranslated key on {$url}");
    }
});

test('counts use Ukrainian plural forms', function (int $count, string $expected) {
    expect(trans_choice('ui.counts.books', $count))->toBe($expected);
})->with([
    [1, '1 книга'], [2, '2 книги'], [4, '4 книги'], [5, '5 книг'], [11, '11 книг'],
    [12, '12 книг'], [21, '21 книга'], [22, '22 книги'], [25, '25 книг'], [111, '111 книг'],
]);

test('other plural forms', function () {
    expect(trans_choice('ui.counts.reviews', 3))->toBe('3 відгуки')
        ->and(trans_choice('ui.counts.authors', 5))->toBe('5 авторів')
        ->and(trans_choice('ui.counts.units', 1))->toBe('1 примірник')
        ->and(trans_choice('ui.counts.units', 2))->toBe('2 примірники');
});

test('every enum case has a Ukrainian label', function () {
    foreach ([UserRole::class, BookFormat::class, AuthorRole::class, ImageType::class, StockMovementReason::class] as $enum) {
        foreach ($enum::cases() as $case) {
            expect($case->label())->not->toStartWith('enums.')->toMatch('/\p{Cyrillic}/u');
        }
    }

    expect(BookFormat::Hardcover->label())->toBe('Тверда обкладинка')
        ->and(AuthorRole::CoAuthor->label())->toBe('Співавтор')
        ->and(StockMovementReason::Receipt->label())->toBe('Надходження');
});

test('English fallback labels exist', function () {
    app()->setLocale('en');

    expect(BookFormat::Ebook->label())->toBe('E-book')
        ->and(__('ui.nav.catalog'))->toBe('Catalog');
});

test('en and uk lang files define the same keys', function (string $file) {
    $keys = fn (string $locale) => array_keys(Arr::dot(require lang_path("{$locale}/{$file}.php")));

    expect($keys('uk'))->toBe($keys('en'));
})->with(['ui', 'lab', 'reports', 'enums', 'mail']);

test('prices are shown in hryvnias', function () {
    $book = Book::factory()->create(['price' => 123450]);

    $this->get(route('books.show', $book))
        ->assertOk()
        ->assertSee('₴')
        // intl separates thousands with a no-break space (U+00A0).
        ->assertSee("1\u{A0}234,50", false);
});

test('slugs generated from Ukrainian text are ASCII', function () {
    $book = Book::factory()->create(['title' => 'Тіні забутих предків']);

    expect($book->slug)->toMatch('/^[a-z0-9-]+$/')
        ->and(Book::factory()->create()->slug)->toMatch('/^[a-z0-9-]+$/');
});

test('the last author is joined with a translated conjunction', function () {
    $book = Book::factory()->withAuthors(3)->create();
    $names = $book->authors()->get()->pluck('full_name');

    $this->get(route('books.show', $book))
        ->assertOk()
        ->assertSeeInOrder([$names[0], ', ', $names[1], ' та ', $names[2]], false)
        ->assertDontSee(' and ', false);
});
