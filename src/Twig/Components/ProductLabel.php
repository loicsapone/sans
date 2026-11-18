<?php

declare(strict_types=1);

namespace App\Twig\Components;

use App\Enum\Allergen;
use App\Labels\ProductLabels;
use App\Recipe\AnalysisStore;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Under an ingredient line that only may carry an avoided allergen: what the labels of real products say.
 * Asking Open Food Facts is slow, so the recipe page loads this component after the rest (loading="defer").
 */
#[AsLiveComponent]
final class ProductLabel
{
    use DefaultActionTrait;

    #[LiveProp]
    public string $url = '';

    /** the position of the ingredient line in the recipe */
    #[LiveProp]
    public int $line = 0;

    /** @var list<string> */
    #[LiveProp]
    public array $avoided = [];

    public function __construct(
        private readonly AnalysisStore $store,
        private readonly ProductLabels $labels,
    ) {
    }

    /**
     * @return list<string>
     */
    public function sentences(): array
    {
        $analysis = $this->store->find($this->url);
        $ingredients = [];
        foreach ($analysis?->lines[$this->line]->names ?? [] as $name) {
            $ingredients[] = $analysis->ingredients[$name] ?? null;
        }

        $sentences = [];
        foreach ($this->labels->of(array_values(array_filter($ingredients)), array_values(array_filter(array_map(Allergen::tryFrom(...), $this->avoided)))) as $ofTheIngredient) {
            foreach ($ofTheIngredient as $label) {
                $sentences[] = \sprintf('On Open Food Facts, %d of %d products in “%s” declare %s.', $label['declaring'], $label['total'], mb_trim($label['category']), mb_strtolower(Allergen::from($label['allergen'])->label()));
            }
        }

        return $sentences;
    }
}
