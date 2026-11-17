<?php

declare(strict_types=1);

namespace App\Tests\Recipe;

use App\Matching\IngredientMatcher;
use App\Recipe\IngredientCheck;
use App\Tests\Support\MatchingAgent;
use PHPUnit\Framework\TestCase;

final class IngredientCheckTest extends TestCase
{
    public function testSaysWhatIsKnownAndWhichAllergensItCarries(): void
    {
        $check = new IngredientCheck(new IngredientMatcher(new MatchingAgent()));

        self::assertSame([
            'olive oil' => ['known' => true, 'allergens' => []],
            'cream' => ['known' => true, 'allergens' => ['milk' => 'always']],
            'unicorn butter' => ['known' => false, 'allergens' => []],
        ], $check(['olive oil', 'cream', 'unicorn butter']));
    }
}
