<?php

declare(strict_types=1);

namespace App\Tests\Labels;

use App\Domain\Ingredient;
use App\Enum\Allergen;
use App\Enum\Certainty;
use App\Labels\ProductLabels;
use App\Tests\Support\LabelsAgent;
use Mcp\Schema\Result\CallToolResult;
use PHPUnit\Framework\TestCase;
use Symfony\AI\McpBundle\Client\ServerConnectionInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

final class ProductLabelsTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $searches = [];

    private ArrayAdapter $cache;

    protected function setUp(): void
    {
        $this->cache = new ArrayAdapter();
    }

    /**
     * Open Food Facts as seen through its MCP server: 992 stock cubes, 469 of them declaring celery.
     */
    private function labels(bool $failing = false): ProductLabels
    {
        $openFoodFacts = $this->createStub(ServerConnectionInterface::class);
        $openFoodFacts->method('callTool')->willReturnCallback(function (string $tool, array $arguments) use ($failing): CallToolResult {
            $this->searches[] = $arguments;

            return new CallToolResult([], $failing, $failing ? null : ['total' => isset($arguments['allergens_tag']) ? 469 : 992]);
        });

        return new ProductLabels(new LabelsAgent(), $openFoodFacts, $this->cache);
    }

    private function stock(): Ingredient
    {
        return new Ingredient(5, 'bouillon', 'stock', [Allergen::Celery->value => Certainty::Depends, Allergen::Milk->value => Certainty::Depends]);
    }

    public function testTheProductsOfTheCategoryTheAgentFoundAreCountedForEachAllergenTheIngredientMayCarry(): void
    {
        $labels = $this->labels()->of([$this->stock()], [Allergen::Celery, Allergen::Fish]);

        self::assertSame([5 => [['category' => 'Stock cubes', 'total' => 992, 'allergen' => 'celery', 'declaring' => 469]]], $labels);
        self::assertSame([
            ['categories_tag' => 'en:bouillon-cubes', 'page_size' => 1],
            ['categories_tag' => 'en:bouillon-cubes', 'allergens_tag' => 'en:celery', 'page_size' => 1],
        ], $this->searches);
    }

    public function testAnIngredientWithoutACategoryIsLeftOutAndNothingIsSearched(): void
    {
        $butter = new Ingredient(73, 'beurre', 'butter', [Allergen::Milk->value => Certainty::Depends]);

        self::assertSame([], $this->labels()->of([$butter], [Allergen::Milk]));
        self::assertSame([], $this->searches);
    }

    public function testNothingIsSaidWhenTheServerFailsAndItIsAskedAgainTheNextTime(): void
    {
        self::assertSame([], $this->labels(failing: true)->of([$this->stock()], [Allergen::Celery]));
        self::assertCount(1, $this->labels()->of([$this->stock()], [Allergen::Celery]));
    }

    public function testTheSameQuestionIsNotAskedTwice(): void
    {
        $this->labels()->of([$this->stock()], [Allergen::Celery]);
        $this->labels()->of([$this->stock()], [Allergen::Celery]);

        self::assertCount(2, $this->searches);
    }

    public function testTheAllergensHaveTheirOpenFoodFactsTag(): void
    {
        self::assertSame('en:milk', Allergen::Milk->openFoodFactsTag());
        self::assertSame('en:nuts', Allergen::TreeNuts->openFoodFactsTag());
        self::assertSame('en:sesame-seeds', Allergen::Sesame->openFoodFactsTag());
        self::assertSame('en:sulphur-dioxide-and-sulphites', Allergen::Sulphites->openFoodFactsTag());
    }
}
