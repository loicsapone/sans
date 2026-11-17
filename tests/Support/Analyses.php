<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Domain\AllergenVerdictEngine;
use App\Domain\Ingredient;
use App\Enum\Allergen;
use App\Enum\Certainty;
use App\Recipe\Analysis;
use App\Recipe\IngredientLine;
use App\Recipe\Recipe;

final class Analyses
{
    /**
     * A small gratin: butter (milk), a stock cube (celery, gluten and milk maybe) and an ingredient the reference does not know.
     */
    public static function gratin(): Analysis
    {
        $butter = new Ingredient(73, 'beurre', 'butter', [Allergen::Milk->value => Certainty::Always]);
        $stock = new Ingredient(5, 'bouillon', 'stock', [Allergen::Celery->value => Certainty::Depends, Allergen::Gluten->value => Certainty::Depends, Allergen::Milk->value => Certainty::Depends]);
        $ingredients = ['beurre' => $butter, 'bouillon' => $stock, 'zgougou' => null];

        return new Analysis(
            new Recipe('Gratin', ['100 g de beurre', '1 cube de bouillon', 'du zgougou'], ['Mélanger.', 'Cuire.'], 6, 75),
            [
                new IngredientLine('100 g de beurre', 'beurre', ['beurre'], 100.0, 'g'),
                new IngredientLine('1 cube de bouillon', 'bouillon', ['bouillon'], 1.0, null, true),
                new IngredientLine('du zgougou', 'zgougou', ['zgougou']),
            ],
            $ingredients,
            (new AllergenVerdictEngine())->evaluate($ingredients),
            ['fetch' => 0.2, 'extract' => 0.0, 'normalise' => 2.1, 'match' => 3.4],
        );
    }
}
