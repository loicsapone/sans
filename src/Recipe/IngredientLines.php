<?php

declare(strict_types=1);

namespace App\Recipe;

final class IngredientLines
{
    /**
     * @param list<IngredientLine> $lines one entry per line given, in the same order
     */
    public function __construct(
        public array $lines = [],
    ) {
    }
}
