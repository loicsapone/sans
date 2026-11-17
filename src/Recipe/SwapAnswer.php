<?php

declare(strict_types=1);

namespace App\Recipe;

final class SwapAnswer
{
    /**
     * @param int          $line        the number of the ingredient line that is replaced
     * @param string       $quantity    the amount of the replacement, as text ("250 g")
     * @param string       $name        the replacement as it should be shown
     * @param string       $why         one sentence saying why it works in this recipe
     * @param list<string> $ingredients the plain ingredients the replacement is made of ("rice flour", "soy drink")
     */
    public function __construct(
        public int $line = 0,
        public string $quantity = '',
        public string $name = '',
        public string $why = '',
        public array $ingredients = [],
    ) {
    }
}
