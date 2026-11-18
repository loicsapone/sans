<?php

declare(strict_types=1);

namespace App\Recipe;

use App\Domain\Ingredient;
use App\Enum\Allergen;
use App\Enum\Certainty;
use App\Enum\Verdict;

final readonly class RecipeView
{
    /**
     * @param list<Allergen> $avoided
     */
    private function __construct(
        private Recipe $recipe,
        private string $url,
        private ?Analysis $analysis = null,
        private array $avoided = [],
        private ?Adaptation $adaptation = null,
        private bool $original = false,
        private bool $adapting = false,
    ) {
    }

    /**
     * @param list<Allergen> $avoided
     */
    public static function of(Analysis $analysis, string $url, array $avoided = [], ?Adaptation $adaptation = null, bool $original = false, bool $adapting = false): self
    {
        return new self($analysis->recipe, $url, $analysis, $avoided, $adaptation, $original, $adapting);
    }

    public static function withoutAnalysis(Recipe $recipe, string $url): self
    {
        return new self($recipe, $url);
    }

    public function url(): string
    {
        return $this->url;
    }

    /**
     * @return list<string> the values of the avoided allergens
     */
    public function avoided(): array
    {
        return array_column($this->avoided, 'value');
    }

    public function isPending(): bool
    {
        return null === $this->analysis;
    }

    public function title(): string
    {
        return '' !== $this->recipe->title ? $this->recipe->title : 'Recipe';
    }

    /**
     * "example.com · Serves 8 · 1 h 15".
     */
    public function meta(): string
    {
        $recipe = $this->recipe;
        $parts = [preg_replace('/^www\./', '', (string) parse_url($this->url, \PHP_URL_HOST))];
        if (null !== $recipe->servings) {
            $parts[] = 'Serves '.$recipe->servings;
        }
        if (null !== $recipe->minutes) {
            $parts[] = $recipe->minutes < 60 ? $recipe->minutes.' min' : intdiv($recipe->minutes, 60).' h'.(0 === $recipe->minutes % 60 ? '' : ' '.$recipe->minutes % 60);
        }

        return implode(' · ', array_filter($parts));
    }

    public function hasAdaptation(): bool
    {
        return null !== $this->adaptation && [] !== $this->adaptation->swaps;
    }

    public function showsOriginal(): bool
    {
        return $this->original;
    }

    /**
     * @return list<array{quantity: string, name: string, note: string, swap: array{quantity: string, name: string, role: string, was: string}|null, pending: string|null, failed: string|null, mayCarry: bool}>
     */
    public function ingredients(): array
    {
        if (null === $this->analysis) {
            return array_map(static fn (string $line): array => ['quantity' => '', 'name' => $line, 'note' => '', 'swap' => null, 'pending' => null, 'failed' => null, 'mayCarry' => false], $this->recipe->ingredients);
        }

        $analysis = $this->analysis;
        $swaps = [];
        foreach ($this->adapted()->swaps ?? [] as $swap) {
            $swaps[$swap->line] = $swap;
        }

        $pending = $this->pendingLines();
        $failed = [];
        foreach ($this->adapted()->failed ?? [] as $position) {
            // a line that only may carry the allergen is not a failure: its label still has the answer
            $failed[$position] = \in_array(Certainty::Always, $analysis->linesCarrying($this->avoided)[$position] ?? [], true)
                ? 'Sans couldn’t find a safe swap for this one.'
                : 'No sure swap for this one: check the label.';
        }

        $rows = [];
        foreach ($analysis->lines as $position => $line) {
            $found = array_map(static fn (string $name): ?Ingredient => $analysis->ingredients[$name] ?? null, $line->names);
            $name = ('' !== $line->label ? $line->label : $line->raw).($line->optional ? ' (optional)' : '');
            $swap = $swaps[$position] ?? null;

            $rows[] = [
                'quantity' => $this->quantity($line),
                'name' => $name,
                'note' => $this->note($found),
                'swap' => null === $swap ? null : ['quantity' => $swap->quantity, 'name' => $swap->name, 'role' => $swap->role->label(), 'was' => $line->names[0] ?? $name],
                'pending' => isset($pending[$position]) ? implode(', ', array_map(static fn (string $allergen): string => Allergen::from($allergen)->freeLabel(), array_keys($pending[$position]))) : null,
                'failed' => $failed[$position] ?? null,
                // only the label of a real product can tell: Open Food Facts is asked about them
                'mayCarry' => \in_array(Certainty::Depends, $analysis->linesCarrying($this->avoided)[$position] ?? [], true),
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{number: int, text: string, adapted: bool, pending: bool}>
     */
    public function steps(): array
    {
        $steps = $this->adapted()->steps ?? array_map(static fn (string $text): AdaptedStep => new AdaptedStep($text), $this->recipe->steps);
        $names = $this->namesOf($this->pendingLines());

        $rows = [];
        foreach ($steps as $i => $step) {
            $rows[] = [
                'number' => $i + 1,
                'text' => $step->text,
                'adapted' => $step->adapted,
                'pending' => $this->adapting && [] !== array_filter($names, static fn (string $name): bool => false !== mb_stripos($step->text, $name)),
            ];
        }

        return $rows;
    }

    public function isAdapting(): bool
    {
        return $this->adapting && [] !== $this->pendingLines();
    }

    public function pendingStepCount(): int
    {
        return \count(array_filter($this->steps(), static fn (array $step): bool => $step['pending']));
    }

    /**
     * @return list<array{allergen: Allergen, label: string, active: bool, swapped: bool, busy: bool, status: string, note: string|null}>
     */
    public function found(): array
    {
        return array_values(array_filter($this->allergens(), fn (array $row): bool => $this->isFound($row['allergen'])));
    }

    /**
     * @return list<array{allergen: Allergen, label: string, active: bool, swapped: bool, busy: bool, status: string, note: string|null}>
     */
    public function notDetected(): array
    {
        return array_values(array_filter($this->allergens(), fn (array $row): bool => !$this->isFound($row['allergen'])));
    }

    public function swapCount(): int
    {
        return \count($this->adapted()->swaps ?? []);
    }

    public function adaptedStepCount(): int
    {
        return \count(array_filter($this->adapted()->steps ?? [], static fn (AdaptedStep $step): bool => $step->adapted));
    }

    public function unidentifiedCount(): int
    {
        if (null === $this->analysis) {
            return 0;
        }

        return \count(array_filter($this->analysis->ingredients, static fn (?Ingredient $ingredient): bool => null === $ingredient));
    }

    private function adapted(): ?Adaptation
    {
        return $this->original ? null : $this->adaptation;
    }

    private function isFound(Allergen $allergen): bool
    {
        return null !== $this->analysis && \in_array($this->analysis->verdicts[$allergen->value]->verdict, [Verdict::Detected, Verdict::DependsOnProduct], true);
    }

    /**
     * @return list<array{allergen: Allergen, label: string, active: bool, swapped: bool, busy: bool, status: string, note: string|null}>
     */
    private function allergens(): array
    {
        if (null === $this->analysis) {
            return [];
        }

        $covered = [];
        foreach ($this->adapted()->swaps ?? [] as $swap) {
            foreach ($swap->allergens as $allergen) {
                $covered[$allergen->value] = true;
            }
        }

        $pending = $this->pendingLines();
        $unresolved = [];
        foreach ($this->adapted()->failed ?? [] as $position) {
            $unresolved += $this->analysis->linesCarrying($this->avoided)[$position] ?? [];
        }

        $verdicts = $this->analysis->verdicts;
        $rows = [];
        foreach (Allergen::cases() as $allergen) {
            $verdict = $verdicts[$allergen->value];
            $sources = implode(', ', $verdict->ingredients);
            $active = \in_array($allergen, $this->avoided, true);
            $swapped = $active && isset($covered[$allergen->value]) && !isset($unresolved[$allergen->value]);
            $busy = $active && $this->adapting && [] !== $this->namesOf($pending, $allergen);

            $rows[] = [
                'allergen' => $allergen,
                'label' => $allergen->label(),
                'active' => $active,
                'swapped' => $swapped,
                'busy' => $busy,
                'status' => match (true) {
                    $busy => 'Swapping '.implode(', ', \array_slice($this->namesOf($pending, $allergen), 0, 3)).'…',
                    $swapped => 'Swapped out of '.$sources,
                    Verdict::Detected === $verdict->verdict => 'In '.$sources,
                    Verdict::DependsOnProduct === $verdict->verdict => $active ? 'May be in '.$sources.' · check the label' : 'May be in '.$sources,
                    default => 'already free',
                },
                'note' => Verdict::Incomplete === $verdict->verdict ? 'Couldn’t identify: '.$sources : null,
            ];
        }

        return $rows;
    }

    /**
     * @return array<int, array<string, Certainty>>
     */
    private function pendingLines(): array
    {
        return $this->adapting && null !== $this->analysis ? $this->analysis->linesCarrying($this->avoided) : [];
    }

    /**
     * @param array<int, array<string, Certainty>> $pending
     *
     * @return list<string> the first name of each line to swap, or only of those that carry the allergen
     */
    private function namesOf(array $pending, ?Allergen $allergen = null): array
    {
        $names = [];
        foreach ($pending as $position => $certainties) {
            if (null === $allergen || isset($certainties[$allergen->value])) {
                $names[] = $this->analysis?->lines[$position]->names[0] ?? '';
            }
        }

        return array_values(array_filter($names));
    }

    private function quantity(IngredientLine $line): string
    {
        if (null === $line->quantity) {
            return '';
        }

        return mb_rtrim(mb_rtrim(number_format($line->quantity, 2, '.', ''), '0'), '.').(null === $line->unit ? '' : ' '.$line->unit);
    }

    /**
     * @param list<Ingredient|null> $found
     */
    private function note(array $found): string
    {
        $contains = $mayContain = [];
        foreach (Allergen::cases() as $allergen) {
            foreach ($found as $ingredient) {
                $certainty = $ingredient?->certainties[$allergen->value] ?? null;
                if (Certainty::Always === $certainty) {
                    $contains[$allergen->value] = $allergen->label();
                } elseif (Certainty::Depends === $certainty) {
                    $mayContain[$allergen->value] = $allergen->label().'?';
                }
            }
        }

        return implode(' · ', [...$contains, ...array_diff_key($mayContain, $contains)]);
    }
}
