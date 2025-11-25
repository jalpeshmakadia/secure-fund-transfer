<?php

namespace App\Tests;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Base test case with common functionality.
 */
abstract class TestCase extends KernelTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();
    }

    protected function getEntityManager(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function clearDatabase(): void
    {
        $em = $this->getEntityManager();
        
        // Disable foreign key checks temporarily
        $em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
        
        // Clear tables
        $em->getConnection()->executeStatement('TRUNCATE TABLE transactions');
        $em->getConnection()->executeStatement('TRUNCATE TABLE accounts');
        
        // Re-enable foreign key checks
        $em->getConnection()->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
        
        $em->clear();
    }
}

