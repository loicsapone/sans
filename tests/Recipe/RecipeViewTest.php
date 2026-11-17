<?php

declare(strict_types=1);

namespace App\Tests\Recipe;

use App\Enum\Allergen;
use App\Recipe\Adaptation;
use App\Recipe\AdaptedStep;
use App\Recipe\RecipeView;
use App\Recipe\Swap;
use App\Tests\Support\Analyses;
use PHPUnit\Framework\TestCase;

final class RecipeViewTest extends TestCase
{
    /**
     * @param list<Allergen> $avoided
     */
    private function view(array $avoided = [], ?Adaptation $adaptation = null, bool $original = false): RecipeView
    {
        return RecipeView::of(Analyses::gratin(), 'https://www.example.com/recipes/gratin', $avoided, $adaptation, $original);
    }

    private function adaptation(): Adaptation
    {
        return new Adaptation(
            [new Swap(0, [Allergen::Milk], '100 ml', 'Sunflower oil', 'A neutral oil keeps it moist without dairy.')],
            [new AdaptedStep('Mélanger avec l’huile.', true), new AdaptedStep('Cuire.')],
        );
    }

    public function testTheMetaLineGivesTheSourceTheServingsAndTheTime(): void
    {
        self::assertSame('example.com · Serves 6 · 1 h 15', $this->view()->meta());
        self::assertSame('Gratin', $this->view()->title());
    }

    public function testEachIngredientShowsItsQuantityItsNameAndTheAllergensItCarries(): void
    {
        $rows = $this->view()->ingredients();

        self::assertSame('100 g', $rows[0]['quantity']);
        self::assertSame('beurre', $rows[0]['name']);
        self::assertSame('Milk', $rows[0]['note']);
        self::assertSame('bouillon (optional)', $rows[1]['name']);
        self::assertSame('1', $rows[1]['quantity']);
        self::assertSame('Gluten? · Milk? · Celery?', $rows[1]['note']);
        self::assertSame('', $rows[2]['note']);
    }

    public function testAllergensAreSplitBetweenFoundAndNotDetected(): void
    {
        $found = array_column($this->view()->found(), 'label');
        $notDetected = array_column($this->view()->notDetected(), 'label');

        self::assertSame(['Gluten', 'Milk', 'Celery'], $found);
        self::assertCount(11, $notDetected);
        self::assertNotContains('Milk', $notDetected);
    }

    public function testEachFoundAllergenSaysWhereItComesFrom(): void
    {
        $status = array_column($this->view()->found(), 'status', 'label');

        self::assertSame('In beurre', $status['Milk']);
        self::assertSame('May be in bouillon', $status['Gluten']);
    }

    public function testAnAllergenThatCannotBeRuledOutIsNeverPresentedAsFree(): void
    {
        $rows = array_column($this->view()->notDetected(), null, 'label');

        self::assertSame('Couldn’t identify: zgougou', $rows['Fish']['note']);
        self::assertSame(1, $this->view()->unidentifiedCount());
    }

    public function testBeforeTheAnalysisOnlyTheTextOfThePageIsShown(): void
    {
        $view = RecipeView::withoutAnalysis(Analyses::gratin()->recipe, 'https://www.example.com/recipes/gratin');

        self::assertTrue($view->isPending());
        self::assertSame('example.com · Serves 6 · 1 h 15', $view->meta());
        self::assertSame(['100 g de beurre', '1 cube de bouillon', 'du zgougou'], array_column($view->ingredients(), 'name'));
        self::assertSame(['', '', ''], array_column($view->ingredients(), 'note'));
        self::assertSame(['Mélanger.', 'Cuire.'], array_column($view->steps(), 'text'));
        self::assertSame([], $view->found());
        self::assertSame([], $view->notDetected());
        self::assertSame(0, $view->unidentifiedCount());
        self::assertFalse($this->view()->isPending());
    }

    public function testAvoidedAllergensAreMarkedActive(): void
    {
        $view = $this->view([Allergen::Milk, Allergen::Fish]);

        self::assertSame(['Milk'], array_column(array_filter($view->found(), static fn (array $row): bool => $row['active']), 'label'));
        self::assertSame(['Fish'], array_column(array_filter($view->notDetected(), static fn (array $row): bool => $row['active']), 'label'));
        self::assertSame(0, $view->swapCount());
        self::assertFalse($view->hasAdaptation());
    }

    public function testASwapReplacesTheIngredientAndAdaptsTheSteps(): void
    {
        $view = $this->view([Allergen::Milk], $this->adaptation());

        self::assertTrue($view->hasAdaptation());
        self::assertSame(['quantity' => '100 ml', 'name' => 'Sunflower oil', 'why' => 'A neutral oil keeps it moist without dairy.', 'was' => 'beurre'], $view->ingredients()[0]['swap']);
        self::assertNull($view->ingredients()[1]['swap']);
        self::assertSame([['number' => 1, 'text' => 'Mélanger avec l’huile.', 'adapted' => true], ['number' => 2, 'text' => 'Cuire.', 'adapted' => false]], $view->steps());
        self::assertSame(1, $view->swapCount());
        self::assertSame(1, $view->adaptedStepCount());

        $milk = array_column($view->found(), null, 'label')['Milk'];
        self::assertTrue($milk['swapped']);
        self::assertSame('Swapped out of beurre', $milk['status']);
    }

    public function testTheOriginalRecipeIsKeptWhenTheVisitorAsksForIt(): void
    {
        $view = $this->view([Allergen::Milk], $this->adaptation(), original: true);

        self::assertNull($view->ingredients()[0]['swap']);
        self::assertSame(['Mélanger.', 'Cuire.'], array_column($view->steps(), 'text'));
        self::assertTrue($view->showsOriginal());
        self::assertSame(0, $view->swapCount());
    }
}
