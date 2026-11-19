<?php

declare(strict_types=1);

namespace App\Tests\Recipe;

use App\Domain\AllergenVerdictEngine;
use App\Enum\Allergen;
use App\Enum\Role;
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
            [new Swap(0, [Allergen::Milk], '100 ml', 'Sunflower oil', Role::Fat)],
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

    public function testOnlyAnUnidentifiedIngredientAsksTheVisitorWhatItContains(): void
    {
        $rows = $this->view()->ingredients();

        self::assertSame([], $rows[0]['unidentified']);
        self::assertSame([], $rows[1]['unidentified']);
        self::assertSame([['name' => 'zgougou', 'declared' => false, 'allergens' => []]], $rows[2]['unidentified']);
        self::assertCount(14, $this->view()->allergenChoices());
        self::assertSame(['value' => 'gluten', 'label' => 'Gluten'], $this->view()->allergenChoices()[0]);
    }

    public function testWhatTheVisitorDeclaredIsShownOnTheIngredientAndLiftsTheWarnings(): void
    {
        $analysis = Analyses::gratin()->withDeclared(['zgougou' => [Allergen::Fish]], new AllergenVerdictEngine());
        $view = RecipeView::of($analysis, 'https://www.example.com/recipes/gratin', declared: ['zgougou']);

        self::assertSame('Fish', $view->ingredients()[2]['note']);
        self::assertSame([['name' => 'zgougou', 'declared' => true, 'allergens' => ['fish']]], $view->ingredients()[2]['unidentified']);
        self::assertSame('In zgougou', array_column($view->found(), 'status', 'label')['Fish']);
        self::assertNotContains('Fish', array_column($view->notDetected(), 'label'));
    }

    public function testAnAllergenIsOnlyPresentedAsFreeOnceEveryIngredientIsKnown(): void
    {
        self::assertFalse(array_column($this->view([Allergen::Fish])->notDetected(), 'free', 'label')['Fish']);

        $analysis = Analyses::gratin()->withDeclared(['zgougou' => []], new AllergenVerdictEngine());
        $view = RecipeView::of($analysis, 'https://www.example.com/recipes/gratin', [Allergen::Fish], declared: ['zgougou']);

        self::assertTrue(array_column($view->notDetected(), 'free', 'label')['Fish']);
    }

    public function testAnIngredientDeclaredWithoutAllergenStaysEditable(): void
    {
        $analysis = Analyses::gratin()->withDeclared(['zgougou' => []], new AllergenVerdictEngine());
        $view = RecipeView::of($analysis, 'https://www.example.com/recipes/gratin', declared: ['zgougou']);

        self::assertSame('', $view->ingredients()[2]['note']);
        self::assertSame([['name' => 'zgougou', 'declared' => true, 'allergens' => []]], $view->ingredients()[2]['unidentified']);
    }

    public function testBeforeTheAnalysisOnlyTheTextOfThePageIsShown(): void
    {
        $view = RecipeView::withoutAnalysis(Analyses::gratin()->recipe, 'https://www.example.com/recipes/gratin');

        self::assertTrue($view->isPending());
        self::assertSame('example.com · Serves 6 · 1 h 15', $view->meta());
        self::assertSame(['100 g', '1', ''], array_column($view->ingredients(), 'quantity'));
        self::assertSame(['de beurre', 'cube de bouillon', 'du zgougou'], array_column($view->ingredients(), 'name'));
        self::assertSame(['', '', ''], array_column($view->ingredients(), 'note'));
        self::assertSame(['Mélanger.', 'Cuire.'], array_column($view->steps(), 'text'));
        self::assertSame([], $view->found());
        self::assertSame([], $view->notDetected());
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
        self::assertSame(['quantity' => '100 ml', 'name' => 'Sunflower oil', 'role' => 'brings the fat', 'was' => 'beurre'], $view->ingredients()[0]['swap']);
        self::assertNull($view->ingredients()[1]['swap']);
        self::assertSame([['number' => 1, 'text' => 'Mélanger avec l’huile.', 'adapted' => true, 'pending' => false], ['number' => 2, 'text' => 'Cuire.', 'adapted' => false, 'pending' => false]], $view->steps());
        self::assertSame(1, $view->swapCount());
        self::assertSame(1, $view->adaptedStepCount());

        $milk = array_column($view->found(), null, 'label')['Milk'];
        self::assertTrue($milk['swapped']);
        self::assertSame('Swapped out of beurre', $milk['status']);
    }

    public function testWhileTheSwapsAreFoundTheLinesAndStepsConcernedWaitAndTheAllergenIsBusy(): void
    {
        $view = RecipeView::of(Analyses::gratin(['Faire fondre le beurre.', 'Cuire.']), 'https://example.com/gratin', [Allergen::Milk], adapting: true);

        self::assertTrue($view->isAdapting());
        self::assertSame(['dairy-free', 'dairy-free', null], array_column($view->ingredients(), 'pending'));
        self::assertSame([true, false], array_column($view->steps(), 'pending'));
        self::assertSame(1, $view->pendingStepCount());

        $milk = array_column($view->found(), null, 'label')['Milk'];
        self::assertTrue($milk['busy']);
        self::assertSame('Swapping beurre, bouillon…', $milk['status']);
    }

    public function testALineWithoutASafeSwapIsReportedAndItsAllergenStaysFound(): void
    {
        $view = $this->view([Allergen::Milk], new Adaptation([], [new AdaptedStep('Mélanger.'), new AdaptedStep('Cuire.')], [0]));

        self::assertSame('Sans couldn’t find a safe swap for this one.', $view->ingredients()[0]['failed']);
        $milk = array_column($view->found(), null, 'label')['Milk'];
        self::assertFalse($milk['swapped']);
        self::assertSame('In beurre', $milk['status']);
    }

    public function testAnIngredientThatOnlyMayCarryTheAllergenAsksToReadTheLabel(): void
    {
        $view = $this->view([Allergen::Celery], new Adaptation([], [new AdaptedStep('Mélanger.'), new AdaptedStep('Cuire.')], [1]));

        self::assertSame('May be in bouillon · check the label', array_column($view->found(), null, 'label')['Celery']['status']);
        self::assertSame('No sure swap for this one: check the label.', $view->ingredients()[1]['failed']);
    }

    public function testOnlyTheLinesThatMayCarryAnAvoidedAllergenAskForTheLabelsOfRealProducts(): void
    {
        self::assertSame([false, true, false], array_column($this->view([Allergen::Celery])->ingredients(), 'mayCarry'));
        self::assertSame([false, false, false], array_column($this->view([Allergen::Fish])->ingredients(), 'mayCarry'));
        self::assertSame(['celery'], $this->view([Allergen::Celery])->avoided());
    }

    public function testAnIngredientThatOnlyMayCarryTheAllergenCanBeSwappedToo(): void
    {
        $view = $this->view([Allergen::Celery], new Adaptation([new Swap(1, [Allergen::Celery], '50 cl', 'Homemade stock', Role::Flavour)], [new AdaptedStep('Mélanger.'), new AdaptedStep('Cuire.')]));

        self::assertSame('Swapped out of bouillon', array_column($view->found(), null, 'label')['Celery']['status']);
        self::assertSame('brings the flavour', $view->ingredients()[1]['swap']['role'] ?? null);
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
