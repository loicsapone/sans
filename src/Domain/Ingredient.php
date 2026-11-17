<?php

declare(strict_types=1);

namespace App\Domain;

use App\Enum\Certainty;

final readonly class Ingredient
{
    /**
     * @param array<string, Certainty> $certainties allergen value => certainty, absent when the ingredient does not carry the allergen
     */
    public function __construct(
        public int $id,
        public string $name,
        public string $nameEn,
        public array $certainties,
    ) {
    }
}
