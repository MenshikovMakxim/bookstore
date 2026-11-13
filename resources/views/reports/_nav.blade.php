<nav class="-mt-4 mb-6 flex gap-4 text-sm">
    <a href="{{ route('reports.stock') }}" @class(['hover:text-amber-700', 'font-semibold text-amber-700' => request()->routeIs('reports.stock')])>{{ __('reports.nav.stock') }}</a>
    <a href="{{ route('reports.top-authors') }}" @class(['hover:text-amber-700', 'font-semibold text-amber-700' => request()->routeIs('reports.top-authors')])>{{ __('reports.nav.top_authors') }}</a>
    @can('export-csv')
        @php($csvWarehouse = $warehouse ?? null)
        <a href="{{ route('reports.stock-csv', $csvWarehouse ? ['warehouse' => $csvWarehouse] : []) }}" class="ml-auto text-amber-700 hover:underline">{{ __('reports.nav.csv') }}</a>
    @endcan
</nav>
