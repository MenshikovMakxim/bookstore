{{--
    The visitor's own review and the review form. $myReview is loaded without
    ApprovedScope but only for user_id = the visitor (ShowsBook), so a pending
    review is visible to its author and to nobody else.
--}}
<section id="review-form" class="scroll-mt-4">
    <h2 class="mb-3 text-lg font-semibold">{{ __('ui.reviews.my_heading') }}</h2>

    @guest
        <x-card>
            <p class="text-sm text-stone-600">
                {{-- Behind "auth": after login the reader is sent back here (intended URL). --}}
                <a href="{{ route('books.reviews.create', $book) }}" class="text-amber-700 underline">{{ __('ui.reviews.login_link') }}</a>
                {{ __('ui.reviews.login_prompt') }}
            </p>
        </x-card>
    @endguest

    @auth
        @if ($myReview)
            <x-card data-my-review>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <x-rating-stars :rating="$myReview->rating->value" />
                    <span @class([
                        'rounded px-2 py-0.5 text-xs',
                        'bg-amber-100 text-amber-800' => ! $myReview->is_approved,
                        'bg-emerald-100 text-emerald-800' => $myReview->is_approved,
                    ])>{{ $myReview->is_approved ? __('ui.reviews.status_approved') : __('ui.reviews.status_pending') }}</span>
                </div>
                <p class="mt-2 text-sm text-stone-700">{!! nl2br(e($myReview->body)) !!}</p>
                {{-- A second create attempt that lost the unique-index race (ReviewController::store). --}}
                <x-form.error name="body" />

                {{-- Approved reviews are read-only: ReviewPolicy::update/delete say no. --}}
                @can('update', $myReview)
                    {{-- Open while the edit form has errors (named bag "reviewEdit"). --}}
                    <details class="mt-4" @if ($errors->reviewEdit->any()) open @endif>
                        <summary class="cursor-pointer text-sm text-amber-700">{{ __('ui.reviews.edit') }}</summary>
                        <form method="POST" action="{{ route('reviews.update', $myReview) }}" class="mt-3 space-y-3">
                            @csrf
                            @method('PUT')
                            <x-form.radio-rating name="rating" :label="__('ui.reviews.rating')" :value="$myReview->rating->value" bag="reviewEdit" />
                            <x-form.textarea name="body" :label="__('ui.reviews.body')" :value="$myReview->body" bag="reviewEdit" rows="5"
                                             :minlength="App\Http\Requests\StoreReviewRequest::BODY_MIN" :maxlength="App\Http\Requests\StoreReviewRequest::BODY_MAX" />
                            <button type="submit" class="btn">{{ __('ui.manage.save') }}</button>
                        </form>
                    </details>
                @endcan
                @can('delete', $myReview)
                    <form method="POST" action="{{ route('reviews.destroy', $myReview) }}" class="mt-3">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn-danger">{{ __('ui.reviews.delete') }}</button>
                    </form>
                @endcan
            </x-card>
        @elseif ($canReview)
            <x-card>
                <form method="POST" action="{{ route('books.reviews.store', $book) }}" class="space-y-3">
                    @csrf
                    <x-form.radio-rating name="rating" :label="__('ui.reviews.rating')" />
                    <x-form.textarea name="body" :label="__('ui.reviews.body')" rows="5" :hint="__('ui.reviews.body_hint', ['min' => App\Http\Requests\StoreReviewRequest::BODY_MIN, 'max' => App\Http\Requests\StoreReviewRequest::BODY_MAX])"
                                     :minlength="App\Http\Requests\StoreReviewRequest::BODY_MIN" :maxlength="App\Http\Requests\StoreReviewRequest::BODY_MAX" />
                    <button type="submit" class="btn">{{ __('ui.reviews.submit') }}</button>
                    <p class="text-xs text-stone-500">{{ __('ui.reviews.moderation_note') }}</p>
                </form>
            </x-card>
        @elseif (! auth()->user()->hasVerifiedEmail())
            <x-card>
                <p class="text-sm text-stone-600">
                    {{ __('ui.reviews.verify_prompt') }} <a href="{{ route('verification.notice') }}" class="text-amber-700 underline">{{ __('ui.auth.verify_link') }}</a>
                </p>
            </x-card>
        @endif
    @endauth
</section>
