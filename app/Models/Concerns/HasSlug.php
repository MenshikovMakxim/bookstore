<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Fills `slug` from slugSource() when a model is created without one.
 *
 * Eloquent calls boot{TraitName}() once per model class, so this trait can register
 * its own model event listener — no observer, nothing in the model's booted().
 *
 * The slug is set on create only: it is part of public URLs (/books/{slug}), so
 * renaming a book must not break links and bookmarks.
 *
 * @mixin Model
 */
trait HasSlug
{
    /**
     * The text the slug is made from (title, name, ...).
     */
    abstract protected function slugSource(): string;

    public static function bootHasSlug(): void
    {
        static::creating(function (Model $model) {
            /** @var Model&HasSlug $model */
            if (blank($model->slug)) {
                $model->slug = $model->uniqueSlug(Str::slug($model->slugSource(), '-', 'uk'));
            }
        });
    }

    /**
     * "kobzar", then "kobzar-2", "kobzar-3", ... — the first one not taken.
     *
     * One query for all candidates: every slug equal to the base or starting with
     * "base-", then the free suffix is picked in PHP. Slugs are ASCII [a-z0-9-],
     * so the LIKE pattern needs no escaping, and the prefix match uses the index.
     */
    protected function uniqueSlug(string $base): string
    {
        $base = $base !== '' ? $base : Str::lower(class_basename($this));
        $taken = $this->takenSlugs($base);
        $slug = $base;

        // Before: one EXISTS query per candidate — an N+1 for popular titles (the CSV
        // import profile showed ~3 queries per new book):
        // for ($i = 2; $this->slugExists($slug); $i++) { $slug = "{$base}-{$i}"; }
        for ($i = 2; isset($taken[$slug]); $i++) {
            $slug = "{$base}-{$i}";
        }

        return $slug;
    }

    /**
     * @return array<string, int> slug => index, for isset() lookups
     */
    protected function takenSlugs(string $base): array
    {
        // The unique index also covers soft-deleted rows, so look at them too.
        return static::query()
            ->when(in_array(SoftDeletes::class, class_uses_recursive($this)), fn ($query) => $query->withTrashed())
            ->where(fn ($query) => $query->where('slug', $base)->orWhere('slug', 'like', $base.'-%'))
            ->pluck('slug')
            ->flip()
            ->all();
    }
}
