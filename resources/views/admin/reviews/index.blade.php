<x-layouts.app :title="__('ui.admin.reviews_title')">
    <div class="space-y-3">
        @forelse ($reviews as $review)
            <x-card>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span>
                        <span class="font-medium">{{ $review->user?->name ?? __('ui.book.deleted_user') }}</span>
                        <span class="text-stone-400">→</span>
                        @if ($review->book)
                            <a href="{{ route('admin.books.show', $review->book) }}" class="text-amber-800 hover:underline">{{ $review->book->title }}</a>
                        @endif
                    </span>
                    <x-rating-stars :rating="$review->rating->value" />
                </div>
                <p class="mt-2 text-sm text-stone-700">{{ $review->body }}</p>
                <x-slot:footer class="flex items-center justify-between">
                    <span>{{ $review->created_at?->diffForHumans() }}</span>
                    <span class="flex gap-2">
                        @can('approve', $review)
                            <form method="POST" action="{{ route('admin.reviews.approve', $review) }}">
                                @csrf @method('PATCH')
                                <button type="submit" class="btn-secondary">{{ __('ui.admin.approve') }}</button>
                            </form>
                        @endcan
                        @can('delete', $review)
                            <form method="POST" action="{{ route('admin.reviews.destroy', $review) }}">
                                @csrf @method('DELETE')
                                <button type="submit" class="btn-danger">{{ __('ui.admin.reject') }}</button>
                            </form>
                        @endcan
                    </span>
                </x-slot:footer>
            </x-card>
        @empty
            <p class="text-sm text-stone-500">{{ __('ui.admin.no_pending') }}</p>
        @endforelse
    </div>

    <div class="mt-6">{{ $reviews->links() }}</div>
</x-layouts.app>
