<x-layouts.app :title="__('ui.genres.title')">
    <p class="mb-4 text-sm text-stone-500">{{ __('ui.genres.intro') }}</p>
    <x-card>
        <x-genre-tree :genres="$genres" counts link="genre" preview class="columns-1 md:columns-2" />
    </x-card>
</x-layouts.app>
