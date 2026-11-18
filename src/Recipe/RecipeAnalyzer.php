<?php

declare(strict_types=1);

namespace App\Recipe;

use App\Domain\AllergenVerdictEngine;
use App\Matching\IngredientMatcher;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Platform\Exception\ExceptionInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

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
     * The first half of an analysis: the recipe of a page, which is what the visitor can read while the rest is computed.
     *
     * @throws AnalysisFailed with the stage that failed
     */
    public function read(string $url): Recipe
    {
        return $this->extractor->extract($this->fetcher->fetch($url));
    }

    /**
     * @throws AnalysisFailed with the stage that failed
     */
    public function analyse(Recipe $recipe): Analysis
    {
        $start = microtime(true);
        $lines = $this->normalize($recipe->ingredients);
        $names = array_values(array_unique(array_filter(array_merge(...array_map(static fn (IngredientLine $line): array => $line->names, $lines)))));
        if ([] === $names) {
            throw new AnalysisFailed('normalise', 'No ingredient could be read from the recipe.');
        }
        $normalised = microtime(true);

        try {
            $ingredients = $this->matcher->match($names);
        } catch (ExceptionInterface $e) {
            throw new AnalysisFailed('match', 'The ingredients could not be looked up.', $e);
        }
        $verdicts = $this->engine->evaluate($ingredients);

        return new Analysis($recipe, $lines, $ingredients, $verdicts, ['normalise' => $normalised - $start, 'match' => microtime(true) - $normalised]);
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
