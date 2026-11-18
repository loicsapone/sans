<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Labels\ProductCategories;
use App\Labels\ProductCategory;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Execution\Execution;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\AI\Agent\Execution\Update\Result;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\ObjectResult;

final class LabelsAgent implements AgentInterface
{
    public function call(string|MessageBag|UserMessage $input, array $options = []): ExecutionInterface
    {
        $categories = [];
        foreach (\array_slice(explode("\n", \is_string($input) ? $input : ''), 1) as $name) {
            $categories[] = 'stock' === $name ? new ProductCategory($name, 'en:bouillon-cubes', 'Stock cubes') : new ProductCategory($name);
        }
        $result = new ObjectResult(new ProductCategories($categories));

        return new Execution(static fn (): \Generator => yield new Result($result, new MessageBag()));
    }

    public function getName(): string
    {
        return 'labels';
    }
}
