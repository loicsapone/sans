<?php

declare(strict_types=1);

namespace App\Enum;

enum Certainty: string
{
    case Always = 'always';
    case Depends = 'depends';
}
