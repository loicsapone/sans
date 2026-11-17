<?php

declare(strict_types=1);

namespace App\Matching;

use App\Domain\Ingredient;
use App\Enum\Certainty;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Bridge\SimilaritySearch\DocumentSource;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Finds the ingredient behind each name of a recipe: an agent searches the index with the
 * search_ingredients tool and picks one of the results (or none) for each name.
 */
final readonly class IngredientMatcher
{
    public function __construct(
        #[Autowire(service: 'ai.agent.matcher')]
        private AgentInterface $agent,
    ) {
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, Ingredient|null> the ingredient found for each name, null when there is none
     */
    public function match(array $names): array
    {
        $result = $this->agent->call("Names to match:\n".implode("\n", $names), ['response_format' => MatchChoices::class])->getResult();

        // the model only gets to pick among the ingredients the tool actually returned
        $offered = [];
        foreach ($result->getMetadata()->get('sources', []) as $source) {
            if ($source instanceof DocumentSource) {
                $document = $source->getDocument();
                $data = $document->getMetadata()->getArrayCopy();
                $certainties = array_map(static fn (string $certainty): Certainty => Certainty::from($certainty), $data['allergens']);
                $offered[(int) $document->getId()] = new Ingredient((int) $document->getId(), $data['name'], $data['name_en'], $certainties);
            }
        }

        $found = array_fill_keys($names, null);
        $choices = $result->getContent();
        if ($choices instanceof MatchChoices) {
            foreach ($choices->choices as $choice) {
                if (\array_key_exists($choice->name, $found) && null !== $choice->id) {
                    $found[$choice->name] = $offered[$choice->id] ?? null;
                }
            }
        }

        return $found;
    }
}
