<?php

declare(strict_types=1);

namespace App\Recipe;

/**
 * An analysis stopped at one stage (fetch, extract, normalise), with a message that can be shown as is.
 */
final class AnalysisFailed extends \RuntimeException
{
    public function __construct(
        public readonly string $stage,
        string $message,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
