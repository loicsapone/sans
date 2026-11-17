<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
class StoredAnalysis
{
    /**
     * @param array<string, mixed> $data the normalised analysis
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(length: 40)]
        public readonly string $id,
        #[ORM\Column(length: 2048)]
        public string $url,
        #[ORM\Column(type: Types::JSON)]
        public array $data,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
