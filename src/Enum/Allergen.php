<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The 14 regulatory allergens of the EU (Regulation 1169/2011).
 * The values are the allergen column names of the ingredient reference CSV.
 */
enum Allergen: string
{
    case Gluten = 'gluten';
    case Crustaceans = 'crustaceans';
    case Eggs = 'eggs';
    case Fish = 'fish';
    case Peanuts = 'peanuts';
    case Soybeans = 'soybeans';
    case Milk = 'milk';
    case TreeNuts = 'tree-nuts';
    case Celery = 'celery';
    case Mustard = 'mustard';
    case Sesame = 'sesame';
    case Sulphites = 'sulphites';
    case Lupin = 'lupin';
    case Molluscs = 'molluscs';

    public function label(): string
    {
        return match ($this) {
            self::Gluten => 'Gluten',
            self::Crustaceans => 'Crustaceans',
            self::Eggs => 'Eggs',
            self::Fish => 'Fish',
            self::Peanuts => 'Peanuts',
            self::Soybeans => 'Soy',
            self::Milk => 'Milk',
            self::TreeNuts => 'Tree nuts',
            self::Celery => 'Celery',
            self::Mustard => 'Mustard',
            self::Sesame => 'Sesame',
            self::Sulphites => 'Sulphites',
            self::Lupin => 'Lupin',
            self::Molluscs => 'Molluscs',
        };
    }
}
