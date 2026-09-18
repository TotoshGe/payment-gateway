<?php

declare(strict_types=1);

namespace App\Tests\Integration\Repository;

use App\Entity\DepositRequest;
use App\Entity\Panel;
use App\Entity\WithdrawalRequest;
use App\Repository\DepositRequestRepository;
use App\Repository\WithdrawalRequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Regression coverage for the uuid-association binding pitfall documented
 * in PanelWalletAddressRepository (setParameter('x', $entity) not
 * resolving the custom uuid type reliably in raw QueryBuilder code):
 * findBy()-based lookups go through a different code path (Criteria API)
 * and need to be verified separately rather than assumed safe.
 */
final class RequestRepositoryPanelFilterTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testFindAwaitingPaymentForPanelOnlyReturnsThatPanelsRequests(): void
    {
        $panelA = new Panel('panel-a-'.uniqid(), 'A');
        $panelB = new Panel('panel-b-'.uniqid(), 'B');
        $this->entityManager->persist($panelA);
        $this->entityManager->persist($panelB);

        $requestA = new DepositRequest('ext-a-'.uniqid(), $panelA, 'USDT', 'TRC20', '10', new \DateTimeImmutable('+1 hour'));
        $requestA->assignWalletAddress(new \App\Entity\PanelWalletAddress($panelA, 'USDT', 'TRC20', 0, 'addrA'));
        $requestB = new DepositRequest('ext-b-'.uniqid(), $panelB, 'USDT', 'TRC20', '10', new \DateTimeImmutable('+1 hour'));
        $requestB->assignWalletAddress(new \App\Entity\PanelWalletAddress($panelB, 'USDT', 'TRC20', 0, 'addrB'));

        $this->entityManager->persist($requestA);
        $this->entityManager->persist($requestB);
        $this->entityManager->flush();

        /** @var DepositRequestRepository $repository */
        $repository = self::getContainer()->get(DepositRequestRepository::class);
        $results = $repository->findAwaitingPaymentForPanel($panelA);

        self::assertCount(1, $results);
        self::assertSame($requestA->getId()->toRfc4122(), $results[0]->getId()->toRfc4122());
    }

    public function testFindInFlightForPanelOnlyReturnsThatPanelsRequests(): void
    {
        $panelA = new Panel('panel-a-'.uniqid(), 'A');
        $panelB = new Panel('panel-b-'.uniqid(), 'B');
        $this->entityManager->persist($panelA);
        $this->entityManager->persist($panelB);

        $withdrawalA = new WithdrawalRequest('ext-a-'.uniqid(), $panelA, 'USDT', 'TRC20', '10', 'dest', null, Uuid::v4()->toRfc4122());
        $withdrawalA->setStatus(\App\Enum\PaymentRequestStatus::SUBMITTED);
        $withdrawalB = new WithdrawalRequest('ext-b-'.uniqid(), $panelB, 'USDT', 'TRC20', '10', 'dest', null, Uuid::v4()->toRfc4122());
        $withdrawalB->setStatus(\App\Enum\PaymentRequestStatus::SUBMITTED);

        $this->entityManager->persist($withdrawalA);
        $this->entityManager->persist($withdrawalB);
        $this->entityManager->flush();

        /** @var WithdrawalRequestRepository $repository */
        $repository = self::getContainer()->get(WithdrawalRequestRepository::class);
        $results = $repository->findInFlightForPanel($panelA);

        self::assertCount(1, $results);
        self::assertSame($withdrawalA->getId()->toRfc4122(), $results[0]->getId()->toRfc4122());
    }
}
