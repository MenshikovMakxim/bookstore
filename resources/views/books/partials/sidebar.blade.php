{{-- $genreTree comes from GenreTreeComposer (View::composer), not from the controller. --}}
<form method="GET" action="{{ route('books.index') }}" id="catalog-filters" class="space-y-6 text-sm">
    {{-- Keep filters that have no input in this form. --}}
    @foreach (['genre', 'author'] as $key)
        @if (request()->filled($key))
            <input type="hidden" name="{{ $key }}" value="{{ request($key) }}">
        @endif
    @endforeach
    {{-- On /tags/{slug} the tag is in the path, not the query string. --}}
    @if (request()->filled('tag') || isset($activeTag))
        <input type="hidden" name="tag" value="{{ request('tag', ($activeTag ?? null)?->slug) }}">
    @endif

    <div>
        <label for="sort" class="mb-1 block font-semibold">{{ __('ui.catalog.sort') }}</label>
        <select id="sort" name="sort" class="w-full rounded border border-stone-300 bg-white px-2 py-1" data-autosubmit>
            @foreach ($sorts as $value)
                <option value="{{ $value }}" @selected($sort === $value)>{{ __('ui.catalog.sorts.'.$value) }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <label for="format" class="mb-1 block font-semibold">{{ __('ui.catalog.format') }}</label>
        <select id="format" name="format" class="w-full rounded border border-stone-300 bg-white px-2 py-1" data-autosubmit>
            <option value="">{{ __('ui.catalog.any') }}</option>
            @foreach ($formats as $format)
                <option value="{{ $format->value }}" @selected(request('format') === $format->value)>{{ $format->label() }}</option>
            @endforeach
        </select>
    </div>

    <label class="flex items-center gap-2">
        <input type="checkbox" name="in_stock" value="1" @checked(request()->boolean('in_stock')) data-autosubmit>
        {{ __('ui.catalog.in_stock_only') }}
    </label>

    <noscript><button type="submit" class="rounded bg-amber-600 px-3 py-1 text-white">{{ __('ui.catalog.apply') }}</button></noscript>

    <div>
        <h3 class="mb-2 font-semibold">{{ __('ui.catalog.genres') }}</h3>
        <x-genre-tree :genres="$genreTree" :active="$activeGenre" />
    </div>
</form>

{{-- @once: even if this partial were included twice, the script is pushed only once. --}}
@once
    @push('scripts')
        <script>
            document.querySelectorAll('#catalog-filters [data-autosubmit]').forEach((el) => {
                el.addEventListener('change', () => el.form.submit());
            });
        </script>
    @endpush
@endonce
