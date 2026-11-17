<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Creates the schema of the test database, inside the transaction DAMA rolls back after each test.
 *
 * @mixin KernelTestCase
 */
trait CreatesSchema
{
    private function createSchema(): EntityManagerInterface
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->getConfiguration()->setUseSchemaEditorApi(true);
        (new SchemaTool($entityManager))->updateSchema($entityManager->getMetadataFactory()->getAllMetadata());

        return $entityManager;
    }
}
