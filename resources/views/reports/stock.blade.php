{{-- SQL report: sums stay integer kopecks in the query, Money only formats them. --}}
@use('App\ValueObjects\Money')
<x-layouts.app :title="__('reports.stock.title')">
    @include('reports._nav')

    @if ($warehouse)
        <p class="mb-4 text-sm text-stone-600">
            {{ __('reports.stock.filtered', ['warehouse' => $warehouse->name]) }}
            <a href="{{ route('reports.stock') }}" class="ml-2 text-amber-700 hover:underline">{{ __('reports.stock.all') }}</a>
        </p>
    @endif

    <div class="mb-6 grid grid-cols-3 gap-4">
        <x-card class="text-center"><div class="text-2xl font-semibold">{{ Number::format($totals['quantity']) }}</div><div class="text-sm text-stone-500">{{ __('reports.stock.units') }}</div></x-card>
        <x-card class="text-center"><div class="text-2xl font-semibold">{{ Number::format($totals['reserved']) }}</div><div class="text-sm text-stone-500">{{ __('reports.stock.reserved') }}</div></x-card>
        <x-card class="text-center"><div class="text-2xl font-semibold">{{ Money::fromCents((int) $totals['value_cents'])->format() }}</div><div class="text-sm text-stone-500">{{ __('reports.stock.value') }}</div></x-card>
    </div>

    <div class="space-y-6">
        @forelse ($warehouses as $warehouseName => $rows)
            <x-card>
                <x-slot:header>{{ $warehouseName }}</x-slot:header>
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-stone-400">
                        <tr>
                            <th class="py-1">{{ __('reports.stock.columns.genre') }}</th>
                            <th class="py-1 text-right">{{ __('reports.stock.columns.titles') }}</th>
                            <th class="py-1 text-right">{{ __('reports.stock.columns.quantity') }}</th>
                            <th class="py-1 text-right">{{ __('reports.stock.columns.reserved') }}</th>
                            <th class="py-1 text-right">{{ __('reports.stock.columns.value') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr class="border-t border-stone-100">
                                <td class="py-1">{{ $row->genre }}</td>
                                <td class="py-1 text-right tabular-nums">{{ Number::format($row->books) }}</td>
                                <td class="py-1 text-right tabular-nums">{{ Number::format($row->quantity) }}</td>
                                <td class="py-1 text-right tabular-nums">{{ Number::format($row->reserved) }}</td>
                                <td class="py-1 text-right tabular-nums">{{ Money::fromCents((int) $row->value_cents)->format() }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <x-slot:footer>
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span>
                            {{ trans_choice('ui.counts.units', $rows->sum('quantity'), ['count' => Number::format($rows->sum('quantity'))]) }} · {{ Money::fromCents((int) $rows->sum('value_cents'))->format() }}
                        </span>
                        <span class="flex gap-3">
                            <a href="{{ route('reports.stock', $rows->first()->warehouse_id) }}" class="text-amber-700 hover:underline">{{ __('reports.stock.only') }}</a>
                            @can('export-csv')
                                <a href="{{ route('reports.stock-csv', $rows->first()->warehouse_id) }}" class="text-amber-700 hover:underline">{{ __('reports.stock.warehouse_csv') }}</a>
                            @endcan
                        </span>
                    </div>
                </x-slot:footer>
            </x-card>
        @empty
            <p class="text-stone-500">{{ __('reports.stock.empty') }}</p>
        @endforelse
    </div>
</x-layouts.app>
