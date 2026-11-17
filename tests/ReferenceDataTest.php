<?php

declare(strict_types=1);

namespace App\Tests;

use App\Enum\Allergen;
use App\Enum\Certainty;
use PHPUnit\Framework\TestCase;

// The CSV was drafted by a model: these checks catch a broken file, not a wrong allergen.
final class ReferenceDataTest extends TestCase
{
    /**
     * @return list<array<string, string>>
     */
    private static function rows(): array
    {
        $lines = file(\dirname(__DIR__).'/resources/data/ingredients.csv', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [];
        $header = array_map(strval(...), str_getcsv((string) array_shift($lines), escape: ''));

        return array_map(static fn (string $line): array => array_map(strval(...), array_combine($header, str_getcsv($line, escape: ''))), $lines);
    }

    public function testEveryAllergenHasAColumnWithValidValues(): void
    {
        $rows = self::rows();
        $valid = ['', Certainty::Always->value, Certainty::Depends->value];

        self::assertCount(2173, $rows);
        foreach ($rows as $row) {
            foreach (Allergen::cases() as $allergen) {
                self::assertContains($row[$allergen->value], $valid, $row['name']);
            }
        }
    }

    public function testIdsAreUniqueAndEveryParentExists(): void
    {
        $rows = self::rows();
        $ids = array_column($rows, 'id');

        self::assertSame($ids, array_unique($ids));
        foreach ($rows as $row) {
            self::assertTrue('' === $row['parent_id'] || \in_array($row['parent_id'], $ids, true), $row['name']);
        }
    }

    public function testKnowsTheFreeFromSubstitutesAWriterAddedByHand(): void
    {
        $byName = array_column(self::rows(), null, 'name');

        self::assertSame('', $byName['farine de riz']['gluten']);
        self::assertSame('always', $byName['lait de soja']['soybeans']);
        self::assertSame('', $byName['lait de soja']['gluten']);
        self::assertSame('always', $byName["lait d'amande"]['tree-nuts']);
        self::assertSame('', $byName['huile de coco']['tree-nuts']);
        self::assertSame('depends', $byName['fromage végétal']['soybeans']);
        self::assertSame('', $byName['lasagnes sans gluten']['gluten']);
    }

    public function testKnowsTheAllergensOfTheTrickyIngredients(): void
    {
        $byName = array_column(self::rows(), null, 'name');

        self::assertSame('always', $byName['sauce soja']['soybeans']);
        self::assertSame('always', $byName['crevettes']['crustaceans']);
        self::assertSame('always', $byName['beurre']['milk']);
        self::assertSame('', $byName['muscade']['tree-nuts']);
        self::assertSame('', $byName['noix de coco']['tree-nuts']);
    }
}
