<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Wipes request/pool/callback tables before each test so functional tests
 * don't leak wallet-pool state (held addresses, etc.) into each other via
 * the shared test database -- Panel rows are kept (cheap to reuse by code).
 */
abstract class FunctionalTestCase extends WebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        self::ensureKernelShutdown();
        self::bootKernel();
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();

        foreach (['callback_delivery', 'deposit_request', 'withdrawal_request', 'panel_wallet_address'] as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }

        self::ensureKernelShutdown();
    }
}
