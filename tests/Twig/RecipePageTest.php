<?php

declare(strict_types=1);

namespace App\Tests\Twig;

use App\Enum\Allergen;
use App\Enum\Role;
use App\Recipe\Adaptation;
use App\Recipe\AdaptedStep;
use App\Recipe\AnalysisStore;
use App\Recipe\RecipeView;
use App\Recipe\Swap;
use App\Tests\Support\AdaptationAgent;
use App\Tests\Support\Analyses;
use App\Tests\Support\CreatesSchema;
use App\Twig\Components\RecipePage;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Twig\Environment;

final class RecipePageTest extends KernelTestCase
{
    use CreatesSchema;
    use InteractsWithLiveComponents;

    private const URL = 'https://www.example.com/recipes/gratin';

    protected function setUp(): void
    {
        AdaptationAgent::$calls = 0;
        self::bootKernel();
        $this->createSchema();
        self::getContainer()->get(AnalysisStore::class)->save(self::URL, Analyses::gratin());
    }

    private function pressed(Crawler $crawler, string $allergen): string
    {
        return (string) $crawler->filter(\sprintf('button[aria-label="Cook without %s"]', $allergen))->attr('aria-pressed');
    }

    public function testAnAddressThatWasAlreadyAnalysedShowsItsRecipeAtOnce(): void
    {
        $crawler = $this->createLiveComponent(RecipePage::class, ['url' => self::URL])->render()->crawler();

        self::assertSame('Gratin', $crawler->filter('h1')->text());
        self::assertSame('example.com · Serves 6 · 1 h 15', $crawler->filter('.recipe__meta')->text());
        self::assertSame('Found in this recipe', $crawler->filter('.card__group')->first()->text());
        self::assertCount(14, $crawler->filter('button.toggle'));
        self::assertStringContainsString('couldn’t be identified', $crawler->filter('.card__warning')->text());
    }

    public function testReadingANewAddressShowsTheRecipeBeforeItsAllergens(): void
    {
        $component = $this->createLiveComponent(RecipePage::class, ['url' => 'https://recipes.test/gratin']);

        $component->call('read');

        $crawler = $component->render()->crawler();
        self::assertSame('Gratin dauphinois', $crawler->filter('h1')->text());
        self::assertSame('recipes.test · Serves 6 · 1 h 15', $crawler->filter('.recipe__meta')->text());
        self::assertCount(4, $crawler->filter('li.ingredient--pending'));
        self::assertStringContainsString('1 kg de pommes de terre', $crawler->filter('li.ingredient--pending')->first()->text());
        self::assertCount(2, $crawler->filter('.method__step'));
        self::assertSame('Checking the 14 allergens…', $crawler->filter('.card__pending')->text());
        self::assertCount(0, $crawler->filter('button.toggle'));
        self::assertSame('delay(100)|$render', $crawler->filter('.recipe-page')->attr('data-poll'));
    }

    public function testAnAddressThatDoesNotAnswerIsReported(): void
    {
        $component = $this->createLiveComponent(RecipePage::class, ['url' => 'https://recipes.test/missing']);

        $component->call('read');

        $crawler = $component->render()->crawler();
        self::assertSame('Sans couldn’t open this page', $crawler->filter('[role="alert"] h1')->text());
        self::assertSame('The page answered with HTTP 404.', $crawler->filter('.failure__text')->text());
        self::assertNull($crawler->filter('.recipe-page')->attr('data-poll'));
    }

    public function testAnUnknownAddressOnlyFillsTheForm(): void
    {
        $crawler = $this->createLiveComponent(RecipePage::class, ['url' => 'https://example.com/other'])->render()->crawler();

        self::assertStringContainsString('without', $crawler->filter('h1')->text());
        self::assertSame('https://example.com/other', $crawler->filter('input[name="url"]')->attr('value'));
        self::assertCount(0, $crawler->filter('h1.recipe__title'));
    }

    public function testTappingTheMarkCooksWithoutAnAllergenAndTappingAgainUndoesIt(): void
    {
        $component = $this->createLiveComponent(RecipePage::class, ['url' => self::URL]);

        $component->call('toggleAllergen', ['allergen' => 'milk']);
        $crawler = $component->render()->crawler();
        self::assertSame('true', $this->pressed($crawler, 'milk'));
        self::assertSame('false', $this->pressed($crawler, 'gluten'));
        self::assertSame('sans milk', $crawler->filter('.allergen__sans')->ancestors()->first()->text());
        self::assertCount(1, $crawler->filter('.allergen__sans'));

        $component->refresh();
        $component->call('toggleAllergen', ['allergen' => 'milk']);
        self::assertSame('false', $this->pressed($component->render()->crawler(), 'milk'));
    }

    public function testTappingTheMarkShowsTheSwapsBeingFoundThenTheAdaptedRecipe(): void
    {
        $component = $this->createLiveComponent(RecipePage::class, ['url' => self::URL]);

        $component->call('toggleAllergen', ['allergen' => 'milk']);

        $crawler = $component->render()->crawler();
        self::assertSame('delay(100)|$render', $crawler->filter('.recipe-page')->attr('data-poll'));
        self::assertSame('Finding a dairy-free swap…', $crawler->filter('.ingredient__finding')->text());
        self::assertSame('Sans is updating the recipe…', $crawler->filter('.card__updating')->text());
        self::assertSame('true', $crawler->filter('button[aria-label="Cook without milk"]')->attr('aria-busy'));
        self::assertSame(0, AdaptationAgent::$calls);

        $component->refresh();
        $crawler = $component->render()->crawler();
        self::assertSame('Olive oil', $crawler->filter('.ingredient__name--swapped')->text());
        self::assertSame('adapted', $crawler->filter('.method__adapted')->text());
        self::assertNull($crawler->filter('.recipe-page')->attr('data-poll'));
        self::assertSame(1, AdaptationAgent::$calls);
    }

    public function testTheSameAllergensAreNotAdaptedTwice(): void
    {
        $component = $this->createLiveComponent(RecipePage::class, ['url' => self::URL]);
        $component->call('toggleAllergen', ['allergen' => 'milk']);
        $component->refresh();
        $component->call('toggleAllergen', ['allergen' => 'milk']);
        self::assertCount(0, $component->render()->crawler()->filter('.ingredient__swap'));

        $component->call('toggleAllergen', ['allergen' => 'milk']);

        self::assertSame('Olive oil', $component->render()->crawler()->filter('.ingredient__name--swapped')->text());
        self::assertSame(1, AdaptationAgent::$calls);
    }

    public function testBackToTheOriginalRecipeClearsEveryChoice(): void
    {
        $component = $this->createLiveComponent(RecipePage::class, ['url' => self::URL]);
        $component->call('toggleAllergen', ['allergen' => 'milk']);
        $component->call('toggleAllergen', ['allergen' => 'fish']);

        $component->call('reset');

        $crawler = $component->render()->crawler();
        self::assertSame('false', $this->pressed($crawler, 'milk'));
        self::assertSame('false', $this->pressed($crawler, 'fish'));
    }

    public function testAnUnknownAllergenFromTheBrowserIsIgnored(): void
    {
        $component = $this->createLiveComponent(RecipePage::class, ['url' => self::URL]);

        $component->call('toggleAllergen', ['allergen' => 'banana']);

        self::assertCount(0, $component->render()->crawler()->filter('button[aria-pressed="true"]'));
    }

    public function testReadingAnAddressThatWasAlreadyAnalysedUsesTheStoredAnalysis(): void
    {
        $component = $this->createLiveComponent(RecipePage::class, ['url' => 'https://elsewhere.example']);
        $component->set('url', self::URL);

        $component->call('read');

        self::assertSame('Gratin', $component->render()->crawler()->filter('h1')->text());
    }

    public function testAnAddressThatIsNotAWebPageIsReportedAndMarksTheField(): void
    {
        $component = $this->createLiveComponent(RecipePage::class, ['url' => 'file:///etc/passwd']);

        $component->call('read');

        $crawler = $component->render()->crawler();
        self::assertSame('Sans couldn’t open this page', $crawler->filter('[role="alert"] h1')->text());
        self::assertSame('true', $crawler->filter('input[name="url"]')->attr('aria-invalid'));
        self::assertCount(0, $crawler->filter('h1.recipe__title'));
    }

    public function testTheAdaptedRecipeShowsItsSwapsItsAdaptedStepsAndTheOriginalOnRequest(): void
    {
        $adaptation = new Adaptation(
            [new Swap(0, [Allergen::Milk], '100 ml', 'Sunflower oil', Role::Fat)],
            [new AdaptedStep('Mélanger avec l’huile.', true), new AdaptedStep('Cuire.')],
        );
        $twig = self::getContainer()->get(Environment::class);

        $adapted = new Crawler($twig->render('recipe/_recipe.html.twig', ['view' => RecipeView::of(Analyses::gratin(), self::URL, [Allergen::Milk], $adaptation)]));
        self::assertStringContainsString('Sans swap ·', $adapted->filter('.ingredient__swap')->text());
        self::assertSame('Sunflower oil', $adapted->filter('.ingredient__name--swapped')->text());
        self::assertSame('was beurre', $adapted->filter('.ingredient__was')->text());
        self::assertSame('adapted', $adapted->filter('.method__adapted')->text());
        self::assertSame('1 ingredient swapped, 1 step adapted.', $adapted->filter('.card__summary')->text());
        self::assertSame('Swapped out of beurre', $adapted->filter('.allergen__status--swapped')->text());
        self::assertSame('true', $adapted->filter('.version__button--yours')->attr('aria-pressed'));

        $original = new Crawler($twig->render('recipe/_recipe.html.twig', ['view' => RecipeView::of(Analyses::gratin(), self::URL, [Allergen::Milk], $adaptation, original: true)]));
        self::assertCount(0, $original->filter('.ingredient__swap'));
        self::assertSame('true', $original->filter('.version__button--original')->attr('aria-pressed'));
    }
}
