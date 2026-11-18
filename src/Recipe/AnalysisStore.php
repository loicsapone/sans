<?php

declare(strict_types=1);

namespace App\Recipe;

use App\Entity\StoredAnalysis;
use App\Enum\Allergen;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

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
        return $this->read(self::idOf($url), Analysis::class);
    }

    public function save(string $url, Analysis $analysis): void
    {
        $this->write(self::idOf($url), $url, $analysis);
    }

    /**
     * @param list<Allergen> $avoided
     */
    public function findAdaptation(string $url, array $avoided): ?Adaptation
    {
        return $this->read($this->adaptationId($url, $avoided), Adaptation::class);
    }

    /**
     * @param list<Allergen> $avoided
     */
    public function saveAdaptation(string $url, array $avoided, Adaptation $adaptation): void
    {
        $this->write($this->adaptationId($url, $avoided), $url, $adaptation);
    }

    /**
     * @param list<Allergen> $avoided
     */
    private function adaptationId(string $url, array $avoided): string
    {
        $values = array_map(static fn (Allergen $allergen): string => $allergen->value, $avoided);
        sort($values);

        return sha1($url.'|'.implode(',', $values));
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T|null
     */
    private function read(string $id, string $class): ?object
    {
        $stored = $this->entityManager->find(StoredAnalysis::class, $id);
        if (null === $stored || $stored->createdAt->modify(self::LIFETIME) < $this->clock->now()) {
            return null;
        }

        return $this->serializer->denormalize($stored->data, $class, 'json');
    }

    private function write(string $id, string $url, object $value): void
    {
        /** @var array<string, mixed> $data */
        $data = $this->serializer->normalize($value, 'json');

        $stored = $this->entityManager->find(StoredAnalysis::class, $id) ?? new StoredAnalysis($id, $url, $data, $this->clock->now());
        $stored->url = $url;
        $stored->data = $data;
        $stored->createdAt = $this->clock->now();

        $this->entityManager->persist($stored);
        $this->entityManager->flush();
    }
}
