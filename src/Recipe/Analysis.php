<?php

declare(strict_types=1);

namespace App\Recipe;

use App\Domain\AllergenVerdict;
use App\Domain\AllergenVerdictEngine;
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
     * @param array<string, list<Allergen>> $declared what the visitor says an ingredient contains, by ingredient name
     */
    public function withDeclared(array $declared, AllergenVerdictEngine $engine): self
    {
        $ingredients = $this->ingredients;
        foreach ($declared as $name => $allergens) {
            $name = (string) $name;
            // the visitor only speaks for what the reference does not know
            if (\array_key_exists($name, $ingredients) && null === $ingredients[$name]) {
                $ingredients[$name] = new Ingredient(0, $name, $name, array_fill_keys(array_column($allergens, 'value'), Certainty::Always));
            }
        }

        return $ingredients === $this->ingredients ? $this : new self($this->recipe, $this->lines, $ingredients, $engine->evaluate($ingredients), $this->durations);
    }

    /**
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
