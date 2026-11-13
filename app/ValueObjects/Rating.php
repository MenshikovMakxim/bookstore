<?php

namespace App\ValueObjects;

use App\Casts\RatingCast;
use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;
use Stringable;

/**
 * A review rating from 1 to 5 stars.
 *
 * Immutable value object: the database stores the raw integer, the model exposes
 * this object, and stars() renders the familiar five-character scale.
 *
 * @implements Arrayable<string, int|string>
 */
final readonly class Rating implements Arrayable, Castable, JsonSerializable, Stringable
{
    public const MIN = 1;

    public const MAX = 5;

    private function __construct(public int $value) {}

    public static function fromStars(int $stars): self
    {
        if ($stars < self::MIN || $stars > self::MAX) {
            throw new InvalidArgumentException(sprintf(
                'Rating must be between %d and %d, %d given.', self::MIN, self::MAX, $stars,
            ));
        }

        return new self($stars);
    }

    /**
     * Five characters: ★ for the chosen stars, ☆ for the rest.
     */
    public function stars(): string
    {
        return str_repeat('★', $this->value).str_repeat('☆', self::MAX - $this->value);
    }

    public static function castUsing(array $arguments): string
    {
        return RatingCast::class;
    }

    /**
     * @return array{value: int, stars: string}
     */
    public function toArray(): array
    {
        return ['value' => $this->value, 'stars' => $this->stars()];
    }

    /**
     * @return array{value: int, stars: string}
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public function __toString(): string
    {
        return (string) $this->value;
    }
}
