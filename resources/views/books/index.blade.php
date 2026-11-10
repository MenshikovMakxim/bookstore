<x-layouts.app :title="$pageTitle ?? __('ui.catalog.title')">
    <div class="grid gap-8 md:grid-cols-[14rem_1fr]">
        <aside>
            {{-- @include shares the parent's variables; extra ones can be passed explicitly. --}}
            @include('books.partials.sidebar', ['sorts' => App\Http\Controllers\BookController::SORTS])
        </aside>

        <section>
            {{-- Active filters as removable chips: each link drops one query parameter. --}}
            @if ($filters)
                <div class="mb-4 flex flex-wrap items-center gap-2 text-sm">
                    @foreach ($filters as $key => $label)
                        <a href="{{ route('books.index', request()->except([$key, 'page'])) }}"
                           class="rounded-full bg-amber-100 px-3 py-1 text-amber-900 hover:bg-amber-200"
                           data-filter="{{ $key }}">
                            {{ $label }} <span aria-hidden="true">×</span>
                        </a>
                    @endforeach
                    <a href="{{ route('books.index') }}" class="text-stone-500 hover:underline">{{ __('ui.catalog.clear_all') }}</a>
                </div>
            @endif

            <p class="mb-4 text-sm text-stone-500">{{ trans_choice('ui.counts.books', $books->total(), ['count' => Number::format($books->total())]) }}</p>

            <div class="grid grid-cols-2 gap-4 lg:grid-cols-3 xl:grid-cols-4">
                @forelse ($books as $book)
                    <x-book-card :book="$book" />
                @empty
                    {{-- @forelse empty state --}}
                    <x-card class="col-span-full text-center text-stone-500">
                        {{ __('ui.catalog.empty') }} <a href="{{ route('books.index') }}" class="text-amber-700 underline">{{ __('ui.catalog.reset') }}</a>
                    </x-card>
                @endforelse
            </div>

            <div class="mt-6">{{ $books->links() }}</div>
        </section>
    </div>
</x-layouts.app>
