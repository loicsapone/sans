<?php

declare(strict_types=1);

namespace App\Recipe;

final readonly class Adaptation
{
    /**
     * @param list<Swap>        $swaps
     * @param list<AdaptedStep> $steps  all the steps of the adapted recipe, in order
     * @param list<int>         $failed the positions of the ingredient lines no safe swap was found for (those that only may carry an allergen included)
     */
    public function __construct(
        public array $swaps,
        public array $steps,
        public array $failed = [],
    ) {
    }
}
