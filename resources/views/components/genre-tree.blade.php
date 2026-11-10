{{--
    Recursive anonymous component: renders itself for children.
    It only descends into relations that were eager-loaded (relationLoaded) —
    strict mode would throw on lazy loading.
--}}
@props(['genres', 'active' => null, 'counts' => false, 'link' => 'catalog', 'preview' => false])

<ul {{ $attributes->class(['space-y-1']) }}>
    @foreach ($genres as $genre)
        <li>
            <a href="{{ $link === 'catalog' ? route('books.index', ['genre' => $genre->slug]) : route('genres.show', $genre) }}"
               @class([
                   'hover:text-amber-700',
                   'font-semibold text-amber-700' => $active?->is($genre),
               ])>{{ $genre->name }}</a>
            @if ($counts)
                <span class="text-xs text-stone-400">({{ $genre->books_count }})</span>
            @endif

            @if ($preview && $genre->relationLoaded('books') && $genre->books->isNotEmpty())
                <ul class="mt-1 space-y-0.5 text-xs text-stone-500">
                    @foreach ($genre->books as $book)
                        <li class="truncate">
                            <a href="{{ route('books.show', $book) }}" class="hover:text-amber-700">{{ $book->title }}</a>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if ($genre->relationLoaded('children') && $genre->children->isNotEmpty())
                <x-genre-tree :genres="$genre->children" :active="$active" :counts="$counts" :link="$link" :preview="$preview" class="ml-4 mt-1" />
            @endif
        </li>
    @endforeach
</ul>
