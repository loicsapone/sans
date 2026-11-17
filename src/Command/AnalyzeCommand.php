<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\Verdict;
use App\Recipe\AnalysisFailed;
use App\Recipe\RecipeAnalyzer;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'app:analyze', description: 'Show which allergens a recipe contains')]
final readonly class AnalyzeCommand
{
    public function __construct(private RecipeAnalyzer $analyzer)
    {
    }

    public function __invoke(
        SymfonyStyle $io,
        #[Argument(description: 'Address of the recipe page')]
        string $url,
    ): int {
        try {
            $analysis = $this->analyzer->analyze($url);
        } catch (AnalysisFailed $e) {
            $io->error(\sprintf('Stage "%s" failed: %s', $e->stage, $e->getMessage()));

            return 1;
        }

        $io->title($analysis->recipe->title ?: 'Recipe');

        $rows = [];
        foreach ($analysis->lines as $line) {
            $found = array_map(static fn (string $name): string => $analysis->ingredients[$name]->name ?? '?', $line->names);
            $rows[] = [$line->raw, implode(' / ', $line->names).($line->optional ? ' (optional)' : ''), implode(' / ', $found)];
        }
        $io->table(['Line', 'Names', 'Found in the reference'], $rows);

        $notDetected = [];
        foreach ($analysis->verdicts as $verdict) {
            if (Verdict::NotDetected === $verdict->verdict) {
                $notDetected[] = $verdict->allergen->value;

                continue;
            }
            $io->writeln(\sprintf('%-12s %-11s %s', $verdict->allergen->value, $verdict->verdict->value, implode(', ', $verdict->ingredients)));
        }
        $io->writeln('Not detected: '.implode(', ', $notDetected));

        $io->newLine();
        $io->writeln(implode(', ', array_map(static fn (string $stage, float $seconds): string => \sprintf('%s %.1f s', $stage, $seconds), array_keys($analysis->durations), $analysis->durations)).\sprintf(' (total %.1f s)', array_sum($analysis->durations)));

        return 0;
    }
}
