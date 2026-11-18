<?php

declare(strict_types=1);

namespace App\Labels;

final class ProductCategory
{
    /**
     * @param string      $name  the ingredient, copied as given
     * @param string|null $tag   the id of the Open Food Facts category, as the tool returned it ("en:bouillon-cubes"), null when none fits
     * @param string|null $label the name of that category, as the tool returned it ("Stock cubes")
     */
    public function __construct(
        public string $name = '',
        public ?string $tag = null,
        public ?string $label = null,
    ) {
    }
}
