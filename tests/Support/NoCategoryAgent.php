<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Labels\ProductCategories;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Execution\Execution;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\AI\Agent\Execution\Update\Result;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\ObjectResult;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

/**
 * The labels agent of the test container: it finds no category, so Open Food Facts is never asked.
 */
#[AsDecorator('ai.agent.labels')]
final class NoCategoryAgent implements AgentInterface
{
    public function call(string|MessageBag|UserMessage $input, array $options = []): ExecutionInterface
    {
        return new Execution(static fn (): \Generator => yield new Result(new ObjectResult(new ProductCategories([])), new MessageBag()));
    }

    public function getName(): string
    {
        return 'labels';
    }
}
