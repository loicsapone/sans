<?php

declare(strict_types=1);

namespace App\Recipe;

use App\Domain\AllergenVerdict;
use App\Domain\Ingredient;
use App\Enum\Allergen;
use App\Enum\Certainty;

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

    /**
     * The ingredient lines that always carry one of the allergens, with the allergens they carry.
     * An ingredient that only may carry it is left out: only its label can tell.
     *
     * @param list<Allergen> $allergens
     *
     * @return array<int, list<Allergen>> by position of the line
     */
    public function linesCarrying(array $allergens): array
    {
        $found = [];
        foreach ($this->lines as $position => $line) {
            foreach ($allergens as $allergen) {
                foreach ($line->names as $name) {
                    if (Certainty::Always === ($this->ingredients[$name]?->certainties[$allergen->value] ?? null)) {
                        $found[$position][] = $allergen;
                        break;
                    }
                }
            }
        }

        return $found;
    }
}
