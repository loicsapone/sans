<?php

declare(strict_types=1);

namespace App\Recipe;

final class Recipe
{
    /**
     * @param string       $title       the title of the recipe, empty when the page has none
     * @param list<string> $ingredients one entry per ingredient line, copied as written on the page
     * @param list<string> $steps       one entry per step of the method
     */
    public function __construct(
        public string $title = '',
        public array $ingredients = [],
        public array $steps = [],
    ) {
    }
}
