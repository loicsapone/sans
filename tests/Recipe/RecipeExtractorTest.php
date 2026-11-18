<?php

declare(strict_types=1);

namespace App\Tests\Recipe;

use App\Recipe\AnalysisFailed;
use App\Recipe\Recipe;
use App\Recipe\RecipeExtractor;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Execution\Execution;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\AI\Agent\Execution\Update\Result;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\ObjectResult;

final class RecipeExtractorTest extends TestCase
{
    private function html(string $fixture): string
    {
        return (string) file_get_contents(__DIR__.'/../fixtures/recipes/'.$fixture.'.html');
    }

    private function extractor(ExtractorAgent $agent): RecipeExtractor
    {
        return new RecipeExtractor($agent);
    }

    public function testReadsTheSchemaOrgDataOfAPageWithoutAskingTheModel(): void
    {
        $agent = new ExtractorAgent(new Recipe());
        $recipe = $this->extractor($agent)->extract($this->html('jsonld'));

        self::assertSame('Gratin dauphinois', $recipe->title);
        self::assertSame(6, $recipe->servings);
        self::assertSame(75, $recipe->minutes);
        self::assertSame(['1 kg de pommes de terre', '50 cl de crème fraîche', "1 gousse d'ail", 'une noix de beurre'], $recipe->ingredients);
        self::assertSame(['Éplucher et couper les pommes de terre.', 'Les enfourner 1 heure à 180 °C.'], $recipe->steps);
        self::assertSame([], $agent->asked);
    }

    public function testFindsTheRecipeInAGraphAndFlattensItsSections(): void
    {
        $recipe = $this->extractor(new ExtractorAgent(new Recipe()))->extract($this->html('graph'));

        self::assertSame('Pancakes & maple syrup', $recipe->title);
        self::assertSame(8, $recipe->servings);
        self::assertSame(30, $recipe->minutes);
        self::assertSame(['2 cups flour'], $recipe->ingredients);
        self::assertSame(['Whisk the flour and the milk.', 'Fry in a hot pan.'], $recipe->steps);
    }

    public function testServingsAndTimeStayEmptyWhenThePageDoesNotSayOrSaysItBadly(): void
    {
        $html = '<script type="application/ld+json">{"@type":"Recipe","name":"Soup","recipeIngredient":["1 leek"],"totalTime":"soon"}</script>';

        $recipe = $this->extractor(new ExtractorAgent(new Recipe()))->extract($html);

        self::assertNull($recipe->servings);
        self::assertNull($recipe->minutes);
    }

    public function testSendsOnlyTheReadablePartOfThePageToTheModel(): void
    {
        $agent = new ExtractorAgent(new Recipe('Crêpes de Mamie', ['250 g de farine', '3 œufs']));
        $html = $this->html('blog');
        $this->extractor($agent)->extract($html);

        self::assertCount(1, $agent->asked);
        $sent = $agent->asked[0];
        self::assertStringStartsWith('Crêpes de Mamie', $sent);
        foreach (['250 g de farine', '1 cuillère à soupe de rhum (facultatif)', 'Pour 15 crêpes environ', 'Cuire les crêpes une à une'] as $kept) {
            self::assertStringContainsString($kept, $sent);
        }
        foreach (['cookies', 'Accueil', 'fleur d\'oranger', 'Gaufres aux noisettes', 'newsletter', 'Mentions légales', 'trackingScript'] as $noise) {
            self::assertStringNotContainsString($noise, $sent);
        }
        self::assertLessThan(mb_strlen(strip_tags($html)) / 2, mb_strlen($sent));
    }

    public function testFallsBackToTheWholeTextWhenThePageIsTooShortToBeRead(): void
    {
        $agent = new ExtractorAgent(new Recipe('Crêpes de Mamie', ['250 g de farine', '3 œufs']));
        $recipe = $this->extractor($agent)->extract($this->html('no-schema'));

        self::assertSame(['250 g de farine', '3 œufs'], $recipe->ingredients);
        self::assertCount(1, $agent->asked);
        self::assertStringContainsString('250 g de farine', $agent->asked[0]);
        self::assertStringNotContainsString('trackingScript', $agent->asked[0]);
        self::assertStringNotContainsString('Mentions légales', $agent->asked[0]);
    }

    public function testNeverInventsARecipe(): void
    {
        try {
            $this->extractor(new ExtractorAgent(new Recipe()))->extract($this->html('no-schema'));
            self::fail('A page without recipe should be refused.');
        } catch (AnalysisFailed $e) {
            self::assertSame('extract', $e->stage);
        }
    }

    public function testAModelFailureIsReportedAsAFailedExtraction(): void
    {
        $this->expectException(AnalysisFailed::class);

        $this->extractor(new ExtractorAgent(new RuntimeException('The platform is down.')))->extract($this->html('no-schema'));
    }
}

/**
 * An agent that answers with the given recipe (or fails) and remembers what it was asked to read.
 */
final class ExtractorAgent implements AgentInterface
{
    /** @var list<string> */
    public array $asked = [];

    public function __construct(private readonly Recipe|\Throwable $answer)
    {
    }

    public function call(string|MessageBag|UserMessage $input, array $options = []): ExecutionInterface
    {
        $this->asked[] = \is_string($input) ? $input : '';
        if ($this->answer instanceof \Throwable) {
            throw $this->answer;
        }

        return new Execution(fn (): \Generator => yield new Result(new ObjectResult($this->answer), new MessageBag()));
    }

    public function getName(): string
    {
        return 'extractor';
    }
}
