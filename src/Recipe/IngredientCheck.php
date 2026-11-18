<?php

declare(strict_types=1);

namespace App\Recipe;

use App\Matching\IngredientMatcher;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;

#[AsTool('check_ingredients', description: 'Looks up ingredient names in the ingredient reference. Give all the names at once: it says, for each name, whether it is known and which allergens it contains (always) or may contain (depends).')]
final readonly class IngredientCheck
{
    public function __construct(private IngredientMatcher $matcher)
    {
    }

    /**
     * @param list<string> $names the plain ingredient names to check
     *
     * @return array<string, array{known: bool, allergens: array<string, string>}>
     */
    public function __invoke(array $names): array
    {
        $checked = [];
        foreach ($this->matcher->match($names) as $name => $ingredient) {
            $allergens = [];
            foreach ($ingredient->certainties ?? [] as $allergen => $certainty) {
                $allergens[$allergen] = $certainty->value;
            }
            $checked[$name] = ['known' => null !== $ingredient, 'allergens' => $allergens];
        }

        return $checked;
    }
}
