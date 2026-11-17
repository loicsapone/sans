<?php

declare(strict_types=1);

namespace App\Tests\Recipe;

use App\Enum\Allergen;
use App\Matching\IngredientMatcher;
use App\Recipe\AdaptationAnswer;
use App\Recipe\RecipeAdapter;
use App\Recipe\StepChange;
use App\Recipe\SwapAnswer;
use App\Tests\Support\Analyses;
use App\Tests\Support\MatchingAgent;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Execution\Execution;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\AI\Agent\Execution\Update\Result;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\ObjectResult;

final class RecipeAdapterTest extends TestCase
{
    /** @var \ArrayObject<int, string> */
    private \ArrayObject $prompts;

    protected function setUp(): void
    {
        $this->prompts = new \ArrayObject();
    }

    /**
     * An adapter whose agent gives the answers one after the other, the reference knowing olive oil and cream (milk).
     *
     * @param list<AdaptationAnswer> $answers
     */
    private function adapter(array $answers): RecipeAdapter
    {
        $agent = new class($answers, $this->prompts) implements AgentInterface {
            /**
             * @param list<AdaptationAnswer>    $answers
             * @param \ArrayObject<int, string> $prompts
             */
            public function __construct(private array $answers, private readonly \ArrayObject $prompts)
            {
            }

            public function call(string|MessageBag|UserMessage $input, array $options = []): ExecutionInterface
            {
                $this->prompts[] = \is_string($input) ? $input : '';
                $result = new ObjectResult(array_shift($this->answers) ?? new AdaptationAnswer());

                return new Execution(static fn (): \Generator => yield new Result($result, new MessageBag()));
            }

            public function getName(): string
            {
                return 'adapter';
            }
        };

        return new RecipeAdapter($agent, new IngredientMatcher(new MatchingAgent()));
    }

    private function prompt(int $index): string
    {
        return (string) $this->prompts[$index];
    }

    /**
     * @param list<string> $ingredients
     */
    private function answer(array $ingredients): AdaptationAnswer
    {
        return new AdaptationAnswer(
            [new SwapAnswer(0, '80 g', 'Olive oil', 'Fat that keeps the dish moist.', $ingredients)],
            [new StepChange(1, 'Mélanger avec l’huile.')],
        );
    }

    public function testASwapMadeOfKnownIngredientsWithoutTheAllergenIsAccepted(): void
    {
        $adaptation = $this->adapter([$this->answer(['olive oil'])])->adapt(Analyses::gratin(), [Allergen::Milk]);

        self::assertCount(1, $adaptation->swaps);
        self::assertSame([0, [Allergen::Milk], 'Olive oil'], [$adaptation->swaps[0]->line, $adaptation->swaps[0]->allergens, $adaptation->swaps[0]->name]);
        self::assertSame([], $adaptation->failed);
        self::assertTrue($adaptation->steps[0]->adapted);
        self::assertStringContainsString('0: 100 g de beurre (remove: Milk)', $this->prompt(0));
        // the stock cube only may contain milk: the label has to be read, no swap is asked for it
        self::assertStringNotContainsString('bouillon', explode('Steps of the method', $this->prompt(0))[0]);
    }

    public function testASwapMadeOfAnIngredientTheReferenceDoesNotKnowIsAskedAgain(): void
    {
        $adaptation = $this->adapter([$this->answer(['unobtainium']), $this->answer(['olive oil'])])->adapt(Analyses::gratin(), [Allergen::Milk]);

        self::assertCount(2, $this->prompts);
        self::assertStringContainsString('unobtainium is not in the ingredient reference', $this->prompt(1));
        self::assertCount(1, $adaptation->swaps);
    }

    public function testASwapThatStillContainsTheAllergenIsGivenUpAfterThreeAttempts(): void
    {
        $adaptation = $this->adapter([$this->answer(['cream']), $this->answer(['cream']), $this->answer(['cream'])])->adapt(Analyses::gratin(), [Allergen::Milk]);

        self::assertCount(3, $this->prompts);
        self::assertStringContainsString('cream contains or may contain Milk', $this->prompt(1));
        self::assertSame([], $adaptation->swaps);
        self::assertSame([0], $adaptation->failed);
    }

    public function testASwapWithoutIngredientsIsRefused(): void
    {
        $adaptation = $this->adapter([$this->answer([]), $this->answer(['olive oil'])])->adapt(Analyses::gratin(), [Allergen::Milk]);

        self::assertStringContainsString('it names no ingredient', $this->prompt(1));
        self::assertCount(1, $adaptation->swaps);
    }

    public function testOnlyTheStepsTheAgentChangedAreMarkedAndAnUnknownNumberIsIgnored(): void
    {
        $answer = new AdaptationAnswer([new SwapAnswer(0, '80 g', 'Olive oil', 'Why.', ['olive oil'])], [new StepChange(2, 'Cuire avec l’huile.'), new StepChange(9, 'Ghost.')]);

        $adaptation = $this->adapter([$answer])->adapt(Analyses::gratin(), [Allergen::Milk]);

        self::assertSame(['Mélanger.', 'Cuire avec l’huile.'], array_map(static fn ($step): string => $step->text, $adaptation->steps));
        self::assertSame([false, true], array_map(static fn ($step): bool => $step->adapted, $adaptation->steps));
    }

    public function testNothingIsAskedWhenNoLineCarriesTheAllergenForSure(): void
    {
        $adaptation = $this->adapter([])->adapt(Analyses::gratin(), [Allergen::Celery]);

        self::assertCount(0, $this->prompts);
        self::assertSame([], $adaptation->swaps);
    }
}
