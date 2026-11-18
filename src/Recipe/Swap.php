<?php

declare(strict_types=1);

namespace App\Recipe;

use App\Enum\Allergen;
use App\Enum\Role;

final readonly class Swap
{
    /**
     * @param int            $line      the position of the ingredient line that is replaced
     * @param list<Allergen> $allergens the avoided allergens this swap gets rid of
     * @param Role           $role      what the swap does in the recipe, in place of the ingredient
     */
    public function __construct(
        public int $line,
        public array $allergens,
        public string $quantity,
        public string $name,
        public Role $role,
    ) {
    }
}
