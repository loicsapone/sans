<?php

declare(strict_types=1);

namespace App\Recipe;

final class StepChange
{
    /**
     * @param int    $number the position of the step in the method, starting at 1
     * @param string $text   the step as it now reads
     */
    public function __construct(
        public int $number = 0,
        public string $text = '',
    ) {
    }
}
