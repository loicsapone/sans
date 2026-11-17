<?php

declare(strict_types=1);

namespace App\Tests\Recipe;

use App\Enum\Allergen;
use App\Enum\Certainty;
use App\Enum\Verdict;
use App\Recipe\Analysis;
use App\Recipe\AnalysisStore;
use App\Tests\Support\Analyses;
use App\Tests\Support\CreatesSchema;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

final class AnalysisStoreTest extends KernelTestCase
{
    use CreatesSchema;

    private MockClock $clock;
    private AnalysisStore $store;
    private NormalizerInterface&DenormalizerInterface $serializer;

    protected function setUp(): void
    {
        self::bootKernel();
        $entityManager = $this->createSchema();

        $this->clock = new MockClock('2026-11-17 12:00:00');
        $this->serializer = self::getContainer()->get('serializer');
        $this->store = new AnalysisStore($entityManager, $this->serializer, $this->clock);
    }

    private function analysis(): Analysis
    {
        return Analyses::gratin();
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
