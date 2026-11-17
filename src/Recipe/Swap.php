<?php

declare(strict_types=1);

namespace App\Recipe;

use App\Enum\Allergen;

final readonly class Swap
{
    /**
     * @param int            $line      the position of the ingredient line that is replaced
     * @param list<Allergen> $allergens the avoided allergens this swap gets rid of
     * @param string         $why       one sentence explaining the swap
     */
    public function __construct(
        public int $line,
        public array $allergens,
        public string $quantity,
        public string $name,
        public string $why,
    ) {
    }
}
