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
use App\Tests\Support\RecipePages;
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
        self::assertCount(0, $crawler->filter('.card__warning'));
    }

    public function testReadingANewAddressShowsTheRecipeBeforeItsAllergens(): void
    {
        $component = $this->createLiveComponent(RecipePage::class, ['url' => RecipePages::GRATIN]);

        $component->call('read');

        $crawler = $component->render()->crawler();
        self::assertSame('Gratin dauphinois', $crawler->filter('h1')->text());
        self::assertSame('93.184.216.34 · Serves 6 · 1 h 15', $crawler->filter('.recipe__meta')->text());
        self::assertCount(4, $crawler->filter('li.ingredient--pending'));
        self::assertSame('1 kg', $crawler->filter('li.ingredient--pending .ingredient__quantity')->first()->text());
        self::assertStringContainsString('de pommes de terre', $crawler->filter('li.ingredient--pending')->first()->text());
        self::assertCount(2, $crawler->filter('.method__step'));
        self::assertSame('Checking the 14 allergens…', $crawler->filter('.card__pending')->text());
        self::assertCount(0, $crawler->filter('button.toggle'));
        self::assertSame('delay(100)|$render', $crawler->filter('.recipe-page')->attr('data-poll'));
    }

    public function testAnAddressThatDoesNotAnswerIsReported(): void
    {
        $component = $this->createLiveComponent(RecipePage::class, ['url' => 'https://93.184.216.34/missing']);

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

    public function testAnUnidentifiedIngredientAsksWhatItContains(): void
    {
        $crawler = $this->createLiveComponent(RecipePage::class, ['url' => self::URL])->render()->crawler();

        self::assertCount(1, $crawler->filter('details.declare'));
        self::assertSame('Not identified', $crawler->filter('.declare__missing')->text());
        self::assertSame('Add allergens', $crawler->filter('.declare__action')->text());
        self::assertSame('Which allergens are in zgougou?', $crawler->filter('.declare__question')->text());
        self::assertCount(15, $crawler->filter('button.chip'));
        self::assertSame('disclosure', $crawler->filter('details.declare')->attr('data-controller'));
        self::assertCount(15, $crawler->filter('button.chip[data-action="live#action disclosure#close"]'));
        self::assertCount(0, $crawler->filter('button.chip[aria-pressed="true"]'));
        self::assertCount(0, $crawler->filter('.declare__undo'));
        self::assertCount(0, $crawler->filter('.card__warning'));
    }

    public function testDeclaringAnAllergenShowsItOnTheIngredientAndLiftsTheWarnings(): void
    {
        $component = $this->createLiveComponent(RecipePage::class, ['url' => self::URL]);

        $component->call('declareAllergen', ['ingredient' => 'zgougou', 'allergen' => 'fish']);

        $crawler = $component->render()->crawler();
        self::assertSame('Fish', $crawler->filter('.declare .ingredient__note')->text());
        self::assertSame('you told us', $crawler->filter('.declare__told')->text());
        self::assertSame('Edit', $crawler->filter('.declare__action')->text());
        self::assertSame('Fish', $crawler->filter('button.chip[aria-pressed="true"]')->text());
        self::assertStringContainsString('In zgougou', $crawler->filter('.allergen')->reduce(static fn (Crawler $row): bool => str_contains($row->text(), 'Fish'))->text());
        self::assertCount(0, $crawler->filter('.declare__missing'));

        $component->call('declareAllergen', ['ingredient' => 'zgougou', 'allergen' => 'fish']);
        $crawler = $component->render()->crawler();
        self::assertSame('Not identified', $crawler->filter('.declare__missing')->text());
    }

    public function testDeclaringNoAllergenLiftsTheWarningsUntilTheVisitorTakesItBack(): void
    {
        $component = $this->createLiveComponent(RecipePage::class, ['url' => self::URL]);

        $component->call('declareNone', ['ingredient' => 'zgougou']);

        $crawler = $component->render()->crawler();
        self::assertSame('No allergen', $crawler->filter('.declare .ingredient__note')->text());
        self::assertSame('None of these', $crawler->filter('button.chip[aria-pressed="true"]')->text());
        self::assertCount(0, $crawler->filter('.declare__missing'));

        $component->call('undeclare', ['ingredient' => 'zgougou']);

        $crawler = $component->render()->crawler();
        self::assertCount(1, $crawler->filter('.declare__missing'));
    }

    public function testAnAvoidedAllergenIsNotSaidFreeWhileAnIngredientIsUnidentified(): void
    {
        $component = $this->createLiveComponent(RecipePage::class, ['url' => self::URL]);
        $component->call('toggleAllergen', ['allergen' => 'fish']);
        $component->refresh();

        $crawler = $component->render()->crawler();
        self::assertSame('sans fish', $crawler->filter('.allergen__plain')->reduce(static fn (Crawler $row): bool => str_contains($row->text(), 'fish'))->text());
        self::assertCount(0, $crawler->filter('.allergen__free'));

        $component->call('declareNone', ['ingredient' => 'zgougou']);
        $component->refresh();

        self::assertSame('· already free', $component->render()->crawler()->filter('.allergen__free')->text());
    }

    public function testOnlyAnUnidentifiedIngredientAndAKnownAllergenCanBeDeclared(): void
    {
        $component = $this->createLiveComponent(RecipePage::class, ['url' => self::URL]);

        $component->call('declareAllergen', ['ingredient' => 'beurre', 'allergen' => 'fish']);
        $component->call('declareNone', ['ingredient' => 'beurre']);
        $component->call('declareNone', ['ingredient' => 'caviar']);
        $component->call('declareAllergen', ['ingredient' => 'zgougou', 'allergen' => 'banana']);

        $crawler = $component->render()->crawler();
        $page = $component->component();
        self::assertInstanceOf(RecipePage::class, $page);
        self::assertSame([], $page->declared);
        self::assertCount(0, $crawler->filter('button.chip[aria-pressed="true"]'));
        self::assertCount(1, $crawler->filter('.declare__missing'));
    }

    public function testADeclaredIngredientIsSwappedWhenItsAllergenIsAvoided(): void
    {
        $component = $this->createLiveComponent(RecipePage::class, ['url' => self::URL]);
        $component->call('toggleAllergen', ['allergen' => 'fish']);
        $component->refresh();
        self::assertCount(0, $component->render()->crawler()->filter('.ingredient__name--swapped'));

        $component->call('declareAllergen', ['ingredient' => 'zgougou', 'allergen' => 'fish']);
        self::assertSame('Finding a fish-free swap…', $component->render()->crawler()->filter('.ingredient__finding')->text());

        $component->refresh();
        $crawler = $component->render()->crawler();
        self::assertSame('Olive oil', $crawler->filter('.ingredient__name--swapped')->text());
        self::assertSame('was zgougou', $crawler->filter('.ingredient__was')->text());

        $everybody = $this->createLiveComponent(RecipePage::class, ['url' => self::URL]);
        $everybody->call('toggleAllergen', ['allergen' => 'fish']);
        self::assertCount(0, $everybody->render()->crawler()->filter('.ingredient__name--swapped'));
        self::assertCount(1, $everybody->render()->crawler()->filter('details.declare'));
    }

    public function testReadingAnAddressForgetsWhatWasDeclared(): void
    {
        $component = $this->createLiveComponent(RecipePage::class, ['url' => self::URL]);
        $component->call('declareNone', ['ingredient' => 'zgougou']);

        $component->call('read');

        $page = $component->component();
        self::assertInstanceOf(RecipePage::class, $page);
        self::assertSame([], $page->declared);
        self::assertCount(1, $component->render()->crawler()->filter('.declare__missing'));
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
