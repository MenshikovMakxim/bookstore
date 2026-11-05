<?php

use App\Enums\AuthorRole;
use App\Enums\ImageType;
use App\Enums\UserRole;
use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Image;
use App\Models\Profile;
use App\Models\Review;
use App\Models\Scopes\ApprovedScope;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Tag;
use App\Models\User;
use App\Models\Warehouse;

test('hasOne / belongsTo: user profile', function () {
    $profile = Profile::factory()->create();

    $user = User::with('profile')->find($profile->user_id);

    expect($user->profile->is($profile))->toBeTrue()
        ->and(Profile::with('user')->find($profile->id)->user->is($user))->toBeTrue();
});

test('belongsTo: review user and book, manager warehouse, stock movement', function () {
    $review = Review::factory()->create();
    $manager = User::factory()->manager()->create();
    $movement = StockMovement::factory()->create(['user_id' => $manager->id]);

    $review->load(['user', 'book']);
    $movement->load(['warehouse', 'book', 'user']);

    expect($review->user->id)->toBe($review->user_id)
        ->and($review->book->id)->toBe($review->book_id)
        ->and($manager->load('warehouse')->warehouse->id)->toBe($manager->warehouse_id)
        ->and($movement->warehouse->id)->toBe($movement->warehouse_id)
        ->and($movement->book->id)->toBe($movement->book_id)
        ->and($movement->user->is($manager))->toBeTrue();
});

test('hasMany: book and user reviews (approved only, via ApprovedScope)', function () {
    $book = Book::factory()->create();
    Review::factory()->count(2)->approved()->for($book)->create();
    Review::factory()->for($book)->create(); // pending

    $book->load('reviews');

    expect($book->reviews)->toHaveCount(2)
        ->and($book->reviews->every->is_approved)->toBeTrue()
        ->and($book->reviews()->withoutGlobalScope(ApprovedScope::class)->count())->toBe(3);

    $user = User::factory()->has(Review::factory()->approved()->count(2))->create();
    expect($user->reviews()->count())->toBe(2);
});

test('hasOne latestOfMany picks the newest review', function () {
    $book = Book::factory()->withReviews(3)->create();

    $latestId = $book->reviews()->max('id');

    expect(Book::with('latestReview')->find($book->id)->latestReview->id)->toBe($latestId);
});

test('hasOne ofMany(rating, max) picks the best review, ties broken by highest id', function () {
    $book = Book::factory()->create();
    $ratings = [3, 5, 2, 5, 1];
    $reviews = collect($ratings)->map(fn (int $rating) => Review::factory()->approved()->for($book)->create(['rating' => $rating]));

    $best = Book::with('bestReview')->find($book->id)->bestReview;

    expect($best->rating)->toBe(5)
        ->and($best->id)->toBe($reviews[3]->id);
});

test('hasOne ofMany(rating, min) picks the lowest review, ties broken by lowest id', function () {
    $book = Book::factory()->create();
    $ratings = [5, 2, 3, 2, 4];
    $reviews = collect($ratings)->map(fn (int $rating) => Review::factory()->approved()->for($book)->create(['rating' => $rating]));

    $lowest = Book::with('lowestReview')->find($book->id)->lowestReview;

    expect($lowest->rating)->toBe(2)
        ->and($lowest->id)->toBe($reviews[1]->id);
});

test('ofMany relations work when eager loading many books at once', function () {
    $books = Book::factory()->count(3)->withReviews(2)->create();

    $loaded = Book::with(['latestReview', 'bestReview', 'lowestReview'])->whereIn('id', $books->pluck('id'))->get();

    foreach ($loaded as $book) {
        expect($book->latestReview->book_id)->toBe($book->id)
            ->and($book->bestReview->book_id)->toBe($book->id)
            ->and($book->lowestReview->book_id)->toBe($book->id);
    }
});

test('self-referencing belongsTo / hasMany: genre parent and children', function () {
    $root = Genre::factory()->create();
    $children = Genre::factory()->count(2)->child($root)->create();

    $root->load('children');
    $child = $children->first()->load('parent');

    expect($root->children->pluck('id')->all())->toEqualCanonicalizing($children->pluck('id')->all())
        ->and($child->parent->is($root))->toBeTrue();
});

test('hasMany main-genre books vs belongsToMany secondary books', function () {
    $genre = Genre::factory()->create();
    $mainBook = Book::factory()->for($genre)->create();
    $secondaryBook = Book::factory()->create();
    $secondaryBook->genres()->attach($genre);

    $genre->load(['books', 'secondaryBooks']);

    expect($genre->books->pluck('id')->all())->toBe([$mainBook->id])
        ->and($genre->secondaryBooks->pluck('id')->all())->toBe([$secondaryBook->id])
        ->and($secondaryBook->load('genres')->genres->pluck('id')->all())->toBe([$genre->id]);
});

test('hasOne with where: warehouse manager ignores other users of the warehouse', function () {
    $warehouse = Warehouse::factory()->create();
    User::factory()->create(['warehouse_id' => $warehouse->id]); // reader linked to the warehouse
    $manager = User::factory()->manager()->create(['warehouse_id' => $warehouse->id]);

    expect(Warehouse::with('manager')->find($warehouse->id)->manager->is($manager))->toBeTrue();
});

test('hasOneThrough: warehouse managerProfile goes through the manager user', function () {
    $warehouse = Warehouse::factory()->create();
    Profile::factory()->for(User::factory()->state(['warehouse_id' => $warehouse->id]))->create();
    $manager = User::factory()->manager()->create(['warehouse_id' => $warehouse->id]);
    $profile = Profile::factory()->for($manager)->create();

    $loaded = Warehouse::with('managerProfile')->find($warehouse->id)->managerProfile;

    expect($loaded->is($profile))->toBeTrue();
});

test('hasManyThrough: genre reviews via books with that main genre', function () {
    $genre = Genre::factory()->create();
    $books = Book::factory()->count(2)->for($genre)->withReviews(2)->create();
    Book::factory()->withReviews(3)->create(); // another genre

    $expected = Review::whereIn('book_id', $books->pluck('id'))->pluck('id')->sort()->values()->all();

    expect($genre->reviews()->pluck('reviews.id')->sort()->values()->all())->toBe($expected)
        ->and(Genre::withCount('reviews')->find($genre->id)->reviews_count)->toBe(4);
});

test('belongsToMany with pivot data, timestamps, order and custom accessor name', function () {
    $book = Book::factory()->withAuthors(3)->create();

    $authors = Book::with('authors')->find($book->id)->authors;

    expect($authors)->toHaveCount(3)
        ->and($authors->map(fn ($a) => $a->credit->sort_order)->all())->toBe([0, 1, 2])
        ->and($authors->first()->credit->role)->toBe(AuthorRole::Author->value)
        ->and($authors->last()->credit->role)->toBe(AuthorRole::CoAuthor->value)
        ->and($authors->first()->credit->created_at)->not->toBeNull();
});

test('belongsToMany wherePivot: translators and translatedBooks', function () {
    $book = Book::factory()->withAuthors(1)->create();
    $translator = Author::factory()->create();
    $book->authors()->attach($translator, ['role' => AuthorRole::Translator->value, 'sort_order' => 1]);

    expect($book->translators()->pluck('authors.id')->all())->toBe([$translator->id])
        ->and($translator->translatedBooks()->pluck('books.id')->all())->toBe([$book->id])
        ->and($book->authors()->count())->toBe(2);

    $author = Author::factory()->withBooks(2)->create();
    expect($author->books()->count())->toBe(2)
        ->and($author->translatedBooks()->count())->toBe(0);
});

test('coAuthors walks shared books and ignores translators', function () {
    $main = Author::factory()->create();
    $coAuthor = Author::factory()->create();
    $translator = Author::factory()->create();
    $stranger = Author::factory()->create();

    $book = Book::factory()->create();
    $book->authors()->attach($main, ['role' => AuthorRole::Author->value, 'sort_order' => 0]);
    $book->authors()->attach($coAuthor, ['role' => AuthorRole::CoAuthor->value, 'sort_order' => 1]);
    $book->authors()->attach($translator, ['role' => AuthorRole::Translator->value, 'sort_order' => 2]);

    expect($main->coAuthors()->pluck('id')->all())->toBe([$coAuthor->id])
        ->and($coAuthor->coAuthors()->pluck('id')->all())->toBe([$main->id])
        ->and($translator->coAuthors()->count())->toBe(0)
        ->and($stranger->coAuthors()->count())->toBe(0);

    // The same co-author on another book must not produce duplicate rows.
    $anotherBook = Book::factory()->create();
    $anotherBook->authors()->attach($main, ['role' => AuthorRole::Author->value, 'sort_order' => 0]);
    $anotherBook->authors()->attach($coAuthor, ['role' => AuthorRole::CoAuthor->value, 'sort_order' => 1]);

    expect($main->coAuthors()->pluck('id')->all())->toBe([$coAuthor->id]);
});

test('belongsToMany using a custom Stock pivot with its own id and accessor', function () {
    $book = Book::factory()->inWarehouses(2)->create();

    $warehouses = Book::with('warehouses')->find($book->id)->warehouses;
    $stock = $warehouses->first()->stock;

    expect($warehouses)->toHaveCount(2)
        ->and($stock)->toBeInstanceOf(Stock::class)
        ->and($stock->id)->toBeInt()
        ->and($stock->available)->toBe(max(0, $stock->quantity - $stock->reserved));

    $warehouse = $warehouses->first();
    $warehouse->books()->updateExistingPivot($book->id, ['quantity' => 10, 'reserved' => 3]);

    $fresh = Warehouse::with('books')->find($warehouse->id)->books->first()->stock;
    expect($fresh->available)->toBe(7)
        ->and($book->stocks()->count())->toBe(2)
        ->and($warehouse->stocks()->first()->load('book')->book->is($book))->toBeTrue();
});

test('morphOne with where, morphMany and morphTo', function () {
    $book = Book::factory()->create();
    $cover = $book->images()->create(['path' => 'covers/a.jpg', 'type' => ImageType::Cover]);
    $book->images()->create(['path' => 'gallery/b.jpg', 'type' => ImageType::Gallery]);

    $author = Author::factory()->create();
    $photo = $author->images()->create(['path' => 'authors/a.jpg', 'type' => ImageType::Photo]);

    $book->load(['cover', 'images']);

    expect($book->cover->is($cover))->toBeTrue()
        ->and($book->images)->toHaveCount(2)
        ->and($author->load('photo')->photo->is($photo))->toBeTrue()
        ->and(Image::with('imageable')->find($photo->id)->imageable)->toBeInstanceOf(Author::class)
        ->and(Image::with('imageable')->find($cover->id)->imageable->is($book))->toBeTrue();
});

test('warehouse is a polymorphic image owner', function () {
    $warehouse = Warehouse::factory()->create();
    $photo = $warehouse->images()->create(['path' => 'warehouses/a.jpg', 'type' => ImageType::Photo]);

    $warehouse->load('photo');
    $owner = Image::with('imageable')->find($photo->id)->imageable;

    expect($warehouse->photo->is($photo))->toBeTrue()
        ->and($owner)->toBeInstanceOf(Warehouse::class)
        ->and($owner->is($warehouse))->toBeTrue();
});

test('morphToMany and morphedByMany: tags', function () {
    $tag = Tag::factory()->create();
    $book = Book::factory()->create();
    $author = Author::factory()->create();

    $book->tags()->attach($tag);
    $author->tags()->attach($tag);

    $tag->load(['books', 'authors']);

    expect($book->load('tags')->tags->pluck('id')->all())->toBe([$tag->id])
        ->and($tag->books->pluck('id')->all())->toBe([$book->id])
        ->and($tag->authors->pluck('id')->all())->toBe([$author->id]);
});

test('user role enum is preserved through relations', function () {
    $manager = User::factory()->manager()->create();

    expect(Warehouse::with('manager')->find($manager->warehouse_id)->manager->role)->toBe(UserRole::Manager);
});
