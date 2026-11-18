<?php

declare(strict_types=1);

namespace App\Enum;

enum Role: string
{
    case Binder = 'binder';
    case Leavening = 'leavening';
    case Fat = 'fat';
    case Thickener = 'thickener';
    case Liquid = 'liquid';
    case Flavour = 'flavour';
    case Base = 'base';

    public function label(): string
    {
        return match ($this) {
            self::Binder => 'binds the mix',
            self::Leavening => 'makes it rise',
            self::Fat => 'brings the fat',
            self::Thickener => 'thickens',
            self::Liquid => 'brings the liquid',
            self::Flavour => 'brings the flavour',
            self::Base => 'the body of the dish',
        };
    }
}
