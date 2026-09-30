<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use App\Entity\DepositRequest;
use App\Entity\Panel;
use App\Entity\WithdrawalRequest;
use App\Enum\PaymentRequestStatus;
use App\Enum\PaymentStatus;
use App\Panel\Dto\DepositAddressResult;
use App\Panel\Dto\DepositStatusUpdate;
use App\Panel\Dto\WithdrawalExecutionRequest;
use App\Panel\Dto\WithdrawalExecutionResult;
use App\Panel\Dto\WithdrawalStatusUpdate;
use App\Panel\Exception\PanelWalletProvisioningException;
use App\Panel\PanelInterface;

/**
 * Test double registered only in the test container (see services.yaml
 * when@test), under panel code "fake" -- lets functional tests exercise the
 * full request/orchestration/callback pipeline without making any real
 * Binance API calls. Configure canned behaviour per test via the public
 * mutators before making the HTTP request under test.
 */
final class FakePanel implements PanelInterface
{
    private int $maxSlots = 1;

    /** @var array<string, DepositAddressResult> */
    private array $addressesBySlotKey = [];

    /** @var array<int, DepositStatusUpdate> */
    private array $queuedDepositUpdates = [];

    private ?\Throwable $nextWithdrawalException = null;

    public int $withdrawalCalls = 0;

    public function getCode(): string
    {
        return 'fake';
    }

    public function setMaxSlots(int $maxSlots): void
    {
        $this->maxSlots = $maxSlots;
    }

    public function fetchDepositAddress(Panel $panel, string $currency, ?string $network, int $slotIndex): DepositAddressResult
    {
        if ($slotIndex >= $this->maxSlots) {
            throw new PanelWalletProvisioningException('No more fake slots configured.');
        }

        $key = $currency.':'.($network ?? '').':'.$slotIndex;

        return $this->addressesBySlotKey[$key] ??= new DepositAddressResult('fake-address-'.$slotIndex, null);
    }

    /**
     * @param DepositRequest[] $activeRequests
     */
    public function checkDeposits(Panel $panel, array $activeRequests): iterable
    {
        $updates = $this->queuedDepositUpdates;
        $this->queuedDepositUpdates = [];

        return $updates;
    }

    public function queueDepositUpdate(DepositStatusUpdate $update): void
    {
        $this->queuedDepositUpdates[] = $update;
    }

    public function queueDepositCompleted(DepositRequest $depositRequest, string $amount, string $txId = 'fake-tx'): void
    {
        $this->queueDepositPayment($depositRequest, PaymentStatus::COMPLETED, $amount, $txId);
    }

    public function queueDepositPayment(DepositRequest $depositRequest, PaymentStatus $status, string $amount, string $txId = 'fake-tx'): void
    {
        $this->queueDepositUpdate(new DepositStatusUpdate($depositRequest->getId(), $status, $amount, PaymentStatus::COMPLETED === $status ? 12 : 1, $txId));
    }

    public function setNextWithdrawalException(\Throwable $exception): void
    {
        $this->nextWithdrawalException = $exception;
    }

    public function executeWithdrawal(Panel $panel, WithdrawalExecutionRequest $request): WithdrawalExecutionResult
    {
        ++$this->withdrawalCalls;

        if (null !== $this->nextWithdrawalException) {
            $exception = $this->nextWithdrawalException;
            $this->nextWithdrawalException = null;

            throw $exception;
        }

        return new WithdrawalExecutionResult('fake-withdrawal-'.$request->clientWithdrawalId, PaymentRequestStatus::SUBMITTED);
    }

    /**
     * @param WithdrawalRequest[] $activeRequests
     */
    public function checkWithdrawals(Panel $panel, array $activeRequests): iterable
    {
        return [];
    }
}
