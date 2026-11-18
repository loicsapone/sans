<?php

declare(strict_types=1);

namespace App\Labels;

use App\Domain\Ingredient;
use App\Enum\Allergen;
use App\Enum\Certainty;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\McpBundle\Client\ServerConnectionInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;

/**
 * What the labels of real products say about an ingredient that only may carry an allergen: how many products of
 * its kind declare it on Open Food Facts. An agent finds the category of the ingredient with the tools of the
 * Open Food Facts MCP server; the numbers are read from the server here, the model never gets to write them.
 */
final readonly class ProductLabels
{
    public function __construct(
        #[Autowire(service: 'ai.agent.labels')]
        private AgentInterface $agent,
        #[Autowire(service: 'mcp.client.food.server.openfoodfacts')]
        private ServerConnectionInterface $openFoodFacts,
        private CacheInterface $cache,
    ) {
    }

    /**
     * @param list<Ingredient> $ingredients
     * @param list<Allergen>   $allergens
     *
     * @return array<int, list<array{category: string, total: int, allergen: string, declaring: int}>> by ingredient id, one entry
     *                                                                                                 for each allergen the ingredient may carry
     */
    public function of(array $ingredients, array $allergens): array
    {
        if ([] === $ingredients || [] === $allergens) {
            return [];
        }

        $key = 'labels.'.implode('-', array_column($ingredients, 'id')).'.'.implode('-', array_column($allergens, 'value'));

        return $this->cache->get($key, function (ItemInterface $item) use ($ingredients, $allergens): array {
            $labels = $this->ask($ingredients, $allergens);
            // nothing found is not kept: the server may only be busy
            $item->expiresAfter([] === $labels ? 0 : 7 * 86400);

            return $labels;
        });
    }

    /**
     * @param non-empty-list<Ingredient> $ingredients
     * @param list<Allergen>             $allergens
     *
     * @return array<int, list<array{category: string, total: int, allergen: string, declaring: int}>>
     */
    private function ask(array $ingredients, array $allergens): array
    {
        $labels = [];
        try {
            $names = array_map(static fn (Ingredient $ingredient): string => $ingredient->nameEn, $ingredients);
            $answer = $this->agent->call("Ingredients:\n".implode("\n", $names), ['response_format' => ProductCategories::class])->getResult()->getContent();
            $categories = array_column($answer instanceof ProductCategories ? $answer->categories : [], null, 'name');

            foreach ($ingredients as $ingredient) {
                $category = $categories[$ingredient->nameEn] ?? null;
                if (null === $category?->tag || 0 === $total = $this->count(['categories_tag' => $category->tag])) {
                    continue;
                }
                foreach ($allergens as $allergen) {
                    if (Certainty::Depends === ($ingredient->certainties[$allergen->value] ?? null)) {
                        $labels[$ingredient->id][] = [
                            'category' => $category->label ?? $ingredient->nameEn,
                            'total' => $total,
                            'allergen' => $allergen->value,
                            'declaring' => $this->count(['categories_tag' => $category->tag, 'allergens_tag' => $allergen->openFoodFactsTag()]),
                        ];
                    }
                }
            }
        } catch (\Throwable) {
            // a server someone else runs, with a small budget of searches: without it the page only says to read the label
        }

        return $labels;
    }

    /**
     * @param array<string, string> $filters
     */
    private function count(array $filters): int
    {
        $result = $this->openFoodFacts->callTool('off_search_products', $filters + ['page_size' => 1]);
        if ($result->isError || !\is_array($result->structuredContent) || !\is_int($result->structuredContent['total'] ?? null)) {
            throw new \RuntimeException('Open Food Facts gave no count.');
        }

        return $result->structuredContent['total'];
    }
}
