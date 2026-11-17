<?php

declare(strict_types=1);

namespace App\Recipe;

/**
 * A recipe cooked without some allergens: the ingredients that were swapped and the steps as they now read.
 */
final readonly class Adaptation
{
    /**
     * @param list<Swap>        $swaps
     * @param list<AdaptedStep> $steps all the steps of the adapted recipe, in order
     */
    public function __construct(
        public array $swaps,
        public array $steps,
    ) {
    }
}
