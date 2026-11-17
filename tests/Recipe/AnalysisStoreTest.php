<?php

declare(strict_types=1);

namespace App\Tests\Recipe;

use App\Domain\AllergenVerdictEngine;
use App\Domain\Ingredient;
use App\Enum\Allergen;
use App\Enum\Certainty;
use App\Enum\Verdict;
use App\Recipe\Analysis;
use App\Recipe\AnalysisStore;
use App\Recipe\IngredientLine;
use App\Recipe\Recipe;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class AnalysisStoreTest extends KernelTestCase
{
    private MockClock $clock;
    private AnalysisStore $store;
    private NormalizerInterface&DenormalizerInterface $serializer;

    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->getConfiguration()->setUseSchemaEditorApi(true);
        (new SchemaTool($entityManager))->updateSchema($entityManager->getMetadataFactory()->getAllMetadata());

        $this->clock = new MockClock('2026-11-17 12:00:00');
        $this->serializer = self::getContainer()->get('serializer');
        $this->store = new AnalysisStore($entityManager, $this->serializer, $this->clock);
    }

    private function analysis(): Analysis
    {
        $butter = new Ingredient(73, 'beurre', 'butter', [Allergen::Milk->value => Certainty::Always]);
        $stock = new Ingredient(5, 'bouillon', 'stock', [Allergen::Celery->value => Certainty::Depends, Allergen::Gluten->value => Certainty::Depends]);
        $ingredients = ['beurre' => $butter, 'bouillon' => $stock, 'zgougou' => null];

        return new Analysis(
            new Recipe('Gratin', ['100 g de beurre', '1 cube de bouillon', 'du zgougou'], ['Mélanger.', 'Cuire.'], 6, 75),
            [
                new IngredientLine('100 g de beurre', 'beurre', ['beurre'], 100.0, 'g'),
                new IngredientLine('1 cube de bouillon', 'bouillon', ['bouillon'], 1.0, null, true),
                new IngredientLine('du zgougou', 'zgougou', ['zgougou']),
            ],
            $ingredients,
            (new AllergenVerdictEngine())->evaluate($ingredients),
            ['fetch' => 0.2, 'extract' => 0.0, 'normalise' => 2.1, 'match' => 3.4],
        );
    }

    public function testAnAnalysisComesBackAsItWasSaved(): void
    {
        $analysis = $this->analysis();

        $this->store->save('https://example.com/gratin', $analysis);

        $restored = $this->store->find('https://example.com/gratin');

        self::assertInstanceOf(Analysis::class, $restored);
        self::assertSame($this->serializer->normalize($analysis), $this->serializer->normalize($restored));
        self::assertSame(73, $restored->ingredients['beurre']?->id);
        self::assertNull($restored->ingredients['zgougou']);
        self::assertSame(Certainty::Always, $restored->ingredients['beurre']->certainties[Allergen::Milk->value]);
        self::assertSame(Verdict::Detected, $restored->verdicts['milk']->verdict);
    }

    public function testUnknownAddressesAreNotFound(): void
    {
        self::assertNull($this->store->find('https://example.com/unknown'));
    }

    public function testAnAnalysisExpiresAfterAWeek(): void
    {
        $this->store->save('https://example.com/gratin', $this->analysis());

        $this->clock->modify('+6 days');
        self::assertNotNull($this->store->find('https://example.com/gratin'));

        $this->clock->modify('+2 days');
        self::assertNull($this->store->find('https://example.com/gratin'));
    }

    public function testSavingAnAddressAgainReplacesItsAnalysis(): void
    {
        $this->store->save('https://example.com/gratin', $this->analysis());
        $this->clock->modify('+8 days');

        $this->store->save('https://example.com/gratin', $this->analysis());

        self::assertNotNull($this->store->find('https://example.com/gratin'));
    }
}
