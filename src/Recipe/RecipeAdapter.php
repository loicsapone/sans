<?php

declare(strict_types=1);

namespace App\Recipe;

use App\Domain\Ingredient;
use App\Enum\Allergen;
use App\Enum\Certainty;
use App\Matching\IngredientMatcher;
use Symfony\AI\Agent\AgentInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Rewrites a recipe without some allergens: an agent proposes the swaps, then each one is checked against the
 * reference (the replacement must be made of known ingredients that carry none of the avoided allergens).
 * The lines that only may carry an allergen are offered too: without a sure swap, the label still has to be read.
 */
final readonly class RecipeAdapter
{
    private const ATTEMPTS = 3;

    public function __construct(
        #[Autowire(service: 'ai.agent.adapter')]
        private AgentInterface $agent,
        private IngredientMatcher $matcher,
    ) {
    }

    /**
     * @param list<Allergen> $avoided
     */
    public function adapt(Analysis $analysis, array $avoided): Adaptation
    {
        $pending = $analysis->linesCarrying($avoided);
        $accepted = [];
        $refusals = [];
        $leftToTheLabel = [];
        $steps = [];

        for ($attempt = 0; $attempt < self::ATTEMPTS && [] !== $pending; ++$attempt) {
            $answer = $this->agent->call($this->prompt($analysis, $avoided, $pending, $accepted, $refusals), ['response_format' => AdaptationAnswer::class])->getResult()->getContent();
            if (!$answer instanceof AdaptationAnswer) {
                continue;
            }

            $proposed = array_filter($answer->swaps, static fn (SwapAnswer $swap): bool => isset($pending[$swap->line]));
            $names = array_values(array_unique(array_merge([], ...array_map(static fn (SwapAnswer $swap): array => $swap->ingredients, $proposed))));
            $found = [] === $names ? [] : $this->matcher->match($names);

            $refusals = array_fill_keys(array_keys($pending), 'no swap was proposed');
            foreach ($proposed as $swap) {
                $problem = $this->problem($swap, $avoided, $found);
                if (null !== $problem) {
                    $refusals[$swap->line] = $problem;
                    continue;
                }
                $accepted[$swap->line] = new Swap($swap->line, array_map(Allergen::from(...), array_keys($pending[$swap->line])), $swap->quantity, $swap->name, $swap->role);
                unset($pending[$swap->line], $refusals[$swap->line]);
            }
            $steps = $answer->steps;

            // the agent may leave out a line that only may carry the allergen: it is not asked again
            foreach ($pending as $position => $certainties) {
                if ('no swap was proposed' === $refusals[$position] && !\in_array(Certainty::Always, $certainties, true)) {
                    $leftToTheLabel[] = $position;
                    unset($pending[$position], $refusals[$position]);
                }
            }
        }

        ksort($accepted);
        $failed = [...array_keys($pending), ...$leftToTheLabel];
        sort($failed);

        return new Adaptation(array_values($accepted), $this->steps($analysis, $steps), $failed);
    }

    /**
     * @param list<Allergen>                 $avoided
     * @param array<string, Ingredient|null> $found
     */
    private function problem(SwapAnswer $swap, array $avoided, array $found): ?string
    {
        if ([] === $swap->ingredients) {
            return 'it names no ingredient';
        }

        foreach ($swap->ingredients as $name) {
            $ingredient = $found[$name] ?? null;
            if (null === $ingredient) {
                return $name.' is not in the ingredient reference, use a more common ingredient';
            }
            foreach ($avoided as $allergen) {
                if (isset($ingredient->certainties[$allergen->value])) {
                    return $name.' contains or may contain '.$allergen->label();
                }
            }
        }

        return null;
    }

    /**
     * The original steps, with those the agent changed replaced by their new text.
     *
     * @param list<StepChange> $changes
     *
     * @return list<AdaptedStep>
     */
    private function steps(Analysis $analysis, array $changes): array
    {
        $steps = array_map(static fn (string $text): AdaptedStep => new AdaptedStep($text), $analysis->recipe->steps);
        foreach ($changes as $change) {
            if (isset($steps[$change->number - 1])) {
                $steps[$change->number - 1] = new AdaptedStep($change->text, true);
            }
        }

        return $steps;
    }

    /**
     * @param list<Allergen>                       $avoided
     * @param array<int, array<string, Certainty>> $pending
     * @param array<int, Swap>                     $accepted
     * @param array<int, string>                   $refusals
     */
    private function prompt(Analysis $analysis, array $avoided, array $pending, array $accepted, array $refusals): string
    {
        $labels = static fn (array $allergens): string => implode(', ', array_map(static fn (Allergen $allergen): string => $allergen->label(), $allergens));

        $text = 'Allergens to avoid: '.$labels($avoided)."\n\nIngredient lines to replace:\n";
        foreach ($pending as $position => $certainties) {
            $sure = array_map(Allergen::from(...), array_keys($certainties, Certainty::Always, true));
            $maybe = array_map(Allergen::from(...), array_keys($certainties, Certainty::Depends, true));
            $what = implode(', ', array_filter([[] === $sure ? '' : 'remove: '.$labels($sure), [] === $maybe ? '' : 'may contain: '.$labels($maybe)]));
            $text .= $position.': '.$analysis->lines[$position]->raw.' ('.$what.')'.(isset($refusals[$position]) && 'no swap was proposed' !== $refusals[$position] ? ' - your last proposal was refused because '.$refusals[$position] : '')."\n";
        }

        if ([] !== $accepted) {
            $text .= "\nSwaps already decided:\n";
            foreach ($accepted as $swap) {
                $text .= $swap->line.': '.$analysis->lines[$swap->line]->raw.' becomes '.$swap->quantity.' '.$swap->name."\n";
            }
        }

        $text .= "\nSteps of the method:\n";
        foreach ($analysis->recipe->steps as $i => $step) {
            $text .= ($i + 1).'. '.$step."\n";
        }

        return $text;
    }
}
