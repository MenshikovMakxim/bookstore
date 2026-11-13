<?php

use App\Lab\Models\LabBook;
use App\Lab\Models\LabUser;
use App\Models\Author;
use App\Models\Book;
use App\Models\Profile;
use App\Models\Review;
use App\Models\User;
use App\ValueObjects\Money;
use App\ValueObjects\Rating;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\ArrayObject;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

test('price is cast to Money and stored as integer kopecks', function () {
    $book = Book::factory()->create(['price' => Money::fromUah('249.50')]);

    expect($book->fresh()->price)->toBeInstanceOf(Money::class)
        ->and($book->fresh()->price->cents)->toBe(24950)
        ->and(DB::table('books')->where('id', $book->id)->value('price'))->toBe(24950);

    $book->update(['price' => 1000]); // plain int = kopecks
    expect($book->fresh()->price->cents)->toBe(1000);
});

test('review rating is a Rating value object; the database keeps the raw integer', function () {
    // HTTP form values arrive as strings; the cast accepts them and validates the range.
    $review = Review::factory()->create(['rating' => '4']);

    expect($review->rating)->toBeInstanceOf(Rating::class)
        ->and($review->rating->value)->toBe(4)
        ->and($review->rating->stars())->toBe('★★★★☆')
        ->and($review->toArray()['rating'])->toBe(['value' => 4, 'stars' => '★★★★☆'])
        ->and(DB::table('reviews')->where('id', $review->id)->value('rating'))->toBe(4);
});

test('a float price is rejected with a clear message', function () {
    $book = Book::factory()->make();

    expect(fn () => $book->price = 19.99)
        ->toThrow(InvalidArgumentException::class, "does not accept floats (19.99 given). Pass integer kopecks or Money::fromUah('19.99')");
});

test('ISBN mutator keeps digits only; isbn_formatted hyphenates', function () {
    $book = Book::factory()->create(['isbn' => '978-617 7489-12-4']);

    expect($book->getAttributes()['isbn'])->toBe('9786177489124')
        ->and(DB::table('books')->where('id', $book->id)->value('isbn'))->toBe('9786177489124')
        ->and($book->isbn_formatted)->toBe('978-6-1774-8912-4');
});

test('isbn_formatted is cached per instance (shouldCache) until the attributes are reset', function () {
    $book = Book::factory()->create(['isbn' => '9786177489124']);
    expect($book->isbn_formatted)->toBe('978-6-1774-8912-4');

    $book->isbn = '9790000000001';
    $book->save();
    expect($book->isbn_formatted)->toBe('978-6-1774-8912-4'); // stale even after save: documented caveat

    expect($book->fresh()->isbn_formatted)->toBe('979-0-0000-0000-1');
});

test('full_name accessor', function () {
    $author = Author::factory()->make(['first_name' => 'Леся', 'last_name' => 'Українка']);

    expect($author->full_name)->toBe('Леся Українка')
        ->and($author->fullName)->toBe('Леся Українка');
});

test('legacy accessor and mutator on LabBook', function () {
    $book = new LabBook;
    $book->title = "  Тіні   забутих\tпредків ";
    $book->published_year = 1911;

    expect($book->title)->toBe('Тіні забутих предків')
        ->and($book->display_title)->toBe('Тіні забутих предків (1911)');
});

test('encrypted phone is unreadable in the raw database row', function () {
    $profile = Profile::factory()->create(['phone' => '+380501234567']);

    $raw = DB::table('profiles')->where('id', $profile->id)->value('phone');

    expect($raw)->not->toContain('380501234567')
        ->and(Crypt::decryptString($raw))->toBe('+380501234567')
        ->and($profile->fresh()->phone)->toBe('+380501234567')
        // Ciphertext differs every time: equality search is impossible.
        ->and(Profile::where('phone', '+380501234567')->exists())->toBeFalse();
});

test('date casts: immutable published_at, meta as collection or ArrayObject', function () {
    $book = Book::factory()->published()->create(['meta' => ['pages' => 320]]);
    $fresh = $book->fresh();

    expect($fresh->published_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($fresh->meta)->toBeInstanceOf(Collection::class)
        ->and($fresh->meta->get('pages'))->toBe(320)
        ->and(LabBook::find($book->id)->meta)->toBeInstanceOf(ArrayObject::class);

    $fresh->meta->put('language', 'uk');
    expect($fresh->isDirty('meta'))->toBeTrue();
});

test('serialization: Money as {cents, formatted}, appended isbn_formatted, hidden user fields', function () {
    $book = Book::factory()->create(['price' => 12345, 'isbn' => '9786177489124']);

    expect($book->toArray())
        ->toMatchArray([
            'price' => ['cents' => 12345, 'formatted' => Money::fromCents(12345)->format()],
            'isbn_formatted' => '978-6-1774-8912-4',
        ])
        // Selected without isbn: the appended accessor yields null instead of a strict-mode error.
        ->and(Book::select(['id', 'title'])->find($book->id)->toArray()['isbn_formatted'])->toBeNull();

    $user = User::factory()->create();

    expect($user->toArray())->not->toHaveKeys(['password', 'remember_token', 'email'])
        ->and($user->makeVisible('email')->toArray())->toHaveKey('email');
});

test('strict mode turns a non-fillable attribute into an exception', function () {
    expect(fn () => new Book(['title' => 'Кобзар', 'rating_avg' => 5]))
        ->toThrow(MassAssignmentException::class, 'rating_avg');
});

test('guarded = [] lets input escalate privileges; User keeps role out of #[Fillable]', function () {
    $input = ['name' => 'Mallory', 'email' => 'mallory@example.com', 'password' => 'secret', 'role' => 'admin'];

    expect(LabUser::create($input)->role->value)->toBe('admin')
        ->and(fn () => User::create([...$input, 'email' => 'm2@example.com']))->toThrow(MassAssignmentException::class, 'role');
});
