<?php

declare(strict_types=1);

namespace App\Domain;

use App\Enum\Allergen;
use App\Enum\Verdict;

final readonly class AllergenVerdict
{
    /**
     * @param list<string> $ingredients the recipe ingredients behind the verdict
     */
    public function __construct(
        public Allergen $allergen,
        public Verdict $verdict,
        public array $ingredients = [],
    ) {
    }
}
