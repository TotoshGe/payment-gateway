<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\Panel;
use App\Entity\PanelWalletAddress;
use App\Repository\PanelWalletAddressRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Exercises the real database (not a mock): tryReserve() must be exclusive
 * even under concurrent access, see PanelWalletAddressRepository's docblock
 * for why it's a single atomic UPDATE rather than SELECT-then-UPDATE.
 */
final class PanelWalletAddressRepositoryTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;
    private PanelWalletAddressRepository $repository;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->repository = self::getContainer()->get(PanelWalletAddressRepository::class);
    }

    public function testReservingTheOnlyFreeAddressTwiceOnlySucceedsOnce(): void
    {
        $panel = new Panel('test-panel-'.uniqid(), 'Test panel');
        $this->entityManager->persist($panel);

        $address = new PanelWalletAddress($panel, 'USDT', 'TRC20', 0, 'Tonly-one');
        $this->entityManager->persist($address);
        $this->entityManager->flush();

        $firstRequestId = Uuid::v7();
        $secondRequestId = Uuid::v7();

        $firstReservation = $this->repository->tryReserve($panel, 'USDT', 'TRC20', $firstRequestId);
        $secondReservation = $this->repository->tryReserve($panel, 'USDT', 'TRC20', $secondRequestId);

        self::assertNotNull($firstReservation);
        self::assertSame($address->getId()->toRfc4122(), $firstReservation->getId()->toRfc4122());
        self::assertNull($secondReservation, 'A second reservation attempt must not get the already-held address.');
    }

    public function testReleasedAddressCanBeReservedAgain(): void
    {
        $panel = new Panel('test-panel-'.uniqid(), 'Test panel');
        $this->entityManager->persist($panel);

        $address = new PanelWalletAddress($panel, 'USDT', 'TRC20', 0, 'Treusable');
        $this->entityManager->persist($address);
        $this->entityManager->flush();

        $firstRequestId = Uuid::v7();
        $reserved = $this->repository->tryReserve($panel, 'USDT', 'TRC20', $firstRequestId);
        self::assertNotNull($reserved);

        $this->repository->releaseByDepositRequestId($firstRequestId);

        $secondRequestId = Uuid::v7();
        $reservedAgain = $this->repository->tryReserve($panel, 'USDT', 'TRC20', $secondRequestId);

        self::assertNotNull($reservedAgain);
        self::assertSame($address->getId()->toRfc4122(), $reservedAgain->getId()->toRfc4122());
    }

    public function testNextSlotIndexAndCountByPool(): void
    {
        $panel = new Panel('test-panel-'.uniqid(), 'Test panel');
        $this->entityManager->persist($panel);
        $this->entityManager->flush();

        self::assertSame(0, $this->repository->nextSlotIndex($panel, 'BTC', null));
        self::assertSame(0, $this->repository->countByPool($panel, 'BTC', null));

        $this->entityManager->persist(new PanelWalletAddress($panel, 'BTC', null, 0, 'addr-0'));
        $this->entityManager->persist(new PanelWalletAddress($panel, 'BTC', null, 1, 'addr-1'));
        $this->entityManager->flush();

        self::assertSame(2, $this->repository->nextSlotIndex($panel, 'BTC', null));
        self::assertSame(2, $this->repository->countByPool($panel, 'BTC', null));
    }
}
