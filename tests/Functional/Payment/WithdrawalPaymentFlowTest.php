<?php

declare(strict_types=1);

namespace App\Tests\Functional\Payment;

use App\Entity\Panel;
use App\Entity\WithdrawalRequest;
use App\Enum\PaymentRequestStatus;
use App\Enum\PaymentStatus;
use App\Panel\Exception\PanelException;
use App\Repository\CallbackDeliveryRepository;
use App\Service\Exception\WithdrawalPaymentException;
use App\Service\WithdrawalRequestService;
use App\Tests\Fixture\FakePanel;
use App\Tests\Functional\FunctionalTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

final class WithdrawalPaymentFlowTest extends FunctionalTestCase
{
    private function service(): WithdrawalRequestService
    {
        return self::getContainer()->get(WithdrawalRequestService::class);
    }

    private function newWithdrawal(string $amount = '12.50'): WithdrawalRequest
    {
        self::bootKernel();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $panel = $em->getRepository(Panel::class)->findOneBy(['code' => 'fake']) ?? new Panel('fake', 'Fake panel');
        $panel->setActive(true);
        $em->persist($panel);
        $em->flush();

        return $this->service()->createOrGetExisting(Uuid::v4()->toRfc4122(), 'fake', 'USDT', 'TRC20', $amount, 'TDest', null)['request'];
    }

    /**
     * @return list<string>
     */
    private function events(WithdrawalRequest $request): array
    {
        return array_map(static fn ($d) => $d->getEventType(), self::getContainer()->get(CallbackDeliveryRepository::class)->findBy(['requestId' => $request->getId()], ['id' => 'ASC']));
    }

    public function testRequestIsCreatedWithoutPaymentAndNothingIsSent(): void
    {
        $withdrawal = $this->newWithdrawal();

        self::assertSame(PaymentRequestStatus::AWAITING_PAYOUT, $withdrawal->getStatus());
        self::assertCount(0, $withdrawal->getPayments());
        self::assertSame(0, self::getContainer()->get(FakePanel::class)->withdrawalCalls);
        self::assertSame([], $this->events($withdrawal));
    }

    public function testCreatePaymentSendsToPanelOnceAndRefusesASecondPayment(): void
    {
        $withdrawal = $this->newWithdrawal();

        $payment = $this->service()->createPayment($withdrawal);

        self::assertSame(PaymentStatus::PENDING, $payment->getStatus());
        self::assertSame('12.50', $payment->getAmount());
        self::assertNotNull($payment->getPanelReference());
        self::assertSame(PaymentRequestStatus::SUBMITTED, $withdrawal->getStatus());
        self::assertSame(1, self::getContainer()->get(FakePanel::class)->withdrawalCalls);

        try {
            $this->service()->createPayment($withdrawal);
            self::fail('a second payment must be refused');
        } catch (WithdrawalPaymentException) {
        }
        self::assertSame(1, self::getContainer()->get(FakePanel::class)->withdrawalCalls);
        self::assertCount(1, $withdrawal->getPayments());
    }

    public function testCreatePaymentRefusedForInactivePanelWithoutCreatingAnything(): void
    {
        $withdrawal = $this->newWithdrawal();
        $withdrawal->getPanel()->setActive(false);
        self::getContainer()->get(EntityManagerInterface::class)->flush();

        $this->expectException(WithdrawalPaymentException::class);
        try {
            $this->service()->createPayment($withdrawal);
        } finally {
            self::assertCount(0, $withdrawal->getPayments());
            self::assertSame(0, self::getContainer()->get(FakePanel::class)->withdrawalCalls);
            self::assertSame(PaymentRequestStatus::AWAITING_PAYOUT, $withdrawal->getStatus());
            $withdrawal->getPanel()->setActive(true);
            self::getContainer()->get(EntityManagerInterface::class)->flush();
        }
    }

    public function testPanelErrorStillCreatesFailedPaymentWithReasonAndPausesSilently(): void
    {
        $withdrawal = $this->newWithdrawal();
        self::getContainer()->get(FakePanel::class)->setNextWithdrawalException(new PanelException('insufficient balance'));

        $payment = $this->service()->createPayment($withdrawal);

        self::assertSame(PaymentStatus::FAILED, $payment->getStatus());
        self::assertSame('insufficient balance', $payment->getReason());
        self::assertNull($payment->getPanelReference());
        self::assertSame(PaymentRequestStatus::PAUSED, $withdrawal->getStatus());
        self::assertSame([], $this->events($withdrawal), 'pausing sends no callback');
    }

    public function testUnexpectedErrorIsTreatedTheSameWay(): void
    {
        $withdrawal = $this->newWithdrawal();
        self::getContainer()->get(FakePanel::class)->setNextWithdrawalException(new \RuntimeException('connection reset'));

        $payment = $this->service()->createPayment($withdrawal);

        self::assertSame(PaymentStatus::FAILED, $payment->getStatus());
        self::assertSame('connection reset', $payment->getReason());
        self::assertSame(PaymentRequestStatus::PAUSED, $withdrawal->getStatus());
    }

    public function testCompletedPaymentWithMatchingAmountClosesRequestAndSendsCompleted(): void
    {
        $withdrawal = $this->newWithdrawal();
        $this->service()->createPayment($withdrawal);

        $this->service()->applyPaymentUpdate($withdrawal, PaymentStatus::COMPLETED, 'tx-out', null, '12.50000000', 20, null);

        self::assertSame(PaymentRequestStatus::COMPLETED, $withdrawal->getStatus());
        self::assertSame('tx-out', $withdrawal->getTxHash());
        self::assertSame(['withdrawal.completed'], $this->events($withdrawal));

        $this->service()->applyPaymentUpdate($withdrawal, PaymentStatus::COMPLETED, 'tx-out', null, '12.50000000', 20, null);
        self::assertSame(['withdrawal.completed'], $this->events($withdrawal), 'a repeated observation sends nothing');
    }

    public function testCompletedPaymentWithDifferentAmountKeepsRequestAwaitingPayoutWithoutCallback(): void
    {
        $withdrawal = $this->newWithdrawal();
        $this->service()->createPayment($withdrawal);

        $this->service()->applyPaymentUpdate($withdrawal, PaymentStatus::COMPLETED, 'tx-out', null, '12.40', 20, null);

        self::assertSame(PaymentRequestStatus::AWAITING_PAYOUT, $withdrawal->getStatus());
        self::assertSame(PaymentStatus::COMPLETED, $withdrawal->getLeadPayment()->getStatus());
        self::assertSame('12.40', $withdrawal->getLeadPayment()->getAmount());
        self::assertSame([], $this->events($withdrawal));
    }

    public function testCancelledPaymentPausesWithoutCallback(): void
    {
        $withdrawal = $this->newWithdrawal();
        $this->service()->createPayment($withdrawal);

        $this->service()->applyPaymentUpdate($withdrawal, PaymentStatus::CANCELLED, null, 'cancelled on panel');

        self::assertSame(PaymentRequestStatus::PAUSED, $withdrawal->getStatus());
        self::assertSame([], $this->events($withdrawal));
    }

    public function testFailedPaymentReportedByThePollerPausesWithoutCallback(): void
    {
        $withdrawal = $this->newWithdrawal();
        $this->service()->createPayment($withdrawal);

        $this->service()->applyPaymentUpdate($withdrawal, PaymentStatus::FAILED, null, 'Binance withdraw status 5');

        self::assertSame(PaymentRequestStatus::PAUSED, $withdrawal->getStatus());
        self::assertSame('Binance withdraw status 5', $withdrawal->getFailureReason());
        self::assertSame([], $this->events($withdrawal));
    }

    public function testOperatorClosingAPausedWithdrawalIsTheOnlyWayOkeanHearsAboutIt(): void
    {
        $withdrawal = $this->newWithdrawal();
        $this->service()->createPayment($withdrawal);
        $this->service()->applyPaymentUpdate($withdrawal, PaymentStatus::CANCELLED, null, 'x');

        $this->service()->failPaused($withdrawal);

        self::assertSame(PaymentRequestStatus::FAILED, $withdrawal->getStatus());
        self::assertSame(['withdrawal.failed'], $this->events($withdrawal));
    }

    public function testTransferSeenWithHashAndMatchingAmountIsAwaitingConfirmationsAndCallbackRepeatsAsConfirmationsGrow(): void
    {
        $withdrawal = $this->newWithdrawal();
        $this->service()->createPayment($withdrawal);

        $this->service()->applyPaymentUpdate($withdrawal, PaymentStatus::CONFIRMING, null, null, '12.50', null, null);
        self::assertSame(PaymentRequestStatus::PROCESSING, $withdrawal->getStatus(), 'no tx hash yet');
        self::assertSame([], $this->events($withdrawal));

        $this->service()->applyPaymentUpdate($withdrawal, PaymentStatus::CONFIRMING, '0xhash', null, '12.50', 1, 12);
        self::assertSame(PaymentRequestStatus::AWAITING_CONFIRMATIONS, $withdrawal->getStatus());
        self::assertSame(['withdrawal.confirming'], $this->events($withdrawal));

        $this->service()->applyPaymentUpdate($withdrawal, PaymentStatus::CONFIRMING, '0xhash', null, '12.50', 1, 12);
        self::assertSame(['withdrawal.confirming'], $this->events($withdrawal), 'unchanged, no duplicate');

        $this->service()->applyPaymentUpdate($withdrawal, PaymentStatus::CONFIRMING, '0xhash', null, '12.50', 5, 12);
        self::assertSame(['withdrawal.confirming', 'withdrawal.confirming'], $this->events($withdrawal));

        $deliveries = self::getContainer()->get(CallbackDeliveryRepository::class)->findBy(['requestId' => $withdrawal->getId()], ['id' => 'DESC']);
        $payload = $deliveries[0]->getPayload();
        self::assertSame('awaiting_confirmations', $payload['status']);
        self::assertSame('0xhash', $payload['tx_hash']);
        self::assertSame(5, $payload['confirmations']);
        self::assertSame(12, $payload['required_confirmations']);
        self::assertSame(5, $payload['payment']['confirmations']);
        self::assertSame(12, $payload['payment']['required_confirmations']);
        self::assertSame('confirming', $payload['payment']['status']);
        self::assertSame($withdrawal->getUuid(), $payload['uuid']);

        $this->service()->applyPaymentUpdate($withdrawal, PaymentStatus::COMPLETED, '0xhash', null, '12.50', 12, 12);
        self::assertSame(PaymentRequestStatus::COMPLETED, $withdrawal->getStatus());
        self::assertSame(['withdrawal.confirming', 'withdrawal.confirming', 'withdrawal.completed'], $this->events($withdrawal));
    }

    public function testTransferWithDifferentAmountIsNotAwaitingConfirmations(): void
    {
        $withdrawal = $this->newWithdrawal();
        $this->service()->createPayment($withdrawal);

        $this->service()->applyPaymentUpdate($withdrawal, PaymentStatus::CONFIRMING, '0xhash', null, '12.49', 1, 12);

        self::assertSame(PaymentRequestStatus::PROCESSING, $withdrawal->getStatus());
        self::assertSame([], $this->events($withdrawal));
    }
}
