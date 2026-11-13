<?php

use App\Models\Book;
use App\Models\Review;
use App\Models\Scopes\ApprovedScope;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    app()->setLocale('uk');
    $this->book = Book::factory()->create();
});

function reviewPayload(array $overrides = []): array
{
    return ['rating' => 4, 'body' => 'Чудова книга, читається на одному диханні.', ...$overrides];
}

function anyReview(): Builder
{
    return Review::withoutGlobalScope(ApprovedScope::class);
}

test('guest: login prompt; after login the reader lands on the review form (intended URL)', function () {
    $create = route('books.reviews.create', $this->book);

    $this->get(route('books.show', $this->book))
        ->assertOk()
        ->assertSee('href="'.$create.'"', false)
        ->assertDontSee('action="'.route('books.reviews.store', $this->book).'"', false);

    $this->get($create)->assertRedirect(route('login'));

    $user = User::factory()->create();
    $this->post(route('login'), ['email' => $user->email, 'password' => 'password'])->assertRedirect($create);
    $this->get($create)->assertRedirect(route('books.show', $this->book).'#review-form');
});

test('guests cannot post reviews; unverified users are sent to verify', function () {
    $this->post(route('books.reviews.store', $this->book), reviewPayload())->assertRedirect(route('login'));

    $this->actingAs(User::factory()->unverified()->create());
    $this->get(route('books.show', $this->book))->assertSee(__('ui.reviews.verify_prompt'));
    $this->post(route('books.reviews.store', $this->book), reviewPayload())->assertRedirect(route('verification.notice'));

    expect(anyReview()->count())->toBe(0);
});

test('a verified reader writes one review; it is pending and is_approved cannot be smuggled in', function () {
    $user = asReader();

    $this->get(route('books.show', $this->book))->assertSee('action="'.route('books.reviews.store', $this->book).'"', false);

    $this->post(route('books.reviews.store', $this->book), reviewPayload(['is_approved' => 1, 'user_id' => 999]))
        ->assertRedirect(route('books.show', $this->book).'#review-form')
        ->assertSessionHas('status', __('ui.reviews.flash.created'));

    $review = anyReview()->sole();
    expect($review->user_id)->toBe($user->id)
        ->and($review->is_approved)->toBeFalse()
        ->and($review->rating->value)->toBe(4);

    // Second attempt: ReviewPolicy::create says no => 403 from StoreReviewRequest::authorize().
    $this->post(route('books.reviews.store', $this->book), reviewPayload())->assertForbidden();
    expect(anyReview()->count())->toBe(1);
});

test('StoreReviewRequest rules', function (array $overrides, array $errors) {
    asReader();

    $response = $this->post(route('books.reviews.store', $this->book), reviewPayload($overrides));

    $errors ? $response->assertSessionHasErrors($errors) : $response->assertSessionHasNoErrors();
})->with([
    'valid' => [[], []],
    'rating 1' => [['rating' => 1], []],
    'rating 5' => [['rating' => 5], []],
    'rating 0' => [['rating' => 0], ['rating']],
    'rating 6' => [['rating' => 6], ['rating']],
    'rating not a number' => [['rating' => 'five'], ['rating']],
    'no rating' => [['rating' => null], ['rating']],
    'body too short' => [['body' => 'Класно'], ['body']],
    'body too long' => [['body' => str_repeat('а', 2001)], ['body']],
    'closure rule: a link' => [['body' => 'Дивіться також https://example.com — там дешевше!'], ['body']],
    'closure rule: www' => [['body' => 'Купуйте на www.example.com зі знижкою, недорого.'], ['body']],
]);

test('the closure rule message is translated', function () {
    asReader();

    $this->post(route('books.reviews.store', $this->book), reviewPayload(['body' => 'Дивіться також https://example.com — там дешевше!']))
        ->assertSessionHasErrors(['body' => __('validation.no_links', ['attribute' => 'текст відгуку'])]);
});

test('a pending review is visible only to its author', function () {
    $author = User::factory()->create();
    Review::factory()->for($this->book)->for($author)->create(['body' => 'Мій відгук чекає на модерацію.']);

    $this->get(route('books.show', $this->book))->assertDontSee('Мій відгук чекає на модерацію.');

    asReader();
    $this->get(route('books.show', $this->book))->assertDontSee('Мій відгук чекає на модерацію.');

    $this->actingAs($author);
    $this->get(route('books.show', $this->book))
        ->assertSee('Мій відгук чекає на модерацію.')
        ->assertSee(__('ui.reviews.status_pending'))
        ->assertSee('action="'.route('reviews.update', anyReview()->sole()).'"', false)
        ->assertDontSee('action="'.route('books.reviews.store', $this->book).'"', false);
});

test('the author edits a pending review; a partial update keeps the other field (sometimes)', function () {
    $user = asReader();
    $review = Review::factory()->for($this->book)->for($user)->create(['rating' => 2]);

    $this->put(route('reviews.update', $review), reviewPayload(['rating' => 5, 'body' => 'Перечитав і змінив думку: дуже добре.']))
        ->assertRedirect(route('books.show', $this->book).'#review-form')
        ->assertSessionHas('status');
    expect($review->fresh())
        ->rating->value->toBe(5)
        ->body->toBe('Перечитав і змінив думку: дуже добре.');

    $this->put(route('reviews.update', $review), ['rating' => 3])->assertSessionHasNoErrors();
    expect($review->fresh())
        ->rating->value->toBe(3)
        ->body->toBe('Перечитав і змінив думку: дуже добре.');
});

test('edit errors go to the named bag "reviewEdit", with old input', function () {
    $user = asReader();
    $review = Review::factory()->for($this->book)->for($user)->create();

    $this->from(route('books.show', $this->book))
        ->put(route('reviews.update', $review), ['rating' => 9, 'body' => 'Коротко'])
        ->assertRedirect(route('books.show', $this->book).'#review-form')
        ->assertSessionHasErrorsIn('reviewEdit', ['rating', 'body'])
        ->assertSessionHasInput('body', 'Коротко');

    expect(session('errors')->getBag('default')->isEmpty())->toBeTrue();
});

test('the book page reopens the edit form with the named-bag errors and old input', function () {
    $user = asReader();
    $review = Review::factory()->for($this->book)->for($user)->create();

    $this->from(route('books.show', $this->book))->put(route('reviews.update', $review), ['rating' => 4, 'body' => 'Коротко']);

    $this->get(route('books.show', $this->book))
        ->assertSee('<details class="mt-4"  open', false)
        ->assertSee('>Коротко</textarea>', false)
        ->assertSee('id="error-body"', false);
});

test('approved reviews are read-only for the author; others cannot touch a pending review', function () {
    $user = asReader();
    $approved = Review::factory()->approved()->for($this->book)->for($user)->create();
    $foreign = Review::factory()->create();

    $this->get(route('books.show', $this->book))
        ->assertSee(__('ui.reviews.status_approved'))
        ->assertDontSee('action="'.route('reviews.update', $approved).'"', false);

    $this->put(route('reviews.update', $approved), reviewPayload())->assertForbidden();
    $this->delete(route('reviews.destroy', $approved))->assertForbidden();
    $this->put(route('reviews.update', $foreign), reviewPayload())->assertForbidden();
    $this->delete(route('reviews.destroy', $foreign))->assertForbidden();

    expect(anyReview()->count())->toBe(2);
});

test('the author deletes a pending review and may write a new one', function () {
    $user = asReader();
    $review = Review::factory()->for($this->book)->for($user)->create();

    $this->delete(route('reviews.destroy', $review))->assertRedirect()->assertSessionHas('status', __('ui.reviews.flash.deleted'));

    expect(anyReview()->count())->toBe(0);
    $this->post(route('books.reviews.store', $this->book), reviewPayload())->assertSessionHasNoErrors();
});

test('throttle:reviews: the 6th write within a minute is a 429', function () {
    asReader();

    foreach (range(1, 5) as $attempt) {
        $this->post(route('books.reviews.store', $this->book), ['rating' => 0])->assertSessionHasErrors('rating');
    }

    $this->post(route('books.reviews.store', $this->book), reviewPayload())
        ->assertTooManyRequests()
        ->assertSee(__('ui.errors.too_many_requests.title'));
    expect(anyReview()->count())->toBe(0);
});

test('unique(user_id, book_id) race: the DB error becomes a friendly validation error', function () {
    $user = asReader();

    // Simulate the other request winning the race: the policy has already said yes,
    // then a row for the same user and book appears just before our INSERT.
    Review::creating(function (Review $review) {
        DB::table('reviews')->insert([...$review->getAttributes(), 'created_at' => now(), 'updated_at' => now()]);
    });

    $this->post(route('books.reviews.store', $this->book), reviewPayload())
        ->assertRedirect(route('books.show', $this->book).'#review-form')
        ->assertSessionHasErrors(['body' => __('ui.reviews.already_reviewed')]);

    expect(anyReview()->where('user_id', $user->id)->count())->toBe(1);
});

test('review bodies are escaped; line breaks become <br>', function () {
    Review::factory()->approved()->for($this->book)->create(['body' => "Перший рядок\n<script>alert('xss')</script><img src=x onerror=alert(1)>"]);

    $this->get(route('books.show', $this->book))
        ->assertOk()
        ->assertSee('Перший рядок<br />', false)
        ->assertSee('&lt;script&gt;alert(&#039;xss&#039;)&lt;/script&gt;', false)
        ->assertDontSee("<script>alert('xss')", false)
        ->assertDontSee('<img src=x onerror', false);
});

test('moderation: the admin approves a review written through the form', function () {
    $reader = asReader();
    $this->post(route('books.reviews.store', $this->book), reviewPayload());
    $review = anyReview()->sole();

    asAdmin();
    $this->get(route('admin.reviews.index'))->assertSee(reviewPayload()['body']);
    $this->patch(route('admin.reviews.approve', $review))->assertSessionHas('status');

    $this->actingAs($reader);
    $this->get(route('books.show', $this->book))
        ->assertSee(__('ui.reviews.status_approved'))
        ->assertDontSee('action="'.route('reviews.update', $review).'"', false);
    auth()->logout();
    $this->get(route('books.show', $this->book))->assertSee(reviewPayload()['body']);
});
