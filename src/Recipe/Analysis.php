<?php

declare(strict_types=1);

namespace App\Recipe;

use App\Domain\AllergenVerdict;
use App\Domain\Ingredient;

final readonly class Analysis
{
    /**
     * @param list<IngredientLine>           $lines       the ingredient lines of the recipe, normalised
     * @param array<string, Ingredient|null> $ingredients the reference ingredient found for each name, null when there is none
     * @param array<string, AllergenVerdict> $verdicts    by allergen value
     * @param array<string, float>           $durations   seconds spent in each stage
     */
    public function __construct(
        public Recipe $recipe,
        public array $lines,
        public array $ingredients,
        public array $verdicts,
        public array $durations,
    ) {
    }
}
