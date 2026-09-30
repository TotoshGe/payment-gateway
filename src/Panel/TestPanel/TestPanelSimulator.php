<?php

declare(strict_types=1);

namespace App\Panel\TestPanel;

use App\Entity\DepositRequest;
use App\Entity\Panel;
use App\Entity\WithdrawalRequest;
use App\Enum\PaymentRequestStatus;
use App\Enum\PaymentStatus;
use App\Service\DepositRequestService;
use App\Service\WithdrawalRequestService;
use Psr\Log\LoggerInterface;

/**
 * The only way a Test Panel request changes status. It does not notify
 * Okean itself: it calls the very same applyStatusUpdate()/expire() the real
 * pollers call, and those dispatch the signed callback -- there is no second
 * notification path.
 *
 * Only for requests on the test panel (code binance_test). The transitions
 * offered are the ones BinancePanel's pollers can produce: a deposit goes
 * AWAITING_CONFIRMATIONS/COMPLETED or expires (deposits never FAIL on Binance);
 * a withdrawal goes PROCESSING/AWAITING_CONFIRMATIONS/COMPLETED, FAILED (payment
 * error, request pauses). PAUSED means "the payment was cancelled":
 * the simulator drives the payment, the request status follows from it.
 * Reachable from the console (app:test-panel:confirm) only; there are no admin buttons.
 */
final class TestPanelSimulator
{
    private const DEPOSIT_TRANSITIONS = [
        'awaiting_payment' => [PaymentRequestStatus::AWAITING_CONFIRMATIONS, PaymentRequestStatus::COMPLETED, PaymentRequestStatus::EXPIRED],
        'received' => [PaymentRequestStatus::COMPLETED, PaymentRequestStatus::PAUSED],
        'awaiting_confirmations' => [PaymentRequestStatus::COMPLETED, PaymentRequestStatus::PAUSED],
    ];

    private const WITHDRAWAL_TRANSITIONS = [
        'submitted' => [PaymentRequestStatus::PROCESSING, PaymentRequestStatus::AWAITING_CONFIRMATIONS, PaymentRequestStatus::COMPLETED, PaymentRequestStatus::FAILED, PaymentRequestStatus::PAUSED],
        'processing' => [PaymentRequestStatus::AWAITING_CONFIRMATIONS, PaymentRequestStatus::COMPLETED, PaymentRequestStatus::FAILED, PaymentRequestStatus::PAUSED],
        'awaiting_confirmations' => [PaymentRequestStatus::COMPLETED, PaymentRequestStatus::FAILED, PaymentRequestStatus::PAUSED],
    ];

    private const REQUIRED_CONFIRMATIONS = 12;

    public function __construct(
        private readonly DepositRequestService $depositRequestService,
        private readonly WithdrawalRequestService $withdrawalRequestService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return list<PaymentRequestStatus>
     */
    public function availableDepositTargets(DepositRequest $request): array
    {
        if (!$this->isSimulatable($request->getPanel())) {
            return [];
        }

        return self::DEPOSIT_TRANSITIONS[$request->getStatus()->value] ?? [];
    }

    /**
     * @return list<PaymentRequestStatus>
     */
    public function availableWithdrawalTargets(WithdrawalRequest $request): array
    {
        if (!$this->isSimulatable($request->getPanel())) {
            return [];
        }

        return self::WITHDRAWAL_TRANSITIONS[$request->getStatus()->value] ?? [];
    }

    /**
     * @param ?string $amount confirmed amount for RECEIVED/COMPLETED; defaults to the expected amount
     */
    public function transitionDeposit(DepositRequest $request, PaymentRequestStatus $target, ?string $amount = null): void
    {
        $this->assertSimulatable($request->getPanel());

        if (!\in_array($target, $this->availableDepositTargets($request), true)) {
            throw new TestPanelSimulationException(sprintf(
                'Пополнение %s имеет статус «%s»; невозможно перевести его в «%s».',
                $request->getId(),
                $request->getStatus()->label(),
                $target->label(),
            ));
        }

        if (null !== $amount && 1 !== preg_match('/^\d+(\.\d+)?$/', $amount)) {
            throw new TestPanelSimulationException('Сумма должна быть десятичной строкой.');
        }

        $this->logger->warning(TestPanel::LOG_PREFIX.' simulating deposit status change', [
            'requestId' => (string) $request->getId(),
            'from' => $request->getStatus()->value,
            'to' => $target->value,
        ]);

        if (PaymentRequestStatus::EXPIRED === $target) {
            $this->depositRequestService->expire($request);

            return;
        }

        $paymentStatus = match ($target) {
            PaymentRequestStatus::COMPLETED => PaymentStatus::COMPLETED,
            PaymentRequestStatus::PAUSED => PaymentStatus::CANCELLED,
            default => PaymentStatus::CONFIRMING,
        };

        $this->depositRequestService->applyPaymentUpdate(
            $request,
            $paymentStatus,
            $amount ?? $request->getReceivedAmount() ?? $request->getExpectedAmount(),
            PaymentStatus::COMPLETED === $paymentStatus ? self::REQUIRED_CONFIRMATIONS : 1,
            TestPanel::fakeDepositReference((string) $request->getId()),
            PaymentStatus::CANCELLED === $paymentStatus ? 'Test Panel: simulated cancellation' : null,
            self::REQUIRED_CONFIRMATIONS,
        );
    }

    public function transitionWithdrawal(WithdrawalRequest $request, PaymentRequestStatus $target): void
    {
        $this->assertSimulatable($request->getPanel());

        if (!\in_array($target, $this->availableWithdrawalTargets($request), true)) {
            throw new TestPanelSimulationException(sprintf(
                'Вывод %s имеет статус «%s»; невозможно перевести его в «%s».',
                $request->getId(),
                $request->getStatus()->label(),
                $target->label(),
            ));
        }

        $this->logger->warning(TestPanel::LOG_PREFIX.' simulating withdrawal status change', [
            'requestId' => (string) $request->getId(),
            'from' => $request->getStatus()->value,
            'to' => $target->value,
        ]);

        $paymentStatus = match ($target) {
            PaymentRequestStatus::COMPLETED => PaymentStatus::COMPLETED,
            PaymentRequestStatus::FAILED => PaymentStatus::FAILED,
            PaymentRequestStatus::PAUSED => PaymentStatus::CANCELLED,
            default => PaymentStatus::CONFIRMING,
        };

        $seesTransfer = PaymentStatus::COMPLETED === $paymentStatus || PaymentRequestStatus::AWAITING_CONFIRMATIONS === $target;

        $this->withdrawalRequestService->applyPaymentUpdate(
            $request,
            $paymentStatus,
            $seesTransfer ? TestPanel::fakeTxHash((string) $request->getId()) : null,
            match ($paymentStatus) {
                PaymentStatus::FAILED => 'Test Panel: simulated failure',
                PaymentStatus::CANCELLED => 'Test Panel: simulated cancellation',
                default => null,
            },
            $seesTransfer ? $request->getAmount() : null,
            $seesTransfer ? (PaymentStatus::COMPLETED === $paymentStatus ? self::REQUIRED_CONFIRMATIONS : 1) : null,
            $seesTransfer ? self::REQUIRED_CONFIRMATIONS : null,
        );
    }

    private function isSimulatable(Panel $panel): bool
    {
        return TestPanel::CODE === $panel->getCode();
    }

    private function assertSimulatable(Panel $panel): void
    {
        if (TestPanel::CODE !== $panel->getCode()) {
            throw new TestPanelSimulationException(sprintf('Заявка принадлежит панели «%s», а не %s -- симуляция невозможна.', $panel->getCode(), TestPanel::CODE));
        }
    }
}
