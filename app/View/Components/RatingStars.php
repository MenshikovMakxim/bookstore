<?php

namespace App\View\Components;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Class-based component: rounding lives here and can be tested without HTML.
 *
 * <x-rating-stars :rating="4.3" /> (max defaults to 5).
 */
class RatingStars extends Component
{
    public function __construct(
        public ?float $rating = null,
        public int $max = 5,
    ) {}

    /**
     * Whole stars to fill: 4.5 rounds up, 4.49 rounds down, no rating fills none.
     */
    public function filled(): int
    {
        if ($this->rating === null) {
            return 0;
        }

        return max(0, min($this->max, (int) round($this->rating)));
    }

    public function render(): View|Closure|string
    {
        return view('components.rating-stars');
    }
}
