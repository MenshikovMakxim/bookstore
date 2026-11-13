<?php

namespace App\Lab;

use App\Enums\BookFormat;
use App\Lab\Collections\Approach;
use App\Lab\Collections\Comparison;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Profile;
use App\Models\Review;
use App\Models\Scopes\ApprovedScope;
use App\Models\Stock;
use App\Reports\StockByWarehouseGenreReport;
use App\ValueObjects\Money;
use Closure;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

/**
 * Registry of "same task, approach A vs B" comparisons for /lab/collections.
 *
 * Every approach is profiled with QueryProfiler (queries, DB time, wall time,
 * peak memory). Write comparisons run inside a transaction that is rolled back.
 */
final class CollectionExamples
{
    /**
     * Section keys in display order; titles: lang/{en,uk}/lab.php => collections.sections.*
     *
     * @var list<string>
     */
    public const SECTIONS = [
        'types',
        'eloquent-collection',
        'relation-query',
        'aggregates',
        'large-volumes',
        'chunk-pitfall',
        'mass-update',
        'subqueries',
        'report',
    ];

    /**
     * @return array<string, string> section key => translated title
     */
    public static function sections(): array
    {
        return collect(self::SECTIONS)
            ->mapWithKeys(fn (string $key) => [$key => __('lab.collections.sections.'.$key)])
            ->all();
    }

    /**
     * The "PHP way" stock report hydrates every stock row with its book. Above this
     * many stock rows it runs on the first warehouse only (and says so).
     */
    public const PHP_REPORT_CAP = 30_000;

    /**
     * @var list<Comparison>
     */
    private array $comparisons = [];

    private ?int $busiestBookId = null;

    private ?int $reportWarehouseId = null;

    private bool $reportCapped = false;

    public function __construct()
    {
        $this->types();
        $this->eloquentCollection();
        $this->relationQuery();
        $this->aggregates();
        $this->largeVolumes();
        $this->chunkPitfall();
        $this->massUpdate();
        $this->subqueries();
        $this->report();
    }

    /**
     * @return list<Comparison>
     */
    public function comparisons(?string $section = null): array
    {
        return array_values(array_filter(
            $this->comparisons,
            fn (Comparison $comparison) => $section === null || $comparison->section === $section,
        ));
    }

    /**
     * Run every approach of a comparison.
     *
     * @return list<array{approach: Approach, profile: Profile}>
     */
    public function run(Comparison $comparison): array
    {
        $this->prepare();

        return array_map(function (Approach $approach) use ($comparison) {
            $callback = fn () => Presenter::present(($approach->run)());

            if ($comparison->writes) {
                $inner = $callback;
                $callback = function () use ($inner) {
                    DB::beginTransaction();

                    try {
                        return $inner();
                    } finally {
                        DB::rollBack();
                    }
                };
            }

            return [
                'approach' => $approach,
                'profile' => QueryProfiler::run($callback, $comparison->heavy ? 1 : 2),
            ];
        }, $comparison->approaches);
    }

    /**
     * Notes shown above a section (e.g. when a variant runs on a capped subset).
     *
     * @return list<string>
     */
    public function notes(string $section): array
    {
        $this->prepare();

        return $section === 'report' && $this->reportCapped
            ? [__('lab.collections.capped', ['cap' => Number::format(self::PHP_REPORT_CAP)])]
            : [];
    }

    /**
     * Sample records, resolved once and outside profiling.
     */
    private function prepare(): void
    {
        if ($this->busiestBookId !== null) {
            return;
        }

        $this->busiestBookId = (int) Review::query()->select('book_id')->groupBy('book_id')
            ->orderByRaw('count(*) desc')->orderBy('book_id')->value('book_id');

        if (Stock::count() > self::PHP_REPORT_CAP) {
            $this->reportCapped = true;
            $this->reportWarehouseId = (int) DB::table('warehouses')->min('id');
        }
    }

    /**
     * @param  list<Approach>  $approaches
     */
    private function compare(string $section, string $title, string $description, array $approaches, bool $writes = false, bool $heavy = false): void
    {
        $this->comparisons[] = new Comparison($section, $title, $description, $approaches, $writes, $heavy);
    }

    private static function approach(string $label, Closure $run, ?string $note = null): Approach
    {
        return new Approach($label, $run, $note);
    }

    /**
     * Type of a value as shown on the page: "string 'paperback'", "App\Enums\BookFormat::Paperback", ...
     */
    private static function typed(mixed $value): string
    {
        return match (true) {
            $value instanceof \UnitEnum => $value::class.'::'.$value->name,
            $value instanceof Money => $value::class.' '.$value->format(),
            $value instanceof \DateTimeInterface => $value::class.' '.$value->format('Y-m-d H:i'),
            is_string($value) => 'string '.var_export(Str::limit($value, 40), true),
            default => get_debug_type($value).' '.var_export($value, true),
        };
    }

    /**
     * @param  Collection<int, mixed>  $rows
     * @return array<string, mixed>
     */
    private static function describe(Collection $rows): array
    {
        $first = $rows->first();

        return [
            'collection' => $rows::class,
            'count' => $rows->count(),
            'item' => get_debug_type($first),
            'format' => self::typed($first->format),
            'price' => self::typed($first->price),
            'published_at' => self::typed($first->published_at),
            'meta' => self::typed($first->meta),
            'has relation methods' => method_exists($first, 'genre'),
            'genre loaded' => $first instanceof Book ? $first->relationLoaded('genre') : 'n/a (no relations)',
        ];
    }

    private function types(): void
    {
        $this->compare('types', __('lab.collections.comparisons.what_comes_back'),
            __('lab.collections.comparisons.what_comes_back_d'),
            [
                self::approach('DB::table()->get()', function () {
                    return self::describe(DB::table('books')->whereNotNull('published_at')->orderBy('id')->limit(3)->get());
                }),
                self::approach('Book::get()', function () {
                    return self::describe(Book::whereNotNull('published_at')->orderBy('id')->limit(3)->get());
                }),
                self::approach('Book::toBase()->get()', function () {
                    return self::describe(Book::whereNotNull('published_at')->orderBy('id')->limit(3)->toBase()->get());
                }),
            ]);

        $this->compare('types', __('lab.collections.comparisons.hydration'),
            __('lab.collections.comparisons.hydration_d'),
            [
                self::approach('DB::table()->get()', function () {
                    $rows = DB::table('books')->get();

                    return ['count' => $rows->count(), 'item' => get_debug_type($rows->first())];
                }),
                self::approach('Book::get()', function () {
                    $books = Book::get();

                    return ['count' => $books->count(), 'item' => get_debug_type($books->first())];
                }),
                self::approach('Book::toBase()->get()', function () {
                    $rows = Book::toBase()->get();

                    return ['count' => $rows->count(), 'item' => get_debug_type($rows->first())];
                }),
            ], heavy: true);
    }

    private function eloquentCollection(): void
    {
        $this->compare('eloquent-collection', __('lab.collections.comparisons.load_methods'),
            __('lab.collections.comparisons.load_methods_d'),
            [
                self::approach(__('lab.collections.labels.collection_methods'), function () {
                    $books = Book::orderBy('id')->limit(20)->get();

                    $books->load('genre')->loadMissing(['genre', 'cover'])->loadCount('reviews');

                    return $books->pluck('reviews_count', 'id');
                }),
                self::approach(__('lab.collections.labels.per_model'), function () {
                    $books = Book::orderBy('id')->limit(20)->get();

                    $books->each(function (Book $book) {
                        $book->setRelation('genre', $book->genre()->first());
                        $book->setRelation('cover', $book->cover()->first());
                        $book->reviews_count = $book->reviews()->count();
                    });

                    return $books->pluck('reviews_count', 'id');
                }),
            ]);

        $this->compare('eloquent-collection', __('lab.collections.comparisons.find_keys'),
            __('lab.collections.comparisons.find_keys_d'),
            [
                self::approach(__('lab.collections.labels.eloquent'), function () {
                    $books = Book::orderBy('id')->limit(20)->get();

                    return ['found' => $books->find($books->last()->id)?->title, 'keys' => $books->modelKeys()];
                }),
                self::approach(__('lab.collections.labels.base'), function () {
                    $books = Book::orderBy('id')->limit(20)->get()->toBase();

                    return ['found' => $books->firstWhere('id', $books->last()->id)?->title, 'keys' => $books->pluck('id')->all()];
                }),
            ]);

        $this->compare('eloquent-collection', __('lab.collections.comparisons.fresh'),
            __('lab.collections.comparisons.fresh_d'),
            [
                self::approach('$books->fresh()', function () {
                    $books = Book::orderBy('id')->limit(20)->get();

                    return $books->fresh()->count();
                }),
                self::approach('$books->map->fresh()', function () {
                    $books = Book::orderBy('id')->limit(20)->get();

                    return $books->map->fresh()->count();
                }),
            ]);

        $this->compare('eloquent-collection', __('lab.collections.comparisons.to_query_update'),
            __('lab.collections.comparisons.to_query_update_d'),
            [
                self::approach('$books->toQuery()->update()', function () {
                    $books = Book::orderBy('id')->limit(20)->get();

                    return $books->toQuery()->update(['published_year' => 2000]);
                }),
                self::approach('whereIn(modelKeys())->update()', function () {
                    $books = Book::orderBy('id')->limit(20)->get();

                    return Book::whereIn('id', $books->modelKeys())->update(['published_year' => 2000]);
                }),
            ], writes: true);

        $this->compare('eloquent-collection', __('lab.collections.comparisons.by_key'),
            __('lab.collections.comparisons.by_key_d'),
            [
                self::approach(__('lab.collections.labels.by_key'), function () {
                    $first = Book::orderBy('id')->limit(10)->get();
                    $second = Book::orderBy('id')->offset(5)->limit(10)->get();
                    $second->first()->price = $second->first()->price->add(Money::fromCents(1)); // in memory only

                    return [
                        'diff' => $first->diff($second)->modelKeys(),
                        'intersect' => $first->intersect($second)->modelKeys(),
                        'unique' => $first->concat($second)->unique()->count(),
                        'contains' => $first->contains(Book::find($first->first()->id)),
                    ];
                }),
                self::approach(__('lab.collections.labels.by_value'), function () {
                    $first = Book::orderBy('id')->limit(10)->get()->toBase();
                    $second = Book::orderBy('id')->offset(5)->limit(10)->get()->toBase();
                    $second->first()->price = $second->first()->price->add(Money::fromCents(1)); // in memory only

                    return [
                        'diff' => $first->diff($second)->pluck('id')->all(),
                        'intersect' => $first->intersect($second)->pluck('id')->all(),
                        'unique' => $first->concat($second)->unique()->count(),
                        'contains' => $first->contains(Book::find($first->first()->id)),
                    ];
                }),
            ]);

        $this->compare('eloquent-collection', __('lab.collections.comparisons.visibility'),
            __('lab.collections.comparisons.visibility_d'),
            [
                self::approach('makeVisible / makeHidden', function () {
                    $profiles = Profile::orderBy('id')->limit(3)->get();

                    return $profiles->makeVisible('phone')->makeHidden(['bio', 'avatar_path', 'created_at', 'updated_at'])->toArray();
                }),
                self::approach('map + Arr::except', function () {
                    $profiles = Profile::orderBy('id')->limit(3)->get();

                    return $profiles->map(fn (Profile $profile) => Arr::except(
                        [...$profile->toArray(), 'phone' => $profile->phone],
                        ['bio', 'avatar_path', 'created_at', 'updated_at'],
                    ))->all();
                }),
            ]);

        $this->compare('eloquent-collection', __('lab.collections.comparisons.map_type'),
            __('lab.collections.comparisons.map_type_d'),
            [
                self::approach(__('lab.collections.labels.map_models'), function () {
                    $books = Book::orderBy('id')->limit(5)->get();
                    $mapped = $books->map(fn (Book $book) => $book->setAttribute('title', Str::upper($book->title)));

                    return ['before' => $books::class, 'after' => $mapped::class];
                }),
                self::approach(__('lab.collections.labels.map_strings'), function () {
                    $books = Book::orderBy('id')->limit(5)->get();
                    $mapped = $books->map(fn (Book $book) => $book->title);

                    return ['before' => $books::class, 'after' => $mapped::class];
                }),
            ]);
    }

    private function relationQuery(): void
    {
        $this->compare('relation-query', __('lab.collections.comparisons.count_condition'),
            __('lab.collections.comparisons.count_condition_d'),
            [
                self::approach('reviews()->where()->count()', function () {
                    $book = Book::find($this->busiestBookId);

                    return $book->reviews()->where('rating', 5)->count();
                }),
                self::approach('reviews->where()->count()', function () {
                    $book = Book::find($this->busiestBookId)->load('reviews');

                    return $book->reviews->where('rating.value', 5)->count();
                }),
            ]);

        $this->compare('relation-query', __('lab.collections.comparisons.exists_count'),
            __('lab.collections.comparisons.exists_count_d'),
            [
                self::approach('exists()', function () {
                    $book = Book::find($this->busiestBookId);

                    return $book->reviews()->where('rating', 1)->exists();
                }),
                self::approach('count() > 0', function () {
                    $book = Book::find($this->busiestBookId);

                    return $book->reviews()->where('rating', 1)->count() > 0;
                }),
            ]);

        $this->compare('relation-query', __('lab.collections.comparisons.first_where'),
            __('lab.collections.comparisons.first_where_d'),
            [
                self::approach('reviews()->firstWhere()', function () {
                    $book = Book::find($this->busiestBookId);

                    return $book->reviews()->firstWhere('rating', 5)?->only(['id', 'rating']);
                }),
                self::approach('reviews->where()->first()', function () {
                    $book = Book::find($this->busiestBookId)->load('reviews');

                    return $book->reviews->where('rating.value', 5)->first()?->only(['id', 'rating']);
                }),
            ]);
    }

    private function aggregates(): void
    {
        $this->compare('aggregates', __('lab.collections.comparisons.avg_sum_max'),
            __('lab.collections.comparisons.avg_sum_max_d'),
            [
                self::approach(__('lab.collections.labels.sql'), function () {
                    return (array) Book::toBase()
                        ->selectRaw('avg(price) as avg_price, sum(price) as total_price, max(price) as max_price')
                        ->first();
                }),
                self::approach(__('lab.collections.labels.collection'), function () {
                    $cents = Book::get(['price'])->map(fn (Book $book) => $book->price->cents);

                    return [
                        'avg_price' => $cents->avg(),
                        'total_price' => $cents->sum(),
                        'max_price' => $cents->max(),
                    ];
                }),
            ], heavy: true);

        $this->compare('aggregates', __('lab.collections.comparisons.count_by_format'),
            __('lab.collections.comparisons.count_by_format_d'),
            [
                self::approach(__('lab.collections.labels.sql_group_by'), function () {
                    return Book::toBase()
                        ->select('format')
                        ->selectRaw('count(*) as total')
                        ->groupBy('format')
                        ->orderBy('format')
                        ->pluck('total', 'format');
                }),
                self::approach(__('lab.collections.labels.collection_group_by'), function () {
                    return Book::get(['format'])
                        ->groupBy(fn (Book $book) => $book->format->value)
                        ->map->count()
                        ->sortKeys();
                }),
            ], heavy: true);

        $this->compare('aggregates', __('lab.collections.comparisons.pluck'),
            __('lab.collections.comparisons.pluck_d'),
            [
                self::approach('Book::pluck()', function () {
                    return Book::pluck('title', 'id')->take(3);
                }),
                self::approach('Book::get()->pluck()', function () {
                    return Book::get()->pluck('title', 'id')->take(3);
                }),
            ], heavy: true);
    }

    private function largeVolumes(): void
    {
        $this->compare('large-volumes', __('lab.collections.comparisons.large'),
            __('lab.collections.comparisons.large_d'),
            [
                self::approach(__('lab.collections.labels.baseline'), function () {
                    return Book::get()->where('format', BookFormat::Ebook)->sum('price.cents');
                }),
                self::approach('chunk(1000)', function () {
                    $sum = 0;
                    Book::chunk(1000, function ($books) use (&$sum) {
                        $sum += $books->where('format', BookFormat::Ebook)->sum('price.cents');
                    });

                    return $sum;
                }),
                self::approach('chunkById(1000)', function () {
                    $sum = 0;
                    Book::chunkById(1000, function ($books) use (&$sum) {
                        $sum += $books->where('format', BookFormat::Ebook)->sum('price.cents');
                    });

                    return $sum;
                }),
                self::approach('lazy(1000)', function () {
                    $sum = 0;
                    foreach (Book::lazy(1000) as $book) {
                        $sum += $book->format === BookFormat::Ebook ? $book->price->cents : 0;
                    }

                    return $sum;
                }),
                self::approach('lazyById(1000)', function () {
                    $sum = 0;
                    foreach (Book::lazyById(1000) as $book) {
                        $sum += $book->format === BookFormat::Ebook ? $book->price->cents : 0;
                    }

                    return $sum;
                }),
                self::approach('cursor()', function () {
                    $sum = 0;
                    foreach (Book::cursor() as $book) {
                        $sum += $book->format === BookFormat::Ebook ? $book->price->cents : 0;
                    }

                    return $sum;
                }),
                self::approach(__('lab.collections.labels.pipeline'), function () {
                    return Book::cursor()
                        ->filter(fn (Book $book) => $book->format === BookFormat::Ebook)
                        ->map(fn (Book $book) => $book->title)
                        ->take(10)
                        ->all();
                }, __('lab.collections.pipeline_note')),
            ], heavy: true);
    }

    private function chunkPitfall(): void
    {
        $this->compare('chunk-pitfall', __('lab.collections.comparisons.chunk_pitfall'),
            __('lab.collections.comparisons.chunk_pitfall_d'),
            [
                self::approach('chunk(100)', function () {
                    $before = Review::withoutGlobalScope(ApprovedScope::class)->where('is_approved', false)->count();

                    $chunks = 0;
                    Review::withoutGlobalScope(ApprovedScope::class)->where('is_approved', false)->chunk(100, function ($reviews) use (&$chunks) {
                        $reviews->toQuery()->update(['is_approved' => true]);
                        $chunks++;
                    });

                    return ['unapproved_before' => $before, 'chunks' => $chunks, 'unapproved_after' => Review::withoutGlobalScope(ApprovedScope::class)->where('is_approved', false)->count()];
                }),
                self::approach('chunkById(100)', function () {
                    $before = Review::withoutGlobalScope(ApprovedScope::class)->where('is_approved', false)->count();

                    $chunks = 0;
                    Review::withoutGlobalScope(ApprovedScope::class)->where('is_approved', false)->chunkById(100, function ($reviews) use (&$chunks) {
                        $reviews->toQuery()->update(['is_approved' => true]);
                        $chunks++;
                    });

                    return ['unapproved_before' => $before, 'chunks' => $chunks, 'unapproved_after' => Review::withoutGlobalScope(ApprovedScope::class)->where('is_approved', false)->count()];
                }),
            ], writes: true, heavy: true);
    }

    private function massUpdate(): void
    {
        $this->compare('mass-update', __('lab.collections.comparisons.mass_update'),
            __('lab.collections.comparisons.mass_update_d'),
            [
                self::approach('$books->toQuery()->increment()', function () {
                    $books = Book::orderBy('id')->limit(200)->get();
                    $before = $books->pluck('updated_at', 'id');

                    $books->toQuery()->increment('price', 100);

                    return $this->updatedAtChanges($before);
                }),
                self::approach('DB::table()->increment()', function () {
                    $books = Book::orderBy('id')->limit(200)->get();
                    $before = $books->pluck('updated_at', 'id');

                    DB::table('books')->whereIn('id', $books->modelKeys())->increment('price', 100);

                    return $this->updatedAtChanges($before);
                }),
                self::approach('each->save()', function () {
                    $books = Book::orderBy('id')->limit(200)->get();
                    $before = $books->pluck('updated_at', 'id');

                    $books->each(function (Book $book) {
                        $book->price = $book->price->add(Money::fromUah(1));
                        $book->save();
                    });

                    return $this->updatedAtChanges($before);
                }),
            ], writes: true);
    }

    /**
     * @param  Collection<int, mixed>  $before  id => updated_at
     * @return array{rows: int, updated_at_changed: int}
     */
    private function updatedAtChanges(Collection $before): array
    {
        $after = Book::whereKey($before->keys())->pluck('updated_at', 'id');

        return [
            'rows' => $after->count(),
            'updated_at_changed' => $after->filter(fn ($value, $id) => (string) $value !== (string) $before[$id])->count(),
        ];
    }

    private function subqueries(): void
    {
        $this->compare('subqueries', __('lab.collections.comparisons.latest_review'),
            __('lab.collections.comparisons.latest_review_d'),
            [
                self::approach('addSelect(subquery)', function () {
                    return Book::addSelect([
                        'latest_review_at' => Review::select('created_at')
                            ->whereColumn('reviews.book_id', 'books.id')
                            ->latest()
                            ->limit(1),
                    ])->orderBy('id')->limit(20)->get()->pluck('latest_review_at', 'id');
                }),
                self::approach("with('latestReview')", function () {
                    return Book::with('latestReview')->orderBy('id')->limit(20)->get()
                        ->mapWithKeys(fn (Book $book) => [$book->id => $book->latestReview?->created_at]);
                }),
            ]);

        $this->compare('subqueries', __('lab.collections.comparisons.where_exists'),
            __('lab.collections.comparisons.where_exists_d'),
            [
                self::approach('whereExists()', function () {
                    return Book::whereExists(fn (QueryBuilder $query) => $query
                        ->select(DB::raw(1))
                        ->from('reviews')
                        ->whereColumn('reviews.book_id', 'books.id')
                        ->where('reviews.rating', 5)
                        ->where('reviews.is_approved', true))->count();
                }),
                self::approach('whereHas()', function () {
                    // reviews() is approved-only through Review's global ApprovedScope.
                    return Book::whereHas('reviews', fn ($query) => $query->where('rating', 5))->count();
                }),
            ]);
    }

    private function report(): void
    {
        $this->compare('report', __('lab.collections.comparisons.stock_report'),
            __('lab.collections.comparisons.stock_report_d'),
            [
                self::approach(__('lab.collections.labels.report_sql'), function () {
                    $rows = (new StockByWarehouseGenreReport($this->reportWarehouseId))->rows();

                    return ['groups' => $rows->count(), 'quantity' => $rows->sum('quantity'), 'value_cents' => $rows->sum('value_cents'), 'first' => $rows->first()];
                }),
                self::approach(__('lab.collections.labels.report_php'), function () {
                    $genres = Genre::get(['id', 'name', 'parent_id'])->keyBy('id');

                    $rows = Stock::query()
                        ->when($this->reportWarehouseId, fn ($query, $id) => $query->where('warehouse_id', $id))
                        ->whereHas('book')
                        ->with(['warehouse:id,name', 'book:id,genre_id,price'])
                        ->get()
                        ->groupBy(function (Stock $stock) use ($genres) {
                            $genre = $genres[$stock->book->genre_id];

                            return $stock->warehouse->name.' / '.$genres[$genre->parent_id ?? $genre->id]->name;
                        })
                        ->map(fn (Collection $stocks) => [
                            'quantity' => $stocks->sum('quantity'),
                            'reserved' => $stocks->sum('reserved'),
                            'books' => $stocks->pluck('book_id')->unique()->count(),
                            'value_cents' => $stocks->sum(fn (Stock $stock) => $stock->book->price->multiply($stock->quantity)->cents),
                        ])
                        ->sortKeys();

                    return ['groups' => $rows->count(), 'quantity' => $rows->sum('quantity'), 'value_cents' => $rows->sum('value_cents'), 'first' => $rows->first()];
                }),
            ], heavy: true);
    }
}
