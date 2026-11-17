<?php

declare(strict_types=1);

namespace App\Tests\Domain;

use App\Domain\AllergenVerdictEngine;
use App\Domain\Ingredient;
use App\Enum\Allergen;
use App\Enum\Certainty;
use App\Enum\Verdict;
use PHPUnit\Framework\TestCase;

final class AllergenVerdictEngineTest extends TestCase
{
    private static function ingredient(string $name, Allergen $allergen, Certainty $certainty): Ingredient
    {
        return new Ingredient(1, $name, $name, [$allergen->value => $certainty]);
    }

    public function testAnAllergenCarriedWithCertaintyIsDetected(): void
    {
        $butter = self::ingredient('beurre', Allergen::Milk, Certainty::Always);

        $verdict = (new AllergenVerdictEngine())->evaluate(['une noix de beurre' => $butter])['milk'];

        self::assertSame(Verdict::Detected, $verdict->verdict);
        self::assertSame(['une noix de beurre'], $verdict->ingredients);
    }

    public function testACertainSourceWinsOverADependingOne(): void
    {
        $stock = self::ingredient('bouillon', Allergen::Milk, Certainty::Depends);
        $butter = self::ingredient('beurre', Allergen::Milk, Certainty::Always);

        $verdict = (new AllergenVerdictEngine())->evaluate(['bouillon' => $stock, 'beurre' => $butter])['milk'];

        self::assertSame(Verdict::Detected, $verdict->verdict);
        self::assertSame(['beurre'], $verdict->ingredients);
    }

    public function testAnAllergenCarriedInSomeProductsOnlyDependsOnTheProduct(): void
    {
        $pesto = self::ingredient('pesto', Allergen::TreeNuts, Certainty::Depends);

        $verdict = (new AllergenVerdictEngine())->evaluate(['pesto' => $pesto])['tree-nuts'];

        self::assertSame(Verdict::DependsOnProduct, $verdict->verdict);
    }

    public function testAnUnrecognisedIngredientMakesTheOtherAllergensIncomplete(): void
    {
        $butter = self::ingredient('beurre', Allergen::Milk, Certainty::Always);

        $verdicts = (new AllergenVerdictEngine())->evaluate(['beurre' => $butter, 'zgougou' => null]);

        self::assertSame(Verdict::Detected, $verdicts['milk']->verdict);
        self::assertSame(Verdict::Incomplete, $verdicts['gluten']->verdict);
        self::assertSame(['zgougou'], $verdicts['gluten']->ingredients);
    }

    public function testNotDetectedOnlyWhenEveryIngredientIsRecognised(): void
    {
        $salt = new Ingredient(2, 'sel', 'salt', []);

        $verdicts = (new AllergenVerdictEngine())->evaluate(['sel' => $salt]);

        self::assertCount(14, $verdicts);
        self::assertSame(Verdict::NotDetected, $verdicts['gluten']->verdict);
    }

    public function testRefusesAnEmptyRecipe(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new AllergenVerdictEngine())->evaluate([]);
    }
}
