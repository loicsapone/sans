<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Labels\ProductLabels;
use App\Recipe\AnalysisStore;
use App\Tests\Support\Analyses;
use App\Tests\Support\CreatesSchema;
use App\Tests\Support\LabelsAgent;
use App\Twig\Components\ProductLabel;
use App\Twig\Components\RecipePage;
use Mcp\Schema\Result\CallToolResult;
use Symfony\AI\McpBundle\Client\ServerConnectionInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

final class ProductLabelTest extends KernelTestCase
{
    use CreatesSchema;
    use InteractsWithLiveComponents;

    private const URL = 'https://www.example.com/recipes/gratin';

    protected function setUp(): void
    {
        self::bootKernel();
        $this->createSchema();
        self::getContainer()->get(AnalysisStore::class)->save(self::URL, Analyses::gratin());
    }

    public function testTheRecipePageLeavesThePlaceOfTheLabelsToBeLoadedAfterIt(): void
    {
        $component = $this->createLiveComponent(RecipePage::class, ['url' => self::URL]);
        $component->call('toggleAllergen', ['allergen' => 'celery']);

        $label = $component->render()->crawler()->filter('.ingredient__label');

        // only the stock cube may contain celery
        self::assertCount(1, $label);
        self::assertSame('live:connect->live#$render', $label->attr('data-action'));
        self::assertSame('Reading the labels of real products on Open Food Facts…', $label->text());
    }

    public function testItSaysHowManyProductsDeclareTheAllergenTheLineMayCarry(): void
    {
        $openFoodFacts = $this->createStub(ServerConnectionInterface::class);
        $openFoodFacts->method('callTool')->willReturnCallback(static fn (string $tool, array $arguments): CallToolResult => new CallToolResult([], false, ['total' => isset($arguments['allergens_tag']) ? 469 : 992]));
        self::getContainer()->set(ProductLabels::class, new ProductLabels(new LabelsAgent(), $openFoodFacts, new ArrayAdapter()));

        $component = $this->createLiveComponent(ProductLabel::class, ['url' => self::URL, 'line' => 1, 'avoided' => ['celery', 'fish']]);

        self::assertSame('On Open Food Facts, 469 of 992 products in “Stock cubes” declare celery.', $component->render()->crawler()->filter('.ingredient__label')->text());
    }

    public function testItSaysNothingForALineThatCarriesNoAvoidedAllergenOrAnAddressItDoesNotKnow(): void
    {
        foreach ([['url' => self::URL, 'line' => 0, 'avoided' => ['celery']], ['url' => 'https://example.com/unknown', 'line' => 1, 'avoided' => ['celery']]] as $props) {
            self::assertSame('', $this->createLiveComponent(ProductLabel::class, $props)->render()->crawler()->filter('.ingredient__label')->text());
        }
    }
}
