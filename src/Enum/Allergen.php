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

    /**
     * The tag of this allergen on Open Food Facts.
     */
    public function openFoodFactsTag(): string
    {
        return match ($this) {
            self::Soybeans, self::Gluten, self::Crustaceans, self::Eggs, self::Fish, self::Peanuts, self::Milk, self::Celery, self::Mustard, self::Lupin, self::Molluscs => 'en:'.$this->value,
            self::TreeNuts => 'en:nuts',
            self::Sesame => 'en:sesame-seeds',
            self::Sulphites => 'en:sulphur-dioxide-and-sulphites',
        };
    }

    /**
     * What a swap for this allergen is called ("dairy-free").
     */
    public function freeLabel(): string
    {
        return match ($this) {
            self::Gluten => 'gluten-free',
            self::Crustaceans => 'crustacean-free',
            self::Eggs => 'egg-free',
            self::Fish => 'fish-free',
            self::Peanuts => 'peanut-free',
            self::Soybeans => 'soy-free',
            self::Milk => 'dairy-free',
            self::TreeNuts => 'nut-free',
            self::Celery => 'celery-free',
            self::Mustard => 'mustard-free',
            self::Sesame => 'sesame-free',
            self::Sulphites => 'sulphite-free',
            self::Lupin => 'lupin-free',
            self::Molluscs => 'mollusc-free',
        };
    }
}
