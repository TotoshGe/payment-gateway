<?php

declare(strict_types=1);

namespace App\Panel;

use App\Entity\DepositRequest;
use App\Entity\Panel;
use App\Entity\WithdrawalRequest;
use App\Panel\Dto\DepositAddressResult;
use App\Panel\Dto\DepositStatusUpdate;
use App\Panel\Dto\WithdrawalExecutionRequest;
use App\Panel\Dto\WithdrawalExecutionResult;
use App\Panel\Dto\WithdrawalStatusUpdate;
use App\Panel\Exception\PanelException;
use App\Panel\Exception\PanelWalletProvisioningException;

/**
 * One implementation per provider (Binance first). Deliberately works with
 * plain DTOs, not Doctrine entities, on the money-moving methods -- unlike
 * e.g. rates-service's read-only ExchangeConnectorInterface, these calls
 * have side effects, so a panel driver must not be able to mutate
 * persistence directly; only the orchestrating service does that.
 */
interface PanelInterface
{
    public function getCode(): string;

    /**
     * Fetches (not creates on every call -- Binance-style panels return the
     * same address for repeat calls with the same slot) the deposit address
     * for pool slot $slotIndex of a (currency, network). Slot 0 is always
     * expected to work (the panel's own account); slot >= 1 may throw
     * PanelWalletProvisioningException if the panel has no additional
     * sub-account/slot configured for it.
     *
     * @throws PanelWalletProvisioningException
     * @throws PanelException
     */
    public function fetchDepositAddress(Panel $panel, string $currency, ?string $network, int $slotIndex): DepositAddressResult;

    /**
     * Batch-checks every currently AWAITING_PAYMENT deposit request for this
     * panel in as few provider API calls as possible (ideally one call per
     * currency/network regardless of how many requests are active -- see
     * ARCHITECTURE.md section 4). Only returns updates for requests whose
     * observed state actually changed.
     *
     * @param DepositRequest[] $activeRequests
     * @return iterable<DepositStatusUpdate>
     * @throws PanelException
     */
    public function checkDeposits(Panel $panel, array $activeRequests): iterable;

    /**
     * @throws PanelException
     */
    public function executeWithdrawal(Panel $panel, WithdrawalExecutionRequest $request): WithdrawalExecutionResult;

    /**
     * @param WithdrawalRequest[] $activeRequests
     * @return iterable<WithdrawalStatusUpdate>
     * @throws PanelException
     */
    public function checkWithdrawals(Panel $panel, array $activeRequests): iterable;
}
