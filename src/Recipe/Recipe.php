<?php

declare(strict_types=1);

namespace App\Recipe;

final class Recipe
{
    /**
     * @param string       $title       the title of the recipe, empty when the page has none
     * @param list<string> $ingredients one entry per ingredient line, copied as written on the page
     * @param list<string> $steps       one entry per step of the method
     * @param int|null     $servings    the number of servings, null when the page does not say
     * @param int|null     $minutes     the total time in minutes, null when the page does not say
     */
    public function __construct(
        public string $title = '',
        public array $ingredients = [],
        public array $steps = [],
        public ?int $servings = null,
        public ?int $minutes = null,
    ) {
    }
}
