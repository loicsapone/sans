<?php

declare(strict_types=1);

namespace App\Recipe;

final readonly class AdaptedStep
{
    public function __construct(
        public string $text,
        public bool $adapted = false,
    ) {
    }
}
