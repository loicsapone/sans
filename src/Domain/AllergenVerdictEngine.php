<?php

declare(strict_types=1);

namespace App\Domain;

use App\Enum\Allergen;
use App\Enum\Certainty;
use App\Enum\Verdict;

// Pure PHP on purpose: no model output ever becomes a verdict without going through here.
final class AllergenVerdictEngine
{
    /**
     * @param array<string, Ingredient|null> $ingredients the recipe ingredient names, with the reference ingredient found for each (null when unrecognised)
     *
     * @return array<string, AllergenVerdict> by allergen value
     */
    public function evaluate(array $ingredients): array
    {
        if ([] === $ingredients) {
            throw new \InvalidArgumentException('Cannot evaluate a recipe without ingredients.');
        }

        $verdicts = [];
        foreach (Allergen::cases() as $allergen) {
            $always = $depends = $unknown = [];
            foreach ($ingredients as $name => $ingredient) {
                $name = (string) $name;
                if (null === $ingredient) {
                    $unknown[] = $name;
                } elseif (Certainty::Always === ($ingredient->certainties[$allergen->value] ?? null)) {
                    $always[] = $name;
                } elseif (Certainty::Depends === ($ingredient->certainties[$allergen->value] ?? null)) {
                    $depends[] = $name;
                }
            }

            $verdicts[$allergen->value] = match (true) {
                [] !== $always => new AllergenVerdict($allergen, Verdict::Detected, $always),
                [] !== $depends => new AllergenVerdict($allergen, Verdict::DependsOnProduct, $depends),
                [] !== $unknown => new AllergenVerdict($allergen, Verdict::Incomplete, $unknown),
                default => new AllergenVerdict($allergen, Verdict::NotDetected),
            };
        }

        return $verdicts;
    }
}
