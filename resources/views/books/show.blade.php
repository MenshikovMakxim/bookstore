<x-layouts.app :title="$book->title">
    {{-- Genre breadcrumb: parent → genre (genre.parent eager-loaded). --}}
    <nav class="-mt-4 mb-6 text-sm text-stone-500">
        <a href="{{ route('books.index') }}" class="hover:underline">{{ __('ui.nav.catalog') }}</a>
        @if ($book->genre->parent)
            / <a href="{{ route('genres.show', $book->genre->parent) }}" class="hover:underline">{{ $book->genre->parent->name }}</a>
        @endif
        / <a href="{{ route('genres.show', $book->genre) }}" class="hover:underline">{{ $book->genre->name }}</a>
        @isset($viaAuthor)
            <span class="ml-2 rounded bg-stone-200 px-2 py-0.5 text-xs">
                {{ __('ui.book.via') }} <a href="{{ route('authors.show', $viaAuthor) }}" class="underline">{{ $viaAuthor->full_name }}</a>
            </span>
        @endisset
    </nav>

    <div class="grid gap-8 md:grid-cols-[16rem_1fr]">
        <div class="space-y-4">
            <div class="flex h-80 items-center justify-center rounded-lg bg-gradient-to-br from-amber-100 to-stone-200 p-4 text-center text-lg font-semibold text-stone-600"
                 title="{{ $book->cover?->path ?? __('ui.card.no_cover') }}">
                {{ $book->title }}
            </div>
            <x-card>
                <dl class="space-y-1 text-sm">
                    <div class="flex justify-between gap-2"><dt class="text-stone-500">{{ __('ui.book.price') }}</dt><dd class="font-semibold">{{ $book->price->format() }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-stone-500">{{ __('ui.book.format') }}</dt><dd class="text-right">{{ $book->format->label() }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-stone-500">{{ __('ui.book.isbn') }}</dt><dd>{{ $book->isbn_formatted }}</dd></div>
                    <div class="flex justify-between gap-2"><dt class="text-stone-500">{{ __('ui.book.published') }}</dt><dd>{{ $book->published_at?->translatedFormat('j F Y') ?? __('ui.book.unpublished') }}</dd></div>
                    @if ($book->meta?->has('pages'))
                        <div class="flex justify-between gap-2"><dt class="text-stone-500">{{ __('ui.book.pages') }}</dt><dd>{{ $book->meta->get('pages') }}</dd></div>
                    @endif
                </dl>
            </x-card>
        </div>

        <div class="space-y-6">
            <section>
                <p class="text-stone-600">
                    {{-- $loop: first / last / iteration in a list of credits. --}}
                    @foreach ($book->authors as $author)
                        @if (! $loop->first){{ $loop->last ? ' '.__('ui.book.and').' ' : ', ' }}@endif<a href="{{ route('authors.show', $author) }}" class="font-medium text-amber-800 hover:underline">{{ $author->full_name }}</a>
                        <span class="text-xs text-stone-400">({{ Str::lower(App\Enums\AuthorRole::from($author->credit->role)->label()) }})</span>
                    @endforeach
                </p>
                <div class="mt-2"><x-rating-stars :rating="$book->reviews_avg_rating" /> <span class="text-sm text-stone-500">· {{ trans_choice('ui.counts.approved_reviews', $book->reviews_count) }}</span></div>
            </section>

            @if ($book->genres->isNotEmpty() || $book->tags->isNotEmpty())
                <section class="flex flex-wrap gap-2 text-sm">
                    @foreach ($book->genres as $genre)
                        <a href="{{ route('genres.show', $genre) }}" class="rounded-full border border-stone-300 px-3 py-0.5 hover:bg-stone-100">{{ $genre->name }}</a>
                    @endforeach
                    @foreach ($book->tags as $tag)
                        <a href="{{ route('tags.show', $tag) }}" class="rounded-full bg-stone-200 px-3 py-0.5 text-stone-600 hover:bg-stone-300">#{{ $tag->name }}</a>
                    @endforeach
                </section>
            @endif

            <x-card>
                <x-slot:header>{{ __('ui.book.availability') }}</x-slot:header>
                {{-- Status only; quantities per warehouse are visible to staff in /manage. --}}
                {{-- data-live-stock: updated over WebSocket by resources/js/live.js (stage 10). --}}
                <x-stock-badge :quantity="(int) $book->stocks_sum_quantity" :reserved="(int) $book->stocks_sum_reserved" data-live-stock :data-book-id="$book->id" />
                @role('manager', 'admin')
                    <a href="{{ route('manage.warehouses.index') }}" class="ml-3 text-sm text-amber-700 hover:underline">{{ __('ui.book.manage_stock') }}</a>
                @endrole
                @role('admin')
                    <a href="{{ route('admin.books.edit', $book) }}" class="ml-3 text-sm text-amber-700 hover:underline">{{ __('ui.admin.edit') }}</a>
                @endrole
            </x-card>

            <div class="grid gap-4 md:grid-cols-2">
                @foreach ([__('ui.book.latest_review') => $book->latestReview, __('ui.book.best_review') => $book->bestReview] as $heading => $review)
                    <x-card>
                        <x-slot:header>{{ $heading }}</x-slot:header>
                        @if ($review)
                            <x-rating-stars :rating="$review->rating" />
                            <p class="mt-2 text-sm">{{ Str::limit($review->body, 160) }}</p>
                            <x-slot:footer>{{ $review->user?->name ?? __('ui.book.deleted_user') }} · {{ $review->created_at->diffForHumans() }}</x-slot:footer>
                        @else
                            <p class="text-sm text-stone-500">{{ __('ui.book.no_reviews') }}</p>
                        @endif
                    </x-card>
                @endforeach
            </div>

            @include('books.partials.my-review')

            <section>
                <h2 class="mb-3 text-lg font-semibold">{{ __('ui.book.reviews_heading', ['count' => $reviews->total()]) }}</h2>
                <div class="space-y-3">
                    @forelse ($reviews as $review)
                        <x-card>
                            <div class="flex items-center justify-between">
                                <span class="font-medium">{{ $review->user->name }}</span>
                                <x-rating-stars :rating="$review->rating" />
                            </div>
                            {{-- Escape first, then add <br>: e() turns every < > " ' & of the user's text into
                                 entities, so the only tags in the output are the <br>s nl2br() adds. The other
                                 order, {!! nl2br($review->body) !!}, prints the user's own tags as HTML (XSS):
                                 see /lab/xss. --}}
                            <p class="mt-2 text-sm text-stone-700">{!! nl2br(e($review->body)) !!}</p>
                        </x-card>
                    @empty
                        <p class="text-sm text-stone-500">{{ __('ui.book.no_approved_reviews') }}</p>
                    @endforelse
                </div>
                <div class="mt-4">{{ $reviews->links() }}</div>
            </section>
        </div>
    </div>
</x-layouts.app>
