<?php

declare(strict_types=1);

namespace App\Recipe;

use App\Domain\AllergenVerdictEngine;
use App\Matching\IngredientMatcher;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Exception\ExceptionInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * From the address of a recipe to the allergen verdicts: fetch, extract, normalise, match, evaluate.
 */
final readonly class RecipeAnalyzer
{
    public function __construct(
        private RecipeFetcher $fetcher,
        private RecipeExtractor $extractor,
        #[Autowire(service: 'ai.agent.normalizer')]
        private AgentInterface $normalizer,
        private IngredientMatcher $matcher,
        private AllergenVerdictEngine $engine,
    ) {
    }

    /**
     * @throws AnalysisFailed with the stage that failed
     */
    public function analyze(string $url): Analysis
    {
        $durations = [];
        $lap = microtime(true);
        $stage = static function (string $name) use (&$durations, &$lap): void {
            $durations[$name] = microtime(true) - $lap;
            $lap = microtime(true);
        };

        $html = $this->fetcher->fetch($url);
        $stage('fetch');

        $recipe = $this->extractor->extract($html);
        $stage('extract');

        $lines = $this->normalize($recipe->ingredients);
        $names = array_values(array_unique(array_filter(array_merge(...array_map(static fn (IngredientLine $line): array => $line->names, $lines)))));
        if ([] === $names) {
            throw new AnalysisFailed('normalise', 'No ingredient could be read from the recipe.');
        }
        $stage('normalise');

        try {
            $ingredients = $this->matcher->match($names);
        } catch (ExceptionInterface $e) {
            throw new AnalysisFailed('match', 'The ingredients could not be looked up.', $e);
        }
        $verdicts = $this->engine->evaluate($ingredients);
        $stage('match');

        return new Analysis($recipe, $lines, $ingredients, $verdicts, $durations);
    }

    /**
     * @param list<string> $ingredients
     *
     * @return list<IngredientLine>
     */
    private function normalize(array $ingredients): array
    {
        try {
            $result = $this->normalizer->call(implode("\n", $ingredients), ['response_format' => IngredientLines::class])->getResult()->getContent();
        } catch (ExceptionInterface $e) {
            throw new AnalysisFailed('normalise', 'The ingredients could not be read.', $e);
        }

        if (!$result instanceof IngredientLines || \count($result->lines) !== \count($ingredients)) {
            throw new AnalysisFailed('normalise', 'The ingredients could not be read.');
        }

        return $result->lines;
    }
}
