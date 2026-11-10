<?php

namespace App\Models;

use App\Enums\AuthorRole;
use App\Enums\BookFormat;
use App\Enums\ImageType;
use App\Models\Concerns\HasSlug;
use App\ValueObjects\Money;
use Database\Factories\BookFactory;
use Illuminate\Database\Eloquent\Attributes\Appends;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsCollection;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;

// Laravel 13: mass-assignable attributes declared with a PHP attribute.
#[Fillable([
    'title',
    'slug',
    'isbn',
    'price',
    'published_year',
    'format',
    'genre_id',
    'published_at',
    'meta',
])]
// Computed attributes added to toArray()/toJson() (accessor below).
#[Appends(['isbn_formatted'])]
class Book extends Model
{
    /** @use HasFactory<BookFactory> */
    use HasFactory, HasSlug, SoftDeletes;

    // Legacy syntax (Laravel <= 12), equivalent to the #[Fillable] attribute above:
    // protected $fillable = [
    //     'title',
    //     'slug',
    //     'isbn',
    //     'price',
    //     'published_year',
    //     'format',
    //     'genre_id',
    //     'published_at',
    //     'meta',
    // ];

    // Legacy syntax, equivalent to the #[Appends] attribute above:
    // protected $appends = ['isbn_formatted'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'format' => BookFormat::class,
            // Castable value object: Money::castUsing() names MoneyCast (int kopecks <=> Money).
            'price' => Money::class,
            // CarbonImmutable: $book->published_at->addDay() returns a copy instead of
            // silently changing the model attribute.
            'published_at' => 'immutable_datetime',
            // Collection instead of array: $book->meta->get('pages'), and in-place changes
            // ($book->meta->put(...)) are detected as dirty. Plain 'array' cannot do
            // $book->meta['x'] = 1 ("Indirect modification of overloaded property").
            'meta' => AsCollection::class,
        ];
    }

    protected function slugSource(): string
    {
        return $this->title;
    }

    /**
     * Mutator only: "978-617-7489-12-4" or "978 6177 489124" is stored as 9786177489124.
     *
     * @return Attribute<never, string|null>
     */
    protected function isbn(): Attribute
    {
        return Attribute::set(fn (string|int|null $value) => $value === null ? null : preg_replace('/\D+/', '', (string) $value));
    }

    /**
     * "9786177489124" => "978-6-1774-8912-4". Simplified fixed 3-1-4-4-1 split: real
     * hyphenation depends on registration-group ranges published by the ISBN agency.
     *
     * Reads $attributes (not $this->isbn), so a book selected without the isbn column
     * serializes isbn_formatted as null instead of throwing in strict mode ($appends!).
     *
     * shouldCache(): computed once per model instance — it runs for every book in
     * every toArray()/toJson(). Caveat: the cache is NOT cleared when isbn changes
     * on the same instance (see the lab and docs/06).
     *
     * @return Attribute<string|null, never>
     */
    protected function isbnFormatted(): Attribute
    {
        return Attribute::get(function (mixed $value, array $attributes) {
            $isbn = $attributes['isbn'] ?? null;

            return $isbn !== null && preg_match('/^\d{13}$/', $isbn)
                ? preg_replace('/^(\d{3})(\d)(\d{4})(\d{4})(\d)$/', '$1-$2-$3-$4-$5', $isbn)
                : $isbn;
        })->shouldCache();
    }

    /**
     * Everything <x-book-card> reads. Strict mode is on, so the card would throw
     * on any relation/aggregate that is not loaded here.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function withCardData(Builder $query): void
    {
        $query->with(['genre:id,name,slug', 'authors:id,first_name,last_name,slug', 'cover'])
            ->withCount('reviews')
            ->withAvg('reviews', 'rating')
            ->withSum('stocks', 'quantity');
    }

    /**
     * Book::published() — has a publication date that is not in the future.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    /**
     * At least one warehouse has unreserved copies.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function inStock(Builder $query): void
    {
        $query->whereHas('stocks', fn (Builder $stocks) => $stocks->whereColumn('quantity', '>', 'reserved'));
    }

    /**
     * Main or secondary genre is $genre (and, by default, any of its sub-genres).
     * Genre ids are a subquery, so a slug needs no extra query to resolve.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function ofGenre(Builder $query, Genre|string $genre, bool $withChildren = true): void
    {
        $root = $genre instanceof Genre ? [$genre->getKey()] : Genre::select('id')->where('slug', $genre);

        $ids = Genre::select('id')
            ->whereIn('id', $root)
            ->when($withChildren, fn (Builder $genres) => $genres->orWhereIn('parent_id', $root));

        $query->where(fn (Builder $books) => $books
            ->whereIn('genre_id', $ids)
            ->orWhereHas('genres', fn (Builder $genres) => $genres->whereIn('genres.id', $ids)));
    }

    /**
     * Credited in any role (author, co-author, translator).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function byAuthor(Builder $query, Author|string $author): void
    {
        $query->whereHas('authors', fn (Builder $authors) => $author instanceof Author
            ? $authors->whereKey($author->getKey())
            : $authors->where('slug', $author));
    }

    /**
     * Has the tag (any taggable type — here books only).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function tagged(Builder $query, Tag|string $tag): void
    {
        $query->whereHas('tags', fn (Builder $tags) => $tag instanceof Tag
            ? $tags->whereKey($tag->getKey())
            : $tags->where('slug', $tag));
    }

    /**
     * Title contains $term; a term of digits (dashes/spaces allowed) also matches
     * an ISBN prefix ("978-617" finds 978617...).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function search(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $isbn = preg_match('/^[\d\s-]+$/', $term) ? preg_replace('/\D+/', '', $term) : '';

        $query->where(fn (Builder $books) => $books
            ->where('title', 'like', "%{$term}%")
            ->when($isbn !== '', fn (Builder $books) => $books->orWhere('isbn', 'like', "{$isbn}%")));
    }

    /**
     * Catalog sort orders; unknown keys fall back to "newest".
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function sorted(Builder $query, string $key): void
    {
        match ($key) {
            'price_asc' => $query->orderBy('price'),
            'price_desc' => $query->orderByDesc('price'),
            // books.rating_avg: average of approved reviews, kept up to date by the queued
            // RecalculateBookRating job (stage 9). Sorting by a stored, indexable column
            // instead of the withAvg() subquery for every book. Books without reviews last.
            // Stage 5–8 version: ->orderByRaw('reviews_avg_rating is null')->orderByDesc('reviews_avg_rating')
            'rating' => $query->orderByRaw('rating_avg is null')->orderByDesc('rating_avg'),
            default => $query->orderByRaw('published_at is null')->orderByDesc('published_at'),
        };
    }

    /**
     * Main genre.
     *
     * @return BelongsTo<Genre, $this>
     */
    public function genre(): BelongsTo
    {
        return $this->belongsTo(Genre::class);
    }

    /**
     * Secondary genres (book_genre pivot, no extra columns).
     *
     * @return BelongsToMany<Genre, $this>
     */
    public function genres(): BelongsToMany
    {
        return $this->belongsToMany(Genre::class);
    }

    /**
     * All credits (authors, co-authors, translators) in display order.
     *
     * @return BelongsToMany<Author, $this, Pivot, 'credit'>
     */
    public function authors(): BelongsToMany
    {
        return $this->belongsToMany(Author::class)
            ->withPivot(['role', 'sort_order'])
            ->withTimestamps()
            ->orderByPivot('sort_order')
            ->as('credit');
    }

    /**
     * @return BelongsToMany<Author, $this, Pivot, 'credit'>
     */
    public function translators(): BelongsToMany
    {
        return $this->authors()->wherePivot('role', AuthorRole::Translator->value);
    }

    /**
     * @return BelongsToMany<Warehouse, $this, Stock, 'stock'>
     */
    public function warehouses(): BelongsToMany
    {
        return $this->belongsToMany(Warehouse::class, 'stocks')
            ->using(Stock::class)
            ->withPivot(['id', 'quantity', 'reserved', 'shelf_code'])
            ->withTimestamps()
            ->as('stock');
    }

    /**
     * @return HasMany<Stock, $this>
     */
    public function stocks(): HasMany
    {
        return $this->hasMany(Stock::class);
    }

    /**
     * Approved reviews only: Review has a global ApprovedScope. Pending ones:
     * $book->reviews()->withoutGlobalScope(ApprovedScope::class).
     *
     * @return HasMany<Review, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /**
     * One row out of many: the newest review (MAX(id)).
     *
     * @return HasOne<Review, $this>
     */
    public function latestReview(): HasOne
    {
        return $this->hasOne(Review::class)->latestOfMany();
    }

    /**
     * One row out of many: the highest rating (ties broken by MAX(id)).
     *
     * @return HasOne<Review, $this>
     */
    public function bestReview(): HasOne
    {
        return $this->hasOne(Review::class)->ofMany('rating', 'max');
    }

    /**
     * One row out of many: the lowest rating (ties broken by MIN(id)).
     *
     * @return HasOne<Review, $this>
     */
    public function lowestReview(): HasOne
    {
        return $this->hasOne(Review::class)->ofMany('rating', 'min');
    }

    /**
     * @return MorphOne<Image, $this>
     */
    public function cover(): MorphOne
    {
        return $this->morphOne(Image::class, 'imageable')->where('type', ImageType::Cover);
    }

    /**
     * @return MorphMany<Image, $this>
     */
    public function images(): MorphMany
    {
        return $this->morphMany(Image::class, 'imageable');
    }

    /**
     * @return MorphToMany<Tag, $this>
     */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }
}
