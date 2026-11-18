<?php

declare(strict_types=1);

namespace App\Labels;

final class ProductCategories
{
    /**
     * @param list<ProductCategory> $categories one entry per ingredient
     */
    public function __construct(
        public array $categories = [],
    ) {
    }
}
