<?php

declare(strict_types=1);

namespace App\Tests\Matching;

use App\Enum\Allergen;
use App\Matching\IngredientDocumentTransformer;
use Symfony\AI\Store\Document\Loader\CsvLoader;
use Symfony\AI\Store\Document\Metadata;
use Symfony\AI\Store\Document\TextDocument;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class IngredientDocumentTransformerTest extends KernelTestCase
{
    public function testGathersTheNamesAndTheAllergensOfACsvRow(): void
    {
        $row = new TextDocument('5', 'bouillon', new Metadata([
            '_source' => '/data/ingredients.csv',
            '_row_index' => 4,
            'name' => 'bouillon',
            'plural' => 'bouillons',
            'name_en' => 'stock',
            'plural_en' => 'stock',
            'gluten' => 'depends',
            'eggs' => '',
            'celery' => 'always',
        ]));

        $documents = iterator_to_array((new IngredientDocumentTransformer())->transform([$row]), false);

        self::assertCount(1, $documents);
        self::assertSame('5', $documents[0]->getId());
        self::assertSame('bouillon, bouillons, stock', $documents[0]->getContent());
        self::assertSame(
            ['name' => 'bouillon', 'plural' => 'bouillons', 'name_en' => 'stock', 'plural_en' => 'stock', 'allergens' => ['gluten' => 'depends', 'celery' => 'always']],
            $documents[0]->getMetadata()->getArrayCopy(),
        );
    }

    public function testTheConfiguredLoaderGivesItEveryColumnOfTheReference(): void
    {
        self::bootKernel();
        $csv = self::getContainer()->getParameter('kernel.project_dir').'/resources/data/ingredients.csv';
        $rows = iterator_to_array(self::getContainer()->get(CsvLoader::class)->load($csv), false);

        foreach (Allergen::cases() as $allergen) {
            self::assertArrayHasKey($allergen->value, $rows[0]->getMetadata());
        }

        $documents = iterator_to_array((new IngredientDocumentTransformer())->transform($rows), false);

        self::assertCount(2173, $documents);
        self::assertSame('1', $documents[0]->getId());
        self::assertSame('abadèche, abadèches, haddock', $documents[0]->getContent());
        self::assertSame(
            ['name' => 'abadèche', 'plural' => 'abadèches', 'name_en' => 'haddock', 'plural_en' => 'haddock', 'allergens' => ['fish' => 'always']],
            $documents[0]->getMetadata()->getArrayCopy(),
        );
    }
}
