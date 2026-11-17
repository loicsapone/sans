<?php

declare(strict_types=1);

namespace App\Command;

use App\Enum\Allergen;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\TextDocument;
use Symfony\AI\Store\IndexerInterface;
use Symfony\AI\Store\ManagedStoreInterface;
use Symfony\AI\Store\StoreInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'app:index', description: 'Build the vector index from the ingredient CSV')]
final readonly class IndexCommand
{
    public function __construct(
        #[Autowire(service: 'ai.indexer.ingredients')]
        private IndexerInterface $indexer,
        #[Autowire(service: 'ai.store.meilisearch.ingredients')]
        private StoreInterface&ManagedStoreInterface $store,
        #[Autowire('%kernel.project_dir%/resources/data/ingredients.csv')]
        private string $csv,
    ) {
    }

    public function __invoke(SymfonyStyle $io): int
    {
        $lines = file($this->csv, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [];
        $header = array_map(strval(...), str_getcsv((string) array_shift($lines), escape: ''));
        $allergenColumns = array_flip(array_map(static fn (Allergen $allergen): string => $allergen->value, Allergen::cases()));

        // the allergens travel with each document, so a search result is all the verdict needs,
        // and the names are stored too: they are what the full-text half of the search reads
        $documents = [];
        foreach ($lines as $line) {
            $row = array_map(strval(...), array_combine($header, str_getcsv($line, escape: '')));
            $names = array_unique([$row['name'], $row['plural'], $row['name_en'], $row['plural_en']]);
            $allergens = array_filter(array_intersect_key($row, $allergenColumns));

            $documents[] = new TextDocument($row['id'], implode(', ', $names), new Metadata([
                'name' => $row['name'],
                'plural' => $row['plural'],
                'name_en' => $row['name_en'],
                'plural_en' => $row['plural_en'],
                'allergens' => $allergens,
            ]));
        }

        $this->store->setup();
        $this->store->clear();
        // the default chunk of 50 documents is one embedding request each, one after the other
        $this->indexer->index($documents, ['chunk_size' => 200]);

        $io->success(\sprintf('%d ingredients indexed.', \count($documents)));

        return 0;
    }
}
