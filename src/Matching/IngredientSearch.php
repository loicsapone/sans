<?php

declare(strict_types=1);

namespace App\Matching;

use Symfony\AI\Agent\Bridge\SimilaritySearch\DocumentSource;
use Symfony\AI\Agent\Toolbox\Attribute\AsTool;
use Symfony\AI\Agent\Toolbox\Source\HasSourcesInterface;
use Symfony\AI\Agent\Toolbox\Source\HasSourcesTrait;
use Symfony\AI\Store\Document\VectorizerInterface;
use Symfony\AI\Store\Query\HybridQuery;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsTool('search_ingredients', description: 'Searches the ingredient reference. Give all the names to look up at once: it returns, for each name, the 5 nearest ingredients.')]
final class IngredientSearch implements HasSourcesInterface
{
    use HasSourcesTrait;

    public function __construct(
        #[Autowire(service: 'ai.vectorizer.ingredients')]
        private readonly VectorizerInterface $vectorizer,
        #[Autowire(service: 'ai.store.meilisearch.ingredients')]
        private readonly StoreInterface $store,
    ) {
    }

    /**
     * @param list<string> $names the ingredient names to look up
     *
     * @return array<string, list<array{id: int|string, name: mixed, name_en: mixed}>>
     */
    public function __invoke(array $names): array
    {
        // the documents are kept as sources: they are what the model is allowed to choose from
        $sources = $this->getSourceCollection();
        $vectors = $this->vectorizer->vectorize($names);

        $found = [];
        foreach ($names as $i => $name) {
            // half on the words of the name, half on its meaning; the store has no limit option, hence the slice
            $nearest = \array_slice(iterator_to_array($this->store->query(new HybridQuery($vectors[$i], $name)), false), 0, 5);
            foreach ($nearest as $document) {
                $sources->add(new DocumentSource($document));
                $metadata = $document->getMetadata();
                $found[$name][] = ['id' => $document->getId(), 'name' => $metadata['name'], 'name_en' => $metadata['name_en']];
            }
        }

        return $found;
    }
}
