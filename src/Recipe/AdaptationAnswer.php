<?php

declare(strict_types=1);

namespace App\Recipe;

final class AdaptationAnswer
{
    /**
     * @param list<SwapAnswer> $swaps one entry per ingredient line to replace
     * @param list<StepChange> $steps only the steps that change
     */
    public function __construct(
        public array $swaps = [],
        public array $steps = [],
    ) {
    }
}
