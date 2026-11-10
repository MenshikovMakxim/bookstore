<?php

use App\View\Components\RatingStars;

test('filled stars round to the nearest whole star', function (?float $rating, int $filled) {
    expect((new RatingStars($rating))->filled())->toBe($filled);
})->with([
    'no rating' => [null, 0],
    'zero' => [0.0, 0],
    'rounds down' => [4.49, 4],
    'half rounds up' => [4.5, 5],
    'rounds up' => [4.7, 5],
    'full marks' => [5.0, 5],
]);

test('filled stars are clamped to the configured maximum', function () {
    expect((new RatingStars(6.7))->filled())->toBe(5)
        ->and((new RatingStars(6.7, 10))->filled())->toBe(7)
        ->and((new RatingStars(0.1, 5))->filled())->toBe(0);
});
