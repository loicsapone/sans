<?php

declare(strict_types=1);

namespace App\Recipe;

use App\Entity\StoredAnalysis;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Keeps the analysis of a recipe address for a week, so that opening the same recipe again is instant.
 */
final readonly class AnalysisStore
{
    private const LIFETIME = '7 days';

    public function __construct(
        private EntityManagerInterface $entityManager,
        private NormalizerInterface&DenormalizerInterface $serializer,
        private ClockInterface $clock,
    ) {
    }

    public static function idOf(string $url): string
    {
        return sha1($url);
    }

    public function find(string $url): ?Analysis
    {
        $stored = $this->entityManager->find(StoredAnalysis::class, self::idOf($url));
        if (null === $stored || $stored->createdAt->modify(self::LIFETIME) < $this->clock->now()) {
            return null;
        }

        return $this->serializer->denormalize($stored->data, Analysis::class, 'json');
    }

    public function save(string $url, Analysis $analysis): void
    {
        /** @var array<string, mixed> $data */
        $data = $this->serializer->normalize($analysis, 'json');

        $stored = $this->entityManager->find(StoredAnalysis::class, self::idOf($url)) ?? new StoredAnalysis(self::idOf($url), $url, $data, $this->clock->now());
        $stored->url = $url;
        $stored->data = $data;
        $stored->createdAt = $this->clock->now();

        $this->entityManager->persist($stored);
        $this->entityManager->flush();
    }
}
