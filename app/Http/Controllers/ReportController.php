<?php

namespace App\Http\Controllers;

use App\Models\Warehouse;
use App\Reports\StockByWarehouseGenreReport;
use App\Reports\TopAuthorsReport;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Invokable controller behind all reports.* routes; the route passes $report
 * via ->defaults(). Access is restricted to managers/admins in stage 7.
 */
class ReportController extends Controller
{
    // Route parameters are injected positionally: {warehouse?} first, then the
    // ->defaults('report') value.
    public function __invoke(?Warehouse $warehouse, string $report): View|StreamedResponse
    {
        return match ($report) {
            'stock' => $this->stock($warehouse),
            'stock-csv' => $this->stockCsv($warehouse),
            'top-authors' => $this->topAuthors(),
        };
    }

    private function stock(?Warehouse $warehouse): View
    {
        $report = new StockByWarehouseGenreReport($warehouse?->id);
        $rows = $report->rows();

        return view('reports.stock', [
            'warehouses' => $rows->groupBy('warehouse'),
            'totals' => $report->totals($rows),
            'warehouse' => $warehouse,
        ]);
    }

    private function topAuthors(): View
    {
        $report = new TopAuthorsReport;

        return view('reports.top-authors', [
            'authors' => $report->rows(),
            'minReviews' => $report->minReviews(),
        ]);
    }

    /**
     * Streams the CSV while reading stock rows in chunks (lazyById):
     * memory stays flat no matter how many rows there are.
     */
    private function stockCsv(?Warehouse $warehouse): StreamedResponse
    {
        return response()->streamDownload(function () use ($warehouse) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['warehouse', 'shelf', 'isbn', 'title', 'quantity', 'reserved', 'price_cents', 'value_cents'], escape: '');

            foreach ((new StockByWarehouseGenreReport($warehouse?->id))->lines() as $line) {
                fputcsv($out, [
                    $line->warehouse,
                    $line->shelf_code,
                    $line->isbn,
                    self::csvSafe($line->title),
                    $line->quantity,
                    $line->reserved,
                    $line->price,
                    $line->quantity * $line->price,
                ], escape: '');
            }

            fclose($out);
        }, $this->csvFilename($warehouse), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function csvFilename(?Warehouse $warehouse): string
    {
        $suffix = $warehouse ? '-'.Str::slug($warehouse->name, '-', 'uk') : '';

        return 'stock'.$suffix.'-'.now()->format('Y-m-d').'.csv';
    }

    /**
     * Spreadsheet apps execute cells starting with = + - @ as formulas (CSV injection).
     */
    private static function csvSafe(string $value): string
    {
        return in_array(substr($value, 0, 1), ['=', '+', '-', '@'], true) ? "'".$value : $value;
    }
}
