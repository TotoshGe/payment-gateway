<?php

declare(strict_types=1);

namespace App\Panel\TestPanel;

use App\Entity\DepositRequest;
use App\Entity\Panel;
use App\Entity\WithdrawalRequest;
use App\Enum\PaymentRequestStatus;
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
 * RECEIVED/COMPLETED or expires (deposits never FAIL on Binance); a withdrawal
 * goes PROCESSING/COMPLETED/FAILED.
 */
final class TestPanelSimulator
{
    private const DEPOSIT_TRANSITIONS = [
        'awaiting_payment' => [PaymentRequestStatus::RECEIVED, PaymentRequestStatus::COMPLETED, PaymentRequestStatus::EXPIRED],
        'received' => [PaymentRequestStatus::COMPLETED],
    ];

    private const WITHDRAWAL_TRANSITIONS = [
        'submitted' => [PaymentRequestStatus::PROCESSING, PaymentRequestStatus::COMPLETED, PaymentRequestStatus::FAILED],
        'processing' => [PaymentRequestStatus::COMPLETED, PaymentRequestStatus::FAILED],
    ];

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
                'Deposit %s is "%s"; cannot move it to "%s".',
                $request->getId(),
                $request->getStatus()->value,
                $target->value,
            ));
        }

        if (null !== $amount && 1 !== preg_match('/^\d+(\.\d+)?$/', $amount)) {
            throw new TestPanelSimulationException('Amount must be a plain decimal string.');
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

        $this->depositRequestService->applyStatusUpdate(
            $request,
            $target,
            $amount ?? $request->getExpectedAmount(),
            PaymentRequestStatus::COMPLETED === $target ? 12 : 1,
            TestPanel::fakeDepositReference((string) $request->getId()),
        );
    }

    public function transitionWithdrawal(WithdrawalRequest $request, PaymentRequestStatus $target): void
    {
        $this->assertSimulatable($request->getPanel());

        if (!\in_array($target, $this->availableWithdrawalTargets($request), true)) {
            throw new TestPanelSimulationException(sprintf(
                'Withdrawal %s is "%s"; cannot move it to "%s".',
                $request->getId(),
                $request->getStatus()->value,
                $target->value,
            ));
        }

        $this->logger->warning(TestPanel::LOG_PREFIX.' simulating withdrawal status change', [
            'requestId' => (string) $request->getId(),
            'from' => $request->getStatus()->value,
            'to' => $target->value,
        ]);

        $this->withdrawalRequestService->applyStatusUpdate(
            $request,
            $target,
            PaymentRequestStatus::COMPLETED === $target ? TestPanel::fakeTxHash((string) $request->getId()) : null,
            PaymentRequestStatus::FAILED === $target ? 'Test Panel: simulated failure' : null,
        );
    }

    private function isSimulatable(Panel $panel): bool
    {
        return TestPanel::CODE === $panel->getCode();
    }

    private function assertSimulatable(Panel $panel): void
    {
        if (TestPanel::CODE !== $panel->getCode()) {
            throw new TestPanelSimulationException(sprintf('Request belongs to panel "%s", not %s -- refusing to simulate.', $panel->getCode(), TestPanel::CODE));
        }
    }
}
