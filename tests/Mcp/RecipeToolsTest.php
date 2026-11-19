<?php

declare(strict_types=1);

namespace App\Tests\Mcp;

use App\Mcp\RecipeTools;
use App\Recipe\AnalysisStore;
use App\Tests\Support\AdaptationAgent;
use App\Tests\Support\Analyses;
use App\Tests\Support\CreatesSchema;
use Mcp\Exception\ToolCallException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RecipeToolsTest extends KernelTestCase
{
    use CreatesSchema;

    private const URL = 'https://www.example.com/recipes/gratin';

    private RecipeTools $tools;

    protected function setUp(): void
    {
        AdaptationAgent::$calls = 0;
        self::bootKernel();
        $this->createSchema();
        self::getContainer()->get(AnalysisStore::class)->save(self::URL, Analyses::gratin());
        $this->tools = self::getContainer()->get(RecipeTools::class);
    }

    public function testAnalysingSaysWhichAllergensEachLineCarries(): void
    {
        $result = $this->tools->analyze(' '.self::URL.' ');

        self::assertSame('Gratin', $result['title']);
        self::assertSame(6, $result['servings']);
        self::assertSame(['line' => 0, 'text' => '100 g de beurre', 'optional' => false, 'allergens' => ['milk' => 'always']], $result['ingredients'][0]);
        self::assertSame(['gluten' => 'depends', 'milk' => 'depends', 'celery' => 'depends'], $result['ingredients'][1]['allergens']);
        self::assertSame([], $result['ingredients'][2]['allergens']);
        self::assertSame(['verdict' => 'detected', 'ingredients' => ['beurre']], $result['allergens']['milk']);
        self::assertSame('depends', $result['allergens']['celery']['verdict']);
        self::assertSame('incomplete', $result['allergens']['eggs']['verdict']);
        self::assertSame([], $result['not_detected']);
    }

    public function testAnAddressThatDoesNotAnswerIsAToolErrorNamingTheStage(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('The analysis failed at the "fetch" stage: The page answered with HTTP 404.');

        $this->tools->analyze('https://93.184.216.34/missing');
    }

    public function testAdaptingSwapsTheLineThatCarriesTheAllergenLeavesTheOneThatMayToTheLabelAndKeepsTheAdaptation(): void
    {
        $result = $this->tools->adapt(self::URL, ['milk']);

        self::assertSame([['line' => 0, 'was' => '100 g de beurre', 'quantity' => '80 ml', 'name' => 'Olive oil', 'role' => 'fat', 'removes' => ['milk']]], $result['swaps']);
        self::assertSame([['text' => 'Mélanger avec l’huile.', 'adapted' => true], ['text' => 'Cuire.', 'adapted' => false]], $result['steps']);
        self::assertSame([['line' => 1, 'text' => '1 cube de bouillon']], $result['without_swap']);

        self::assertSame($result, $this->tools->adapt(self::URL, ['milk']));
        self::assertSame(1, AdaptationAgent::$calls);
    }

    public function testAnUnknownAllergenIsRefusedWithTheListOfThoseThatExist(): void
    {
        $this->expectException(ToolCallException::class);
        $this->expectExceptionMessage('"dairy" is not one of the allergens: gluten, crustaceans');

        $this->tools->adapt(self::URL, ['dairy']);
    }

    public function testAdaptingNeedsAnAllergen(): void
    {
        $this->expectException(ToolCallException::class);

        $this->tools->adapt(self::URL, []);
    }

    public function testCheckingLooksTheNamesUpInTheReference(): void
    {
        self::assertSame([
            'cream' => ['known' => true, 'allergens' => ['milk' => 'always']],
            'unicorn butter' => ['known' => false, 'allergens' => []],
        ], $this->tools->check(['cream', 'unicorn butter']));
    }
}
