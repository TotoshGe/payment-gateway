<?php

declare(strict_types=1);

namespace App\Tests\Functional\Payment;

use App\Entity\DepositRequest;
use App\Entity\Panel;
use App\Entity\Payment;
use App\Entity\WithdrawalRequest;
use App\Enum\PanelWalletAddressStatus;
use App\Enum\PaymentRequestStatus;
use App\Enum\PaymentStatus;
use App\Repository\CallbackDeliveryRepository;
use App\Repository\PanelWalletAddressRepository;
use App\Service\DepositRequestService;
use App\Service\WithdrawalRequestService;
use App\Tests\Fixture\FakePanel;
use App\Tests\Functional\FunctionalTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

final class PaymentLifecycleTest extends FunctionalTestCase
{
    private function boot(): EntityManagerInterface
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        if (null === $em->getRepository(Panel::class)->findOneBy(['code' => 'fake'])) {
            $em->persist(new Panel('fake', 'Fake panel'));
            $em->flush();
        }

        return $em;
    }

    private function createDeposit(?string $network = 'TRC20'): DepositRequest
    {
        return self::getContainer()->get(DepositRequestService::class)
            ->createOrGetExisting(Uuid::v4()->toRfc4122(), 'fake', 'USDT', $network, '50.00')['request'];
    }

    private function poll(): void
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:payment-gateway:poll-deposits'));
        $tester->execute(['panel-code' => 'fake', '--once' => true]);
    }

    /**
     * @return list<string>
     */
    private function eventTypes(DepositRequest|WithdrawalRequest $request): array
    {
        $rows = self::getContainer()->get(CallbackDeliveryRepository::class)->findBy(['requestId' => $request->getId()], ['id' => 'ASC']);

        return array_map(static fn ($d) => $d->getEventType(), $rows);
    }

    private function addressStatus(): PanelWalletAddressStatus
    {
        return self::getContainer()->get(PanelWalletAddressRepository::class)->findOneBy(['currency' => 'USDT'])->getStatus();
    }

    public function testDepositIsCreatedWithoutPayment(): void
    {
        $this->boot();
        $deposit = $this->createDeposit();

        self::assertSame(PaymentRequestStatus::AWAITING_PAYMENT, $deposit->getStatus());
        self::assertCount(0, $deposit->getPayments());
        self::assertNull($deposit->getLeadPayment());
    }

    public function testArrivingPaymentIsLinkedAndRequestMirrorsItsConfirmationsThenCloses(): void
    {
        $em = $this->boot();
        $deposit = $this->createDeposit();
        $fake = self::getContainer()->get(FakePanel::class);

        $fake->queueDepositPayment($deposit, PaymentStatus::CONFIRMING, '50.00', 'tx-1');
        $this->poll();
        $em->clear();

        $deposit = $em->getRepository(DepositRequest::class)->find($deposit->getId());
        self::assertSame(PaymentRequestStatus::AWAITING_CONFIRMATIONS, $deposit->getStatus(), 'request shows the payment is awaiting network confirmation');
        self::assertCount(1, $deposit->getPayments());
        $payment = $deposit->getPayments()->first();
        self::assertSame(PaymentStatus::CONFIRMING, $payment->getStatus());
        self::assertSame('50.00', $payment->getAmount());
        self::assertSame('USDT', $payment->getCurrency());
        self::assertSame('TRC20', $payment->getNetwork());
        self::assertSame('tx-1', $payment->getPanelReference());
        self::assertSame('50.00', $deposit->getReceivedAmount());
        self::assertSame(['deposit.updated'], $this->eventTypes($deposit), 'a newly linked payment is announced');
        self::assertSame(PanelWalletAddressStatus::HELD, $this->addressStatus());

        $fake->queueDepositPayment($deposit, PaymentStatus::COMPLETED, '50.00', 'tx-1');
        $this->poll();
        $em->clear();

        $deposit = $em->getRepository(DepositRequest::class)->find($deposit->getId());
        self::assertSame(PaymentRequestStatus::COMPLETED, $deposit->getStatus());
        self::assertCount(1, $deposit->getPayments(), 'the same transfer updates the same payment, no duplicate');
        self::assertSame(PaymentStatus::COMPLETED, $deposit->getPayments()->first()->getStatus());
        self::assertSame(['deposit.updated', 'deposit.received'], $this->eventTypes($deposit));
        self::assertSame(PanelWalletAddressStatus::FREE, $this->addressStatus());

        $delivery = self::getContainer()->get(CallbackDeliveryRepository::class)->findBy(['requestId' => $deposit->getId()], ['id' => 'DESC'])[0];
        $payload = $delivery->getPayload();
        self::assertSame($deposit->getUuid(), $payload['uuid']);
        self::assertArrayNotHasKey('external_reference', $payload);
        self::assertSame((string) $deposit->getId(), $payload['request_id']);
        self::assertSame('completed', $payload['payment']['status']);
        self::assertSame('50.00', $payload['payment']['amount']);
        self::assertSame('tx-1', $payload['payment']['tx_hash']);
    }

    public function testCancelledDepositPaymentPausesRequestKeepsAddressAndACompletedNewPaymentRecovers(): void
    {
        $em = $this->boot();
        $deposit = $this->createDeposit();
        $fake = self::getContainer()->get(FakePanel::class);

        $fake->queueDepositPayment($deposit, PaymentStatus::CONFIRMING, '50.00', 'tx-a');
        $this->poll();
        $fake->queueDepositPayment($deposit, PaymentStatus::CANCELLED, '50.00', 'tx-a');
        $this->poll();
        $em->clear();

        $deposit = $em->getRepository(DepositRequest::class)->find($deposit->getId());
        self::assertSame(PaymentRequestStatus::PAUSED, $deposit->getStatus());
        self::assertSame(['deposit.updated', 'deposit.paused'], $this->eventTypes($deposit));
        self::assertSame(PanelWalletAddressStatus::HELD, $this->addressStatus(), 'paused is not terminal: the address stays held');

        $fake->queueDepositPayment($deposit, PaymentStatus::CANCELLED, '50.00', 'tx-a');
        $this->poll();
        $em->clear();
        self::assertSame(['deposit.updated', 'deposit.paused'], $this->eventTypes($em->getRepository(DepositRequest::class)->find($deposit->getId())), 'a repeated observation does not re-send the callback');

        $fake->queueDepositPayment($deposit, PaymentStatus::COMPLETED, '50.00', 'tx-b');
        $this->poll();
        $em->clear();

        $deposit = $em->getRepository(DepositRequest::class)->find($deposit->getId());
        self::assertSame(PaymentRequestStatus::COMPLETED, $deposit->getStatus());
        self::assertCount(2, $deposit->getPayments());
        self::assertSame(['deposit.updated', 'deposit.paused', 'deposit.received'], $this->eventTypes($deposit));
    }

    public function testAPaymentAlreadyLinkedToAnotherRequestIsNotRelinked(): void
    {
        $em = $this->boot();
        self::getContainer()->get(FakePanel::class)->setMaxSlots(2);
        $first = $this->createDeposit();
        $second = $this->createDeposit();
        $service = self::getContainer()->get(DepositRequestService::class);

        $service->applyPaymentUpdate($first, PaymentStatus::COMPLETED, '50.00', 12, 'tx-shared');
        $service->applyPaymentUpdate($second, PaymentStatus::COMPLETED, '50.00', 12, 'tx-shared');
        $em->clear();

        self::assertSame(PaymentRequestStatus::COMPLETED, $em->getRepository(DepositRequest::class)->find($first->getId())->getStatus());
        $secondReloaded = $em->getRepository(DepositRequest::class)->find($second->getId());
        self::assertSame(PaymentRequestStatus::AWAITING_PAYMENT, $secondReloaded->getStatus());
        self::assertCount(0, $secondReloaded->getPayments());
    }

    public function testNetworkIsOptionalOnRequestAndPayment(): void
    {
        $em = $this->boot();
        $deposit = $this->createDeposit(null);
        self::assertNull($deposit->getNetwork());

        self::getContainer()->get(DepositRequestService::class)->applyPaymentUpdate($deposit, PaymentStatus::COMPLETED, '50.00', 1, 'tx-nonet');
        $em->clear();

        $payment = $em->getRepository(Payment::class)->findOneBy(['panelReference' => 'tx-nonet']);
        self::assertNull($payment->getNetwork());
        self::assertSame('USDT', $payment->getCurrency());
        self::assertNull($payment->toArray()['network']);
    }

    private function withdrawal(string $amount = '12.50', ?string $network = null): WithdrawalRequest
    {
        return self::getContainer()->get(WithdrawalRequestService::class)->createOrGetExisting(Uuid::v4()->toRfc4122(), 'fake', 'USDT', $network, $amount, 'TDest', null)['request'];
    }

    public function testResumeReturnsAPausedDepositToAwaitingPayment(): void
    {
        $this->boot();
        $deposit = $this->createDeposit();
        $service = self::getContainer()->get(DepositRequestService::class);
        $service->applyPaymentUpdate($deposit, PaymentStatus::CANCELLED, '50.00', null, 'tx-c');
        self::assertSame(PaymentRequestStatus::PAUSED, $deposit->getStatus());

        $service->resume($deposit);
        self::assertSame(PaymentRequestStatus::AWAITING_PAYMENT, $deposit->getStatus());

        $service->applyPaymentUpdate($deposit, PaymentStatus::CANCELLED, '50.00', null, 'tx-c');
        self::assertSame(PaymentRequestStatus::AWAITING_PAYMENT, $deposit->getStatus(), 'the same cancelled payment does not pause it again');
    }

    public function testTestPanelSimulatorCancelsPaymentsAndRequestsPause(): void
    {
        $em = $this->boot();
        if (null === $em->getRepository(Panel::class)->findOneBy(['code' => 'binance_test'])) {
            $em->persist(new Panel('binance_test', 'Test Panel'));
            $em->flush();
        }
        $simulator = self::getContainer()->get(\App\Panel\TestPanel\TestPanelSimulator::class);

        $deposit = self::getContainer()->get(DepositRequestService::class)->createOrGetExisting(Uuid::v4()->toRfc4122(), 'binance_test', 'USDT', 'TRC20', '9')['request'];
        $simulator->transitionDeposit($deposit, PaymentRequestStatus::AWAITING_CONFIRMATIONS);
        self::assertSame([PaymentRequestStatus::COMPLETED, PaymentRequestStatus::PAUSED], $simulator->availableDepositTargets($deposit));
        $simulator->transitionDeposit($deposit, PaymentRequestStatus::PAUSED);
        self::assertSame(PaymentRequestStatus::PAUSED, $deposit->getStatus());
        self::assertSame(PaymentStatus::CANCELLED, $deposit->getLeadPayment()->getStatus());
        self::assertSame(['deposit.updated', 'deposit.paused'], $this->eventTypes($deposit));

        $withdrawal = self::getContainer()->get(WithdrawalRequestService::class)->createOrGetExisting(Uuid::v4()->toRfc4122(), 'binance_test', 'USDT', 'TRC20', '3', 'TDest', null)['request'];
        self::getContainer()->get(WithdrawalRequestService::class)->createPayment($withdrawal);
        $simulator->transitionWithdrawal($withdrawal, PaymentRequestStatus::PAUSED);
        self::assertSame(PaymentRequestStatus::PAUSED, $withdrawal->getStatus());
        self::assertSame([], $this->eventTypes($withdrawal), 'a paused withdrawal is silent');
    }

    public function testDepositUpdatedOnlyOnRealChangesOfThePayment(): void
    {
        $this->boot();
        $deposit = $this->createDeposit();
        $service = self::getContainer()->get(DepositRequestService::class);

        $service->applyPaymentUpdate($deposit, PaymentStatus::PENDING, '50.00', 0, 'tx-u');
        $service->applyPaymentUpdate($deposit, PaymentStatus::PENDING, '50.00', 0, 'tx-u');
        self::assertSame(['deposit.updated'], $this->eventTypes($deposit), 'unchanged state, no duplicate');

        $service->applyPaymentUpdate($deposit, PaymentStatus::CONFIRMING, '50.00', 1, 'tx-u');
        $service->applyPaymentUpdate($deposit, PaymentStatus::CONFIRMING, '50.00', 2, 'tx-u');
        $service->applyPaymentUpdate($deposit, PaymentStatus::CONFIRMING, '50.00', 2, 'tx-u');
        self::assertSame(['deposit.updated', 'deposit.updated', 'deposit.updated'], $this->eventTypes($deposit), 'status change and confirmations change each announce once');

        $delivery = self::getContainer()->get(CallbackDeliveryRepository::class)->findBy(['requestId' => $deposit->getId()], ['id' => 'DESC'])[0];
        self::assertSame('confirming', $delivery->getPayload()['payment']['status']);
        self::assertSame(2, $delivery->getPayload()['payment']['confirmations']);
        self::assertSame('awaiting_confirmations', $delivery->getPayload()['status']);
    }

    public function testPausedDepositExpiresAfterTimeoutAndReleasesAddress(): void
    {
        $em = $this->boot();
        $deposit = $this->createDeposit();
        $service = self::getContainer()->get(DepositRequestService::class);
        $service->applyPaymentUpdate($deposit, PaymentStatus::CANCELLED, '50.00', null, 'tx-p');
        self::assertNotNull($deposit->getPausedAt());

        self::assertFalse($service->expirePaused($deposit, new \DateTimeImmutable('+71 hours')), 'inside the 72h threshold');
        self::assertSame(PaymentRequestStatus::PAUSED, $deposit->getStatus());

        $em->getConnection()->executeStatement('UPDATE deposit_request SET paused_at = ? WHERE id = ?', [(new \DateTimeImmutable('-73 hours'))->format('Y-m-d H:i:s'), $deposit->getId()->toBinary()]);
        $em->clear();

        $this->poll();

        $deposit = $em->getRepository(DepositRequest::class)->find($deposit->getId());
        self::assertSame(PaymentRequestStatus::EXPIRED, $deposit->getStatus());
        self::assertNull($deposit->getPausedAt());
        self::assertSame(PanelWalletAddressStatus::FREE, $this->addressStatus());
        self::assertSame(['deposit.paused', 'deposit.expired'], $this->eventTypes($deposit));
    }

    public function testPausedWithdrawalIsNeverExpiredAutomatically(): void
    {
        $em = $this->boot();
        $service = self::getContainer()->get(WithdrawalRequestService::class);
        $withdrawal = $this->withdrawal('5', 'TRC20');
        $service->createPayment($withdrawal);
        $service->applyPaymentUpdate($withdrawal, PaymentStatus::CANCELLED, null, 'x');
        $em->getConnection()->executeStatement("UPDATE withdrawal_request SET updated_at = '2000-01-01 00:00:00'");

        $tester = new CommandTester((new Application(self::$kernel))->find('app:payment-gateway:poll-withdrawals'));
        $tester->execute(['panel-code' => 'fake', '--once' => true]);
        $em->clear();

        self::assertSame(PaymentRequestStatus::PAUSED, $em->getRepository(WithdrawalRequest::class)->find($withdrawal->getId())->getStatus());
    }

    public function testDepositWithMatchingAmountAndHashIsAwaitingConfirmationsAndAnnouncesConfirmations(): void
    {
        $this->boot();
        $deposit = $this->createDeposit();
        $service = self::getContainer()->get(DepositRequestService::class);

        $service->applyPaymentUpdate($deposit, PaymentStatus::PENDING, '50.00000000', 2, 'tx-m', null, 12);
        self::assertSame(PaymentRequestStatus::AWAITING_CONFIRMATIONS, $deposit->getStatus());

        $service->applyPaymentUpdate($deposit, PaymentStatus::CONFIRMING, '50.00', 7, 'tx-m', null, 12);
        self::assertSame(['deposit.updated', 'deposit.updated'], $this->eventTypes($deposit));

        $delivery = self::getContainer()->get(CallbackDeliveryRepository::class)->findBy(['requestId' => $deposit->getId()], ['id' => 'DESC'])[0];
        $payload = $delivery->getPayload();
        self::assertSame('awaiting_confirmations', $payload['status']);
        self::assertSame('tx-m', $payload['tx_hash']);
        self::assertSame(7, $payload['confirmations']);
        self::assertSame(12, $payload['required_confirmations']);
        self::assertSame(12, $payload['payment']['required_confirmations']);
    }

    public function testDepositWithDifferentAmountStaysReceivedButIsStillAnnounced(): void
    {
        $this->boot();
        $deposit = $this->createDeposit();
        $service = self::getContainer()->get(DepositRequestService::class);

        $service->applyPaymentUpdate($deposit, PaymentStatus::CONFIRMING, '49.00', 1, 'tx-short', null, 12);

        self::assertSame(PaymentRequestStatus::RECEIVED, $deposit->getStatus());
        self::assertSame(['deposit.updated'], $this->eventTypes($deposit));
    }
}
