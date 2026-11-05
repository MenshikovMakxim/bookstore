<?php

namespace App\Lab;

use App\Enums\ImageType;
use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Image;
use App\Models\Review;
use App\Models\Scopes\ApprovedScope;
use App\Models\StockMovement;
use App\Models\Tag;
use App\Models\User;
use App\Models\Warehouse;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Registry of runnable Eloquent relationship examples for /lab/relations.
 *
 * Every example loads relations explicitly (with/load/relation query) — strict
 * mode is on, so lazy loading would throw. Write examples run inside a
 * transaction that is always rolled back.
 */
final class RelationExamples
{
    /**
     * @var list<Example>
     */
    private array $examples = [];

    public function __construct()
    {
        $this->relations();
        $this->querying();
        $this->pivots();
        $this->writing();
    }

    /**
     * @return list<Example>
     */
    public function all(): array
    {
        return $this->examples;
    }

    /**
     * Run every example and collect code, result and executed SQL.
     *
     * @return list<array{section: string, title: string, type: string, code: string, writes: bool, result: mixed, error: string|null, skipped: string|null, queries: list<string>}>
     */
    public function run(): array
    {
        $samples = (new Samples)->load();

        return array_map(fn (Example $example) => $this->runOne($example, $samples), $this->examples);
    }

    /**
     * @return array{section: string, title: string, type: string, code: string, writes: bool, result: mixed, error: string|null, skipped: string|null, queries: list<string>}
     */
    private function runOne(Example $example, Samples $samples): array
    {
        $result = null;
        $error = null;
        $skipped = null;

        $connection = DB::connection();
        $connection->flushQueryLog();
        $connection->enableQueryLog();

        if ($example->writes) {
            $connection->beginTransaction();
        }

        try {
            $result = Presenter::present(($example->run)($samples));
        } catch (MissingSample $e) {
            $skipped = $e->getMessage();
        } catch (Throwable $e) {
            $error = class_basename($e).': '.$e->getMessage();
        } finally {
            if ($example->writes) {
                $connection->rollBack();
            }

            $queries = array_map(
                fn (array $query) => $connection->getQueryGrammar()
                    ->substituteBindingsIntoRawSql($query['query'], $connection->prepareBindings($query['bindings'])),
                $connection->getQueryLog(),
            );

            $connection->disableQueryLog();
            $connection->flushQueryLog();
        }

        return [
            'section' => $example->section,
            'title' => $example->title,
            'type' => $example->type,
            'code' => $example->code(),
            'writes' => $example->writes,
            'result' => $result,
            'error' => $error,
            'skipped' => $skipped,
            'queries' => $queries,
        ];
    }

    /**
     * @param  Closure(Samples): mixed  $run
     */
    private function add(string $section, string $title, string $type, Closure $run, bool $writes = false): void
    {
        $this->examples[] = new Example($section, $title, $type, $run, $writes);
    }

    private function relations(): void
    {
        $section = __('lab.relations.sections.types');

        $this->add($section, __('lab.relations.examples.user_profile'), 'hasOne', function (Samples $s) {
            return User::with('profile')->find($s->id('reader'));
        });

        $this->add($section, __('lab.relations.examples.review_user_book'), 'belongsTo', function (Samples $s) {
            return Review::with(['user:id,name', 'book:id,title'])->find($s->id('review'));
        });

        $this->add($section, __('lab.relations.examples.user_reviews'), 'hasMany', function (Samples $s) {
            return User::find($s->id('reader'))->reviews()->latest('id')->limit(3)->get();
        });

        $this->add($section, __('lab.relations.examples.book_approved_reviews'), 'hasMany + global scope', function (Samples $s) {
            $book = Book::withCount([
                'reviews',
                'reviews as all_reviews_count' => fn ($query) => $query->withoutGlobalScope(ApprovedScope::class),
            ])->find($s->id('book'));

            return $book->only(['id', 'title', 'reviews_count', 'all_reviews_count']);
        });

        $this->add($section, __('lab.relations.examples.book_latest_review'), 'hasOne latestOfMany', function (Samples $s) {
            return Book::with('latestReview')->find($s->id('book'), ['id', 'title']);
        });

        $this->add($section, __('lab.relations.examples.book_best_review'), 'hasOne ofMany(rating, max)', function (Samples $s) {
            return Book::with('bestReview')->find($s->id('book'), ['id', 'title']);
        });

        $this->add($section, __('lab.relations.examples.genre_parent_children'), 'belongsTo + hasMany (self)', function (Samples $s) {
            $genre = Genre::with(['parent', 'children:id,name,parent_id'])->find($s->id('genreWithChildren'));
            $child = $genre->children->first()->load('parent:id,name');

            return ['genre' => $genre, 'child_with_parent' => $child];
        });

        $this->add($section, __('lab.relations.examples.genre_books'), 'hasMany', function (Samples $s) {
            return Genre::find($s->id('genreWithChildren'))->books()->orderBy('id')->limit(3)->get(['id', 'title', 'genre_id']);
        });

        $this->add($section, __('lab.relations.examples.user_warehouse'), 'belongsTo', function (Samples $s) {
            return User::with('warehouse')->find($s->id('manager'), ['id', 'name', 'role', 'warehouse_id']);
        });

        $this->add($section, __('lab.relations.examples.warehouse_manager'), 'hasOne + where', function (Samples $s) {
            return Warehouse::with('manager:id,name,role,warehouse_id')->find($s->id('warehouse'));
        });

        $this->add($section, __('lab.relations.examples.warehouse_manager_profile'), 'hasOneThrough', function (Samples $s) {
            return Warehouse::with('managerProfile')->find($s->id('warehouse'));
        });

        $this->add($section, __('lab.relations.examples.genre_reviews'), 'hasManyThrough', function (Samples $s) {
            $genre = Genre::find($s->id('genreWithChildren'));

            return [
                'genre' => $genre->name,
                'reviews_total' => $genre->reviews()->count(),
                'latest' => $genre->reviews()->latest('reviews.id')->limit(2)->get(['reviews.id', 'rating']),
            ];
        });

        $this->add($section, __('lab.relations.examples.book_authors'), 'belongsToMany + withPivot', function (Samples $s) {
            return Book::with('authors:id,first_name,last_name')->find($s->id('bookWithTranslator'), ['id', 'title']);
        });

        $this->add($section, __('lab.relations.examples.book_translators'), 'belongsToMany + wherePivot', function (Samples $s) {
            return [
                'book_translators' => Book::find($s->id('bookWithTranslator'))->translators()->get(['authors.id', 'last_name']),
                'author_translated_books' => Author::find($s->id('translator'))->translatedBooks()->get(['books.id', 'title']),
            ];
        });

        $this->add($section, __('lab.relations.examples.book_genres'), 'belongsToMany', function (Samples $s) {
            $book = Book::with('genres:id,name')->find($s->id('book'), ['id', 'title']);

            return [
                'book' => $book,
                'secondary_books_of_first_genre' => $book->genres->first()->secondaryBooks()->limit(3)->get(['books.id', 'title']),
            ];
        });

        $this->add($section, __('lab.relations.examples.warehouse_books'), 'belongsToMany + using()', function (Samples $s) {
            return Warehouse::find($s->id('warehouse'))->books()->orderBy('books.id')->limit(3)->get(['books.id', 'title']);
        });

        $this->add($section, __('lab.relations.examples.book_stocks'), 'hasMany (pivot model as model)', function (Samples $s) {
            return Book::with('stocks.warehouse:id,name')->find($s->id('book'), ['id', 'title']);
        });

        $this->add($section, __('lab.relations.examples.cover_photo'), 'morphOne + where', function (Samples $s) {
            return [
                'book' => Book::with('cover')->find($s->id('book'), ['id', 'title']),
                'author' => Author::with('photo')->find($s->id('authorWithPhoto'), ['id', 'last_name']),
            ];
        });

        $this->add($section, __('lab.relations.examples.book_images'), 'morphMany', function (Samples $s) {
            return Book::find($s->id('book'))->images()->get();
        });

        $this->add($section, __('lab.relations.examples.image_imageable'), 'morphTo', function (Samples $s) {
            return Image::with('imageable')->find($s->id('authorImage'));
        });

        $this->add($section, __('lab.relations.examples.book_tags'), 'morphToMany', function (Samples $s) {
            return Book::with('tags:id,name')->find($s->id('book'), ['id', 'title']);
        });

        $this->add($section, __('lab.relations.examples.tag_books_authors'), 'morphedByMany', function (Samples $s) {
            $tag = Tag::withCount(['books', 'authors'])
                ->with(['books' => fn ($q) => $q->limit(2), 'authors' => fn ($q) => $q->limit(2)])
                ->find($s->id('tag'));

            return $tag;
        });

        $this->add($section, __('lab.relations.examples.movement_relations'), 'belongsTo (×3)', function (Samples $s) {
            return StockMovement::with(['warehouse:id,name', 'book:id,title', 'user:id,name'])->find($s->id('movement'));
        });
    }

    private function querying(): void
    {
        $section = __('lab.relations.sections.querying');

        $this->add($section, __('lab.relations.examples.has'), 'has', function (Samples $s) {
            return Book::has('reviews', '>=', 8)->count();
        });

        $this->add($section, __('lab.relations.examples.where_has'), 'whereHas', function (Samples $s) {
            return Book::whereHas('authors', fn ($q) => $q->where('country', 'US'))->count();
        });

        $this->add($section, __('lab.relations.examples.doesnt_have'), 'doesntHave', function (Samples $s) {
            return Book::doesntHave('cover')->count();
        });

        $this->add($section, __('lab.relations.examples.where_relation'), 'whereRelation', function (Samples $s) {
            return Book::whereRelation('reviews', 'rating', 5)->count();
        });

        $this->add($section, __('lab.relations.examples.with_count'), 'withCount', function (Samples $s) {
            return Book::withCount(['reviews', 'tags'])->orderBy('id')->limit(3)->get(['id', 'title']);
        });

        $this->add($section, __('lab.relations.examples.with_count_having'), 'withCount + having', function (Samples $s) {
            // Same result as has('reviews', '>=', 8), but the count is selected as a
            // column and filtered in HAVING instead of a WHERE EXISTS subquery.
            return Book::withCount('reviews')
                ->having('reviews_count', '>=', 8)
                ->orderBy('id')
                ->limit(3)
                ->get(['id', 'title']);
        });

        $this->add($section, __('lab.relations.examples.with_avg'), 'withAvg', function (Samples $s) {
            return Book::withAvg('reviews', 'rating')->withCount('reviews')
                ->having('reviews_count', '>=', 5)
                ->orderByDesc('reviews_avg_rating')->limit(3)->get(['id', 'title']);
        });

        $this->add($section, __('lab.relations.examples.with_sum'), 'withSum', function (Samples $s) {
            return Book::withSum('stocks', 'quantity')->orderBy('id')->limit(3)->get(['id', 'title']);
        });

        $this->add($section, __('lab.relations.examples.with_exists'), 'withExists', function (Samples $s) {
            return Book::withExists('cover')->orderBy('id')->limit(3)->get(['id', 'title']);
        });
    }

    private function pivots(): void
    {
        $section = __('lab.relations.sections.pivot');

        $this->add($section, __('lab.relations.examples.pivot_credit'), 'pivot accessor as()', function (Samples $s) {
            $book = Book::with('authors')->find($s->id('bookWithTranslator'));

            return $book->authors->map(fn (Author $author) => [
                'author' => $author->full_name,
                'role' => $author->credit->role,
                'sort_order' => $author->credit->sort_order,
            ]);
        });

        $this->add($section, __('lab.relations.examples.pivot_stock'), 'custom Pivot + accessor', function (Samples $s) {
            $warehouse = Warehouse::with(['books' => fn ($q) => $q->orderBy('books.id')->limit(1)])->find($s->id('warehouse'));
            $stock = $warehouse->books->first()->stock;

            return [
                'pivot_class' => $stock::class,
                'id' => $stock->id,
                'quantity' => $stock->quantity,
                'reserved' => $stock->reserved,
                'available' => $stock->available,
            ];
        });
    }

    private function writing(): void
    {
        $section = __('lab.relations.sections.writing');

        $this->add($section, __('lab.relations.examples.attach'), 'attach', function (Samples $s) {
            $book = Book::find($s->id('book'));
            $tag = Tag::whereDoesntHave('books', fn ($q) => $q->whereKey($book->id))->orderBy('id')->first();

            $before = $book->tags()->count();
            $book->tags()->attach($tag);

            return ['tags_before' => $before, 'tags_after' => $book->tags()->count()];
        }, writes: true);

        $this->add($section, __('lab.relations.examples.detach'), 'detach', function (Samples $s) {
            $book = Book::find($s->id('book'));

            $before = $book->tags()->count();
            $detached = $book->tags()->detach($book->tags()->orderBy('tags.id')->first());

            return ['detached_rows' => $detached, 'tags_before' => $before, 'tags_after' => $book->tags()->count()];
        }, writes: true);

        $this->add($section, __('lab.relations.examples.sync'), 'sync', function (Samples $s) {
            $book = Book::find($s->id('book'));
            $genreIds = Genre::whereNull('parent_id')->where('id', '!=', $book->genre_id)->orderBy('id')->limit(2)->pluck('id');

            return $book->genres()->sync($genreIds);
        }, writes: true);

        $this->add($section, __('lab.relations.examples.sync_without_detaching'), 'syncWithoutDetaching', function (Samples $s) {
            $book = Book::find($s->id('book'));
            $genreId = Genre::whereDoesntHave('secondaryBooks', fn ($q) => $q->whereKey($book->id))
                ->where('id', '!=', $book->genre_id)->orderBy('id')->value('id');

            return $book->genres()->syncWithoutDetaching([$genreId]);
        }, writes: true);

        $this->add($section, __('lab.relations.examples.toggle'), 'toggle', function (Samples $s) {
            $book = Book::find($s->id('book'));
            $existing = $book->tags()->orderBy('tags.id')->value('tags.id');
            $new = Tag::whereDoesntHave('books', fn ($q) => $q->whereKey($book->id))->orderBy('id')->value('id');

            return $book->tags()->toggle([$existing, $new]);
        }, writes: true);

        $this->add($section, __('lab.relations.examples.update_existing_pivot'), 'updateExistingPivot', function (Samples $s) {
            $warehouse = Warehouse::find($s->id('warehouse'));
            $book = $warehouse->books()->orderBy('books.id')->first();

            $warehouse->books()->updateExistingPivot($book->id, ['quantity' => $book->stock->quantity + 10]);

            return [
                'quantity_before' => $book->stock->quantity,
                'quantity_after' => $warehouse->books()->find($book->id)->stock->quantity,
            ];
        }, writes: true);

        $this->add($section, __('lab.relations.examples.associate'), 'associate / dissociate', function (Samples $s) {
            $review = Review::find($s->id('review'));
            $before = $review->book_id;
            $review->book()->associate(Book::find($s->id('otherBook')))->save();

            $manager = User::find($s->id('manager'));
            $manager->warehouse()->dissociate()->save();

            return [
                'review_book_id' => ['before' => $before, 'after' => $review->book_id],
                'manager_warehouse_id' => $manager->warehouse_id,
            ];
        }, writes: true);

        $this->add($section, __('lab.relations.examples.save_many'), 'saveMany / createMany', function (Samples $s) {
            $book = Book::find($s->id('book'));

            $images = $book->images()->saveMany([
                new Image(['path' => 'gallery/one.jpg', 'type' => ImageType::Gallery, 'sort_order' => 1]),
                new Image(['path' => 'gallery/two.jpg', 'type' => ImageType::Gallery, 'sort_order' => 2]),
            ]);

            $userIds = User::whereDoesntHave('reviews', fn ($q) => $q->where('book_id', $book->id))
                ->orderBy('id')->limit(2)->pluck('id');
            $reviews = $book->reviews()->createMany($userIds->map(fn (int $userId) => [
                'user_id' => $userId,
                'rating' => 5,
                'body' => 'Created by the relations lab.',
            ]));

            return ['images' => collect($images), 'reviews' => $reviews];
        }, writes: true);
    }
}
