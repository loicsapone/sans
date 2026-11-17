<?php

declare(strict_types=1);

namespace App\Matching;

final class MatchChoices
{
    /**
     * @param list<MatchChoice> $choices one entry per requested name
     */
    public function __construct(
        public array $choices = [],
    ) {
    }
}
