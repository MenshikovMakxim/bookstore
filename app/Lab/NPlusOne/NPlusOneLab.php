<?php

namespace App\Lab\NPlusOne;

use App\Lab\LazyLoading;
use App\Lab\Models\LabBook;
use App\Lab\Profile;
use App\Lab\QueryProfiler;
use App\Models\Author;
use App\Models\Book;
use App\Models\Image;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;
use Illuminate\View\ViewException;
use Throwable;

/**
 * Registry of N+1 variants for /lab/n-plus-one.
 *
 * Every variant gets the same 50 book ids and renders the same partial
 * (lab/n-plus-one/_card), so the only difference is how data is loaded.
 */
final class NPlusOneLab
{
    public const SAMPLE_SIZE = 50;

    /**
     * @var array<string, Variant>
     */
    private array $variants = [];

    /**
     * @var list<int>|null
     */
    private ?array $bookIds = null;

    public function __construct()
    {
        $this->define();
    }

    /**
     * @return array<string, Variant>
     */
    public function variants(): array
    {
        return $this->variants;
    }

    public function variant(string $key): ?Variant
    {
        return $this->variants[$key] ?? null;
    }

    /**
     * The fixed sample: first 50 books by id (resolved once, outside profiling).
     *
     * @return list<int>
     */
    public function bookIds(): array
    {
        return $this->bookIds ??= Book::orderBy('id')->limit(self::SAMPLE_SIZE)->pluck('id')->all();
    }

    public function profile(Variant $variant): Profile
    {
        $ids = $this->bookIds();
        $callback = fn () => ($variant->run)($ids);

        if ($variant->allowMissingAttributes) {
            $inner = $callback;
            $callback = fn () => LazyLoading::allowMissingAttributesDuring($inner);
        }

        if ($variant->lazy) {
            $inner = $callback;
            $callback = fn () => LazyLoading::allowDuring($inner);
        }

        return QueryProfiler::run($callback);
    }

    /**
     * @param  Collection<int, Book>  $books
     * @param  string|null  $feed  relation holding the reviews to list (feed variants)
     */
    private function render(Collection $books, ?string $feed = null, bool $showReviewBook = false): string
    {
        return view('lab.n-plus-one._books', compact('books', 'feed', 'showReviewBook'))->render();
    }

    /**
     * @param  Collection<int, Image>  $images
     */
    private function renderImages(Collection $images): string
    {
        return view('lab.n-plus-one._images', compact('images'))->render();
    }

    private function exception(Throwable $e): string
    {
        // Unwrap ViewException(s) down to the original exception.
        while ($e instanceof ViewException && $e->getPrevious() !== null) {
            $e = $e->getPrevious();
        }

        return '<p class="text-red-700"><strong>'.e(class_basename($e)).':</strong> '.e($e->getMessage()).'</p>';
    }

    /**
     * Book images and photos of the sample books' authors.
     *
     * @param  list<int>  $ids
     * @return Builder<Image>
     */
    private function imagesQuery(array $ids): Builder
    {
        return Image::query()
            ->where(fn ($q) => $q->where('imageable_type', 'book')->whereIn('imageable_id', $ids))
            ->orWhere(fn ($q) => $q->where('imageable_type', 'author')->whereIn(
                'imageable_id',
                DB::table('author_book')->select('author_id')->whereIn('book_id', $ids),
            ))
            ->orderBy('id');
    }

    private function add(string $key, string $label, string $title, string $description, Closure $run, bool $lazy = false, bool $allowMissingAttributes = false, bool $ranked = true): void
    {
        $this->variants[$key] = new Variant($key, $label, $title, $description, $run, $lazy, $allowMissingAttributes, $ranked);
    }

    private function define(): void
    {
        $this->add('bad', 'a', __('lab.n_plus_one.variants.bad'),
            __('lab.n_plus_one.variants.bad_d'),
            function (array $ids) {
                $books = Book::whereIn('id', $ids)->get();

                return $this->render($books);
            }, lazy: true);

        $this->add('strict', 'b', __('lab.n_plus_one.variants.strict'),
            __('lab.n_plus_one.variants.strict_d'),
            function (array $ids) {
                try {
                    $books = Book::whereIn('id', $ids)->get();

                    return $this->render($books);
                } catch (ViewException $e) {
                    // Blade wraps exceptions thrown while rendering (once per nested view).
                    return $this->exception($e);
                }
            }, ranked: false);

        $this->add('with-basic', 'c', __('lab.n_plus_one.variants.with-basic'),
            __('lab.n_plus_one.variants.with-basic_d'),
            function (array $ids) {
                $books = Book::whereIn('id', $ids)->with(['authors', 'genre'])->get();

                return $this->render($books);
            }, lazy: true);

        $this->add('with-full', 'd', __('lab.n_plus_one.variants.with-full'),
            __('lab.n_plus_one.variants.with-full_d'),
            function (array $ids) {
                $books = Book::whereIn('id', $ids)
                    ->with(['authors', 'genre', 'cover', 'tags:id,name,slug'])
                    ->withCount('reviews')
                    ->get();

                return $this->render($books);
            });

        $this->add('count-in-php', 'e', __('lab.n_plus_one.variants.count-in-php'),
            __('lab.n_plus_one.variants.count-in-php_d'),
            function (array $ids) {
                $books = Book::whereIn('id', $ids)
                    ->with(['authors', 'genre', 'cover', 'tags:id,name,slug', 'reviews'])
                    ->get();

                // _card falls back to $book->reviews->count() when there is no *_count attribute.
                return $this->render($books);
            });

        $this->add('feed-lazy', 'f', __('lab.n_plus_one.variants.feed-lazy'),
            __('lab.n_plus_one.variants.feed-lazy_d'),
            function (array $ids) {
                $books = Book::whereIn('id', $ids)->get();

                return $this->render($books, feed: 'reviews');
            }, lazy: true);

        $this->add('feed-nested', 'g', __('lab.n_plus_one.variants.feed-nested'),
            __('lab.n_plus_one.variants.feed-nested_d'),
            function (array $ids) {
                $books = Book::whereIn('id', $ids)
                    ->with(['authors', 'genre', 'cover', 'tags:id,name,slug', 'reviews.user.profile'])
                    ->withCount('reviews')
                    ->get();

                return $this->render($books, feed: 'reviews');
            });

        $this->add('feed-limited', 'h', __('lab.n_plus_one.variants.feed-limited'),
            __('lab.n_plus_one.variants.feed-limited_d'),
            function (array $ids) {
                $books = Book::whereIn('id', $ids)
                    ->with([
                        'authors', 'genre', 'cover', 'tags:id,name,slug',
                        'reviews' => fn ($query) => $query->latest('id')->limit(3),
                        'reviews.user.profile',
                    ])
                    ->withCount('reviews')
                    ->get();

                return $this->render($books, feed: 'reviews');
            });

        $this->add('load-missing', 'i', __('lab.n_plus_one.variants.load-missing'),
            __('lab.n_plus_one.variants.load-missing_d'),
            function (array $ids) {
                $books = Book::whereIn('id', $ids)->get();

                $books->load(['authors', 'genre', 'cover', 'tags:id,name,slug'])->loadCount('reviews');
                $books->loadMissing(['authors', 'genre']); // already loaded: no queries

                return $this->render($books);
            });

        $this->add('images-lazy', 'j1', __('lab.n_plus_one.variants.images-lazy'),
            __('lab.n_plus_one.variants.images-lazy_d'),
            function (array $ids) {
                $images = $this->imagesQuery($ids)->with('imageable')->get();

                return $this->renderImages($images);
            }, lazy: true);

        $this->add('images-morph-with', 'j2', __('lab.n_plus_one.variants.images-morph-with'),
            __('lab.n_plus_one.variants.images-morph-with_d'),
            function (array $ids) {
                $images = $this->imagesQuery($ids)
                    ->with(['imageable' => fn (MorphTo $morphTo) => $morphTo->morphWith([
                        Book::class => ['genre'],
                        Author::class => ['books'],
                    ])])
                    ->get();

                return $this->renderImages($images);
            });

        $this->add('over-eager', 'k1', __('lab.n_plus_one.variants.over-eager'),
            __('lab.n_plus_one.variants.over-eager_d'),
            function (array $ids) {
                $books = Book::whereIn('id', $ids)
                    ->with(['authors', 'genre', 'cover', 'images', 'tags', 'genres', 'warehouses', 'stocks', 'reviews.user.profile'])
                    ->withCount('reviews')
                    ->get();

                return $this->render($books);
            });

        $this->add('select-columns', 'k2', __('lab.n_plus_one.variants.select-columns'),
            __('lab.n_plus_one.variants.select-columns_d'),
            function (array $ids) {
                $books = Book::whereIn('id', $ids)
                    ->select(['id', 'title', 'genre_id'])
                    ->with([
                        'authors:id,first_name,last_name',
                        'genre:id,name',
                        'cover:id,imageable_type,imageable_id,path',
                        'tags:id,name,slug',
                    ])
                    ->withCount('reviews')
                    ->get();

                return $this->render($books);
            });

        $this->add('select-missing-fk', 'k3', __('lab.n_plus_one.variants.select-missing-fk'),
            __('lab.n_plus_one.variants.select-missing-fk_d'),
            function (array $ids) {
                $books = Book::whereIn('id', $ids)
                    ->select(['id', 'title']) // forgot genre_id!
                    ->with([
                        'authors:id,first_name,last_name',
                        'genre:id,name',
                        'cover:id,imageable_type,imageable_id,path',
                        'tags:id,name,slug',
                    ])
                    ->withCount('reviews')
                    ->get();

                return $this->render($books);
            }, allowMissingAttributes: true, ranked: false);

        $this->add('accessor-lazy', 'l1', __('lab.n_plus_one.variants.accessor-lazy'),
            __('lab.n_plus_one.variants.accessor-lazy_d'),
            function (array $ids) {
                $books = LabBook::whereIn('id', $ids)
                    ->with(['genre', 'cover'])
                    ->withCount('reviews')
                    ->get();

                return $this->render($books);
            }, lazy: true);

        $this->add('accessor-eager', 'l2', __('lab.n_plus_one.variants.accessor-eager'),
            __('lab.n_plus_one.variants.accessor-eager_d'),
            function (array $ids) {
                $books = LabBook::whereIn('id', $ids)
                    ->with(['authors', 'genre', 'cover', 'tags:id,name,slug'])
                    ->withCount('reviews')
                    ->get();

                return $this->render($books);
            });

        $this->add('inverse-lazy', 'm1', __('lab.n_plus_one.variants.inverse-lazy'),
            __('lab.n_plus_one.variants.inverse-lazy_d'),
            function (array $ids) {
                $books = LabBook::whereIn('id', $ids)
                    ->with(['authors', 'genre', 'cover', 'labReviews.user.profile'])
                    ->withCount('reviews')
                    ->get();

                return $this->render($books, feed: 'labReviews', showReviewBook: true);
            }, lazy: true);

        $this->add('inverse-chaperone', 'm2', __('lab.n_plus_one.variants.inverse-chaperone'),
            __('lab.n_plus_one.variants.inverse-chaperone_d'),
            function (array $ids) {
                $books = LabBook::whereIn('id', $ids)
                    ->with(['authors', 'genre', 'cover', 'tags:id,name,slug', 'labReviewsChaperoned.user.profile'])
                    ->withCount('reviews')
                    ->get();

                return $this->render($books, feed: 'labReviewsChaperoned', showReviewBook: true);
            });

        // Automatic eager loading exists since Laravel 12.8.
        if (method_exists(Collection::class, 'withRelationshipAutoloading')
            && method_exists(Model::class, 'automaticallyEagerLoadRelationships')) {
            $this->add('autoload', 'n', __('lab.n_plus_one.variants.autoload'),
                __('lab.n_plus_one.variants.autoload_d'),
                function (array $ids) {
                    $books = Book::whereIn('id', $ids)->get()->withRelationshipAutoloading();

                    return $this->render($books);
                });
        }
    }
}
