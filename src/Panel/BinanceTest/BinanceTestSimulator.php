<?php

declare(strict_types=1);

namespace App\Panel\BinanceTest;

use App\Entity\DepositRequest;
use App\Entity\Panel;
use App\Entity\WithdrawalRequest;
use App\Enum\PaymentRequestStatus;
use App\Service\DepositRequestService;
use App\Service\WithdrawalRequestService;
use Psr\Log\LoggerInterface;

/**
 * The only way a Binance Test request changes status. It does not notify
 * Okean itself: it calls the very same applyStatusUpdate()/expire() the real
 * pollers call, and those dispatch the signed callback -- there is no second
 * notification path.
 *
 * Refuses anything that is not a request on the binance_test panel, and
 * refuses everything while the env flag is off.
 */
final class BinanceTestSimulator
{
    private const DEPOSIT_TRANSITIONS = [
        'awaiting_payment' => [PaymentRequestStatus::RECEIVED, PaymentRequestStatus::COMPLETED, PaymentRequestStatus::FAILED, PaymentRequestStatus::EXPIRED],
        'received' => [PaymentRequestStatus::COMPLETED, PaymentRequestStatus::FAILED],
    ];

    private const WITHDRAWAL_TRANSITIONS = [
        'submitted' => [PaymentRequestStatus::PROCESSING, PaymentRequestStatus::COMPLETED, PaymentRequestStatus::FAILED],
        'processing' => [PaymentRequestStatus::COMPLETED, PaymentRequestStatus::FAILED],
    ];

    public function __construct(
        private readonly BinanceTestPanel $panel,
        private readonly DepositRequestService $depositRequestService,
        private readonly WithdrawalRequestService $withdrawalRequestService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->panel->isEnabled();
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
            throw new BinanceTestSimulationException(sprintf(
                'Deposit %s is "%s"; cannot move it to "%s".',
                $request->getId(),
                $request->getStatus()->value,
                $target->value,
            ));
        }

        if (null !== $amount && 1 !== preg_match('/^\d+(\.\d+)?$/', $amount)) {
            throw new BinanceTestSimulationException('Amount must be a plain decimal string.');
        }

        $this->logger->warning(BinanceTestPanel::LOG_PREFIX.' simulating deposit status change', [
            'requestId' => (string) $request->getId(),
            'from' => $request->getStatus()->value,
            'to' => $target->value,
        ]);

        if (PaymentRequestStatus::EXPIRED === $target) {
            $this->depositRequestService->expire($request);

            return;
        }

        $observed = match ($target) {
            PaymentRequestStatus::FAILED => '0',
            default => $amount ?? $request->getExpectedAmount(),
        };

        $this->depositRequestService->applyStatusUpdate(
            $request,
            $target,
            $observed,
            PaymentRequestStatus::COMPLETED === $target ? 12 : 1,
            BinanceTestPanel::fakeDepositReference((string) $request->getId()),
        );
    }

    public function transitionWithdrawal(WithdrawalRequest $request, PaymentRequestStatus $target): void
    {
        $this->assertSimulatable($request->getPanel());

        if (!\in_array($target, $this->availableWithdrawalTargets($request), true)) {
            throw new BinanceTestSimulationException(sprintf(
                'Withdrawal %s is "%s"; cannot move it to "%s".',
                $request->getId(),
                $request->getStatus()->value,
                $target->value,
            ));
        }

        $this->logger->warning(BinanceTestPanel::LOG_PREFIX.' simulating withdrawal status change', [
            'requestId' => (string) $request->getId(),
            'from' => $request->getStatus()->value,
            'to' => $target->value,
        ]);

        $this->withdrawalRequestService->applyStatusUpdate(
            $request,
            $target,
            PaymentRequestStatus::COMPLETED === $target ? BinanceTestPanel::fakeTxHash((string) $request->getId()) : null,
            PaymentRequestStatus::FAILED === $target ? 'TEST: simulated failure (Binance Test panel)' : null,
        );
    }

    private function isSimulatable(Panel $panel): bool
    {
        return BinanceTestPanel::CODE === $panel->getCode() && $this->panel->isEnabled();
    }

    private function assertSimulatable(Panel $panel): void
    {
        if (BinanceTestPanel::CODE !== $panel->getCode()) {
            throw new BinanceTestSimulationException(sprintf('Request belongs to panel "%s", not %s -- refusing to simulate.', $panel->getCode(), BinanceTestPanel::CODE));
        }

        if (!$this->panel->isEnabled()) {
            throw new BinanceTestSimulationException('Binance Test panel is disabled (BINANCE_TEST_PANEL_ENABLED is off).');
        }
    }
}
