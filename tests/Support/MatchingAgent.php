<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Matching\MatchChoice;
use App\Matching\MatchChoices;
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
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

#[AsDecorator('ai.agent.matcher')]
final class MatchingAgent implements AgentInterface
{
    private const INGREDIENTS = [
        'olive oil' => [10, 'huile d’olive', []],
        'cream' => [11, 'crème', ['milk' => 'always']],
    ];

    public function call(string|MessageBag|UserMessage $input, array $options = []): ExecutionInterface
    {
        $names = \array_slice(explode("\n", \is_string($input) ? $input : ''), 1);

        $choices = [];
        $sources = [];
        foreach ($names as $name) {
            [$id, $french, $allergens] = self::INGREDIENTS[$name] ?? [null, null, []];
            $choices[] = new MatchChoice($name, $id);
            if (null !== $id) {
                $sources[] = new DocumentSource(new VectorDocument((string) $id, new Vector([1.0]), new Metadata(['name' => $french, 'name_en' => $name, 'allergens' => $allergens])));
            }
        }

        $result = new ObjectResult(new MatchChoices($choices));
        $result->getMetadata()->add('sources', new SourceCollection($sources));

        return new Execution(static fn (): \Generator => yield new Result($result, new MessageBag()));
    }

    public function getName(): string
    {
        return 'matcher';
    }
}
