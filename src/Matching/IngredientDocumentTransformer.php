<?php

declare(strict_types=1);

namespace App\Matching;

use App\Enum\Allergen;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\TextDocument;
use Symfony\AI\Store\Document\TransformerInterface;

final readonly class IngredientDocumentTransformer implements TransformerInterface
{
    private const array NAME_COLUMNS = ['name', 'plural', 'name_en', 'plural_en'];

    public function transform(iterable $documents, array $options = []): iterable
    {
        foreach ($documents as $document) {
            $row = $document->getMetadata()->getArrayCopy();

            $names = [];
            $allergens = [];
            foreach (self::NAME_COLUMNS as $column) {
                $names[$column] = (string) ($row[$column] ?? '');
            }
            foreach (Allergen::cases() as $allergen) {
                if ('' !== $certainty = (string) ($row[$allergen->value] ?? '')) {
                    $allergens[$allergen->value] = $certainty;
                }
            }

            // the store keeps the metadata only: the names are what the full-text half of the search reads,
            // and the loader's own keys (_source, _row_index) would be searched too
            yield new TextDocument($document->getId(), implode(', ', array_unique($names)), new Metadata([...$names, 'allergens' => $allergens]));
        }
    }
}
