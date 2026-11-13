<?php

namespace App\Casts;

use App\ValueObjects\Rating;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Database\Eloquent\SerializesCastableAttributes;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Integer 1..5 in the database <=> Rating in the model.
 *
 * Used via Rating::castUsing(), so a model writes 'rating' => Rating::class.
 * Query Builder reads/writes stay raw (DB::table('reviews')->value('rating') => int).
 *
 * @implements CastsAttributes<Rating, Rating|int>
 */
class RatingCast implements CastsAttributes, SerializesCastableAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Rating
    {
        return $value === null ? null : Rating::fromStars((int) $value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?int
    {
        return match (true) {
            $value === null => null,
            $value instanceof Rating => $value->value,
            is_int($value) => Rating::fromStars($value)->value,
            is_string($value) && ctype_digit($value) => Rating::fromStars((int) $value)->value,
            default => throw new InvalidArgumentException(sprintf(
                '%s::$%s expects Rating, an integer 1..5 or its numeric string, %s given.',
                $model::class, $key, get_debug_type($value),
            )),
        };
    }

    /**
     * toArray()/toJson(): {"value": 4, "stars": "★★★★☆"}.
     *
     * @return array{value: int, stars: string}|null
     */
    public function serialize(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        return $value?->toArray();
    }
}
