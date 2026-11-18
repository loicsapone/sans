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
     * The ingredient lines that carry one of the allergens, with how sure it is for each allergen:
     * always when one of the ingredients of the line always carries it, depends when only its label can tell.
     *
     * @param list<Allergen> $allergens
     *
     * @return array<int, array<string, Certainty>> by position of the line, then by allergen value
     */
    public function linesCarrying(array $allergens): array
    {
        $found = [];
        foreach ($this->lines as $position => $line) {
            foreach ($allergens as $allergen) {
                foreach ($line->names as $name) {
                    $certainty = $this->ingredients[$name]?->certainties[$allergen->value] ?? null;
                    if (null !== $certainty && Certainty::Always !== ($found[$position][$allergen->value] ?? null)) {
                        $found[$position][$allergen->value] = $certainty;
                    }
                }
            }
        }

        return $found;
    }
}
