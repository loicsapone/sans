<?php

declare(strict_types=1);

namespace App\Matching;

final class MatchChoice
{
    /**
     * @param string   $name the requested name, copied as given
     * @param int|null $id   the id of the chosen candidate, null when no candidate is the same ingredient
     */
    public function __construct(
        public string $name = '',
        public ?int $id = null,
    ) {
    }
}
