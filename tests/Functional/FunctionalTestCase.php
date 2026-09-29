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
    protected function route(string $currency, ?string $network, string $panelCode, bool $enabled = true): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $panel = $em->getRepository(\App\Entity\Panel::class)->findOneBy(['code' => $panelCode]);
        $em->persist(new \App\Entity\PanelRoute($currency, $network, $panel, $enabled));
        $em->flush();
    }

    protected function setUp(): void
    {
        parent::setUp();

        self::ensureKernelShutdown();
        self::bootKernel();
        $connection = self::getContainer()->get(EntityManagerInterface::class)->getConnection();

        foreach (['panel_route', 'callback_delivery', 'payment', 'deposit_request', 'withdrawal_request', 'panel_wallet_address'] as $table) {
            $connection->executeStatement('DELETE FROM '.$table);
        }

        self::ensureKernelShutdown();
    }
}
