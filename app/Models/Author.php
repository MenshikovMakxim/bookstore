<?php

namespace App\Models;

use App\Enums\AuthorRole;
use App\Enums\ImageType;
use App\Models\Concerns\HasSlug;
use Database\Factories\AuthorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

#[Fillable(['first_name', 'last_name', 'slug', 'birth_date', 'country'])]
class Author extends Model
{
    /** @use HasFactory<AuthorFactory> */
    use HasFactory, HasSlug;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Carbon at midnight; serialized as "1970-01-31" thanks to the date format.
            'birth_date' => 'date:Y-m-d',
        ];
    }

    /**
     * $author->full_name — read-only accessor (modern Attribute syntax).
     * Legacy equivalent: public function getFullNameAttribute(): string { ... }
     *
     * @return Attribute<string, never>
     */
    protected function fullName(): Attribute
    {
        return Attribute::get(fn () => trim($this->first_name.' '.$this->last_name));
    }

    protected function slugSource(): string
    {
        return $this->first_name.' '.$this->last_name;
    }

    /**
     * All credits: as author, co-author or translator.
     *
     * @return BelongsToMany<Book, $this, Pivot, 'credit'>
     */
    public function books(): BelongsToMany
    {
        return $this->belongsToMany(Book::class)
            ->withPivot(['role', 'sort_order'])
            ->withTimestamps()
            ->as('credit');
    }

    /**
     * @return BelongsToMany<Book, $this, Pivot, 'credit'>
     */
    public function translatedBooks(): BelongsToMany
    {
        return $this->books()->wherePivot('role', AuthorRole::Translator->value);
    }

    /**
     * Other authors/co-authors credited on at least one of this author's books.
     *
     * There is no built-in self-referencing many-to-many "through a shared pivot"
     * relation: walk Author -> books -> authors, exclude translators and the author.
     *
     * @return Builder<Author>
     */
    public function coAuthors(): Builder
    {
        return $this->newQuery()
            ->whereKeyNot($this->getKey())
            ->whereHas('books', fn (Builder $books) => $books
                ->whereIn('author_book.role', [AuthorRole::Author->value, AuthorRole::CoAuthor->value])
                ->whereHas('authors', fn (Builder $authors) => $authors
                    ->whereKey($this->getKey())
                    ->whereIn('author_book.role', [AuthorRole::Author->value, AuthorRole::CoAuthor->value])));
    }

    /**
     * @return MorphOne<Image, $this>
     */
    public function photo(): MorphOne
    {
        return $this->morphOne(Image::class, 'imageable')->where('type', ImageType::Photo);
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
