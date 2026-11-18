<?php

declare(strict_types=1);

namespace App\Tests\Matching;

use App\Enum\Allergen;
use App\Enum\Certainty;
use App\Matching\IngredientMatcher;
use App\Matching\MatchChoice;
use App\Matching\MatchChoices;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Bridge\SimilaritySearch\DocumentSource;
use Symfony\AI\Agent\Execution\Execution;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\AI\Agent\Execution\Update\Result;
use Symfony\AI\Agent\Toolbox\Source\SourceCollection;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\AI\Platform\Vector\Vector;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\VectorDocument;

final class IngredientMatcherTest extends TestCase
{
    private function matcher(?int $answer): IngredientMatcher
    {
        $butter = new VectorDocument('1', new Vector([1.0]), new Metadata(['name' => 'beurre', 'name_en' => 'butter', 'allergens' => ['milk' => 'always']]));
        $result = new ObjectResult(new MatchChoices([new MatchChoice('une noix de beurre', $answer)]));
        $result->getMetadata()->add('sources', new SourceCollection([new DocumentSource($butter)]));

        $agent = new class($result) implements AgentInterface {
            public function __construct(private readonly ObjectResult $result)
            {
            }

            public function call(string|MessageBag|UserMessage $input, array $options = []): ExecutionInterface
            {
                return new Execution(fn (): \Generator => yield new Result($this->result, new MessageBag()));
            }

            public function getName(): string
            {
                return 'matcher';
            }
        };

        return new IngredientMatcher($agent);
    }

    public function testTheAllergensComeFromTheIngredientTheToolReturned(): void
    {
        $ingredient = $this->matcher(1)->match(['une noix de beurre'])['une noix de beurre'];

        self::assertSame('beurre', $ingredient?->name);
        self::assertSame([Allergen::Milk->value => Certainty::Always], $ingredient->certainties);
    }

    public function testAnIdTheToolDidNotReturnIsRefused(): void
    {
        self::assertNull($this->matcher(42)->match(['une noix de beurre'])['une noix de beurre']);
    }

    public function testNoCandidateMeansNoMatch(): void
    {
        self::assertNull($this->matcher(null)->match(['une noix de beurre'])['une noix de beurre']);
    }
}
