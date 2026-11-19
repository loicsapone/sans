<?php

declare(strict_types=1);

namespace App\Tests\Recipe;

use App\Domain\AllergenVerdictEngine;
use App\Enum\Allergen;
use App\Enum\Certainty;
use App\Enum\Verdict;
use App\Tests\Support\Analyses;
use PHPUnit\Framework\TestCase;

final class AnalysisTest extends TestCase
{
    public function testWhatTheVisitorDeclaresOfAnUnidentifiedIngredientIsDetected(): void
    {
        $analysis = Analyses::gratin()->withDeclared(['zgougou' => [Allergen::Fish]], new AllergenVerdictEngine());

        self::assertSame([Allergen::Fish->value => Certainty::Always], $analysis->ingredients['zgougou']?->certainties);
        self::assertSame(Verdict::Detected, $analysis->verdicts['fish']->verdict);
        self::assertSame(['zgougou'], $analysis->verdicts['fish']->ingredients);
        self::assertSame(Verdict::NotDetected, $analysis->verdicts['eggs']->verdict);
        self::assertSame([2 => ['fish' => Certainty::Always]], $analysis->linesCarrying([Allergen::Fish]));
    }

    public function testAnIngredientDeclaredWithoutAllergenNoLongerLeavesTheRecipeIncomplete(): void
    {
        $analysis = Analyses::gratin();
        self::assertSame(Verdict::Incomplete, $analysis->verdicts['fish']->verdict);

        $declared = $analysis->withDeclared(['zgougou' => []], new AllergenVerdictEngine());

        self::assertSame(Verdict::NotDetected, $declared->verdicts['fish']->verdict);
        self::assertSame(Verdict::Detected, $declared->verdicts['milk']->verdict);
        self::assertNull($analysis->ingredients['zgougou']);
    }

    public function testOnlyAnUnidentifiedIngredientOfTheRecipeCanBeDeclared(): void
    {
        $analysis = Analyses::gratin();

        $declared = $analysis->withDeclared(['beurre' => [], 'caviar' => [Allergen::Fish]], new AllergenVerdictEngine());

        self::assertSame($analysis, $declared);
    }
}
