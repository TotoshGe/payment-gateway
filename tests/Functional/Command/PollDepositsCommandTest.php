<?php

declare(strict_types=1);

namespace App\Tests\Functional\Command;

use App\Entity\Panel;
use App\Enum\CallbackDeliveryStatus;
use App\Enum\PanelWalletAddressStatus;
use App\Enum\PaymentRequestStatus;
use App\Repository\CallbackDeliveryRepository;
use App\Repository\PanelWalletAddressRepository;
use App\Service\DepositRequestService;
use App\Tests\Fixture\FakePanel;
use App\Tests\Functional\FunctionalTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class PollDepositsCommandTest extends FunctionalTestCase
{
    public function testCompletedDepositReleasesAddressAndQueuesCallback(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $em = $container->get(EntityManagerInterface::class);
        $panel = $em->getRepository(Panel::class)->findOneBy(['code' => 'fake']);
        if (null === $panel) {
            $panel = new Panel('fake', 'Fake panel');
            $em->persist($panel);
            $em->flush();
        }

        $depositRequestService = $container->get(DepositRequestService::class);
        $result = $depositRequestService->createOrGetExisting('poll-test-'.uniqid(), 'fake', 'USDT', 'TRC20', '77.00');
        $depositRequest = $result['request'];
        self::assertSame(PaymentRequestStatus::AWAITING_PAYMENT, $depositRequest->getStatus());

        /** @var FakePanel $fakePanel */
        $fakePanel = $container->get(FakePanel::class);
        $fakePanel->queueDepositCompleted($depositRequest, '77.00');

        $application = new Application(self::$kernel);
        $command = $application->find('app:payment-gateway:poll-deposits');
        $tester = new CommandTester($command);
        $tester->execute(['panel-code' => 'fake', '--once' => true]);
        self::assertSame(0, $tester->getStatusCode());

        $em->refresh($depositRequest);
        self::assertSame(PaymentRequestStatus::COMPLETED, $depositRequest->getStatus());
        self::assertSame(CallbackDeliveryStatus::PENDING, $depositRequest->getCallbackStatus());

        /** @var PanelWalletAddressRepository $walletRepository */
        $walletRepository = $container->get(PanelWalletAddressRepository::class);
        $address = $walletRepository->findOneBy(['panel' => $panel, 'currency' => 'USDT']);
        self::assertSame(PanelWalletAddressStatus::FREE, $address->getStatus(), 'address must be released back to the pool once the deposit completes');

        /** @var CallbackDeliveryRepository $callbackRepository */
        $callbackRepository = $container->get(CallbackDeliveryRepository::class);
        $deliveries = $callbackRepository->findBy(['requestId' => $depositRequest->getId()]);
        self::assertCount(1, $deliveries);
        self::assertSame('deposit.received', $deliveries[0]->getEventType());
    }

    public function testExpiredDepositIsMarkedExpiredAndReleasesAddress(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $em = $container->get(EntityManagerInterface::class);
        $panel = $em->getRepository(Panel::class)->findOneBy(['code' => 'fake']);
        if (null === $panel) {
            $panel = new Panel('fake', 'Fake panel');
            $em->persist($panel);
            $em->flush();
        }

        $depositRequestService = $container->get(DepositRequestService::class);
        $result = $depositRequestService->createOrGetExisting('poll-expire-'.uniqid(), 'fake', 'USDT', 'TRC20', '10.00');
        $depositRequest = $result['request'];

        // Force it into the past directly in the DB (expiresAt is set from
        // "now + ttl" at creation time, there's no public setter to backdate it).
        $em->getConnection()->executeStatement(
            'UPDATE deposit_request SET expires_at = ? WHERE id = ?',
            [(new \DateTimeImmutable('-1 hour'))->format('Y-m-d H:i:s'), $depositRequest->getId()->toBinary()],
        );
        $em->clear();

        $application = new Application(self::$kernel);
        $command = $application->find('app:payment-gateway:poll-deposits');
        $tester = new CommandTester($command);
        $tester->execute(['panel-code' => 'fake', '--once' => true]);

        $depositRequestRepository = $container->get(\App\Repository\DepositRequestRepository::class);
        $reloaded = $depositRequestRepository->find($depositRequest->getId());
        self::assertSame(PaymentRequestStatus::EXPIRED, $reloaded->getStatus());
    }
}
