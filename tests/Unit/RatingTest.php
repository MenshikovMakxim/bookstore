<?php

use App\ValueObjects\Rating;

test('stars renders five characters', function (int $rating, string $stars) {
    expect(Rating::fromStars($rating)->stars())->toBe($stars);
})->with([
    [1, '★☆☆☆☆'],
    [3, '★★★☆☆'],
    [5, '★★★★★'],
]);

test('rating rejects values outside 1..5', function (int $rating) {
    expect(fn () => Rating::fromStars($rating))->toThrow(InvalidArgumentException::class);
})->with([0, 6, -1]);

test('rating serializes as value + stars', function () {
    expect(Rating::fromStars(4)->toArray())->toBe(['value' => 4, 'stars' => '★★★★☆'])
        ->and(json_decode(json_encode(Rating::fromStars(2)), true))->toBe(['value' => 2, 'stars' => '★★☆☆☆'])
        ->and((string) Rating::fromStars(5))->toBe('5');
});
