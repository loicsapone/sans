<?php

declare(strict_types=1);

namespace App\Recipe;

final class IngredientLine
{
    /**
     * @param string       $raw      the line exactly as it was given
     * @param string       $label    the ingredient as it should be shown, without quantity or unit ("butter, softened")
     * @param list<string> $names    the base name of the ingredient, without quantity, unit or preparation: one entry per alternative ("butter or margarine" gives two)
     * @param float|null   $quantity the amount as a number, null when there is none
     * @param string|null  $unit     the unit as written (g, cl, c. à soupe...), null when there is none
     * @param bool         $optional whether the line says the ingredient is optional
     */
    public function __construct(
        public string $raw = '',
        public string $label = '',
        public array $names = [],
        public ?float $quantity = null,
        public ?string $unit = null,
        public bool $optional = false,
    ) {
    }
}
