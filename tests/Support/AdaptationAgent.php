<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Enum\Role;
use App\Recipe\AdaptationAnswer;
use App\Recipe\StepChange;
use App\Recipe\SwapAnswer;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Execution\Execution;
use Symfony\AI\Agent\Execution\ExecutionInterface;
use Symfony\AI\Agent\Execution\Update\Result;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\ObjectResult;

/**
 * Stands for the adapter agent in the tests: swaps every line it is asked about for olive oil, and counts the calls.
 */
final class AdaptationAgent implements AgentInterface
{
    // static: every request of a test boots its own container, and so its own agent
    public static int $calls = 0;

    public function call(string|MessageBag|UserMessage $input, array $options = []): ExecutionInterface
    {
        ++self::$calls;
        preg_match_all('/^(\d+): .*\(remove:/m', \is_string($input) ? $input : '', $lines);
        $swaps = array_map(static fn (string $line): SwapAnswer => new SwapAnswer((int) $line, '80 ml', 'Olive oil', Role::Fat, ['olive oil']), $lines[1]);

        $result = new ObjectResult(new AdaptationAnswer($swaps, [new StepChange(1, 'Mélanger avec l’huile.')]));

        return new Execution(static fn (): \Generator => yield new Result($result, new MessageBag()));
    }

    public function getName(): string
    {
        return 'adapter';
    }
}
