<?php

declare(strict_types=1);

namespace App\Panel\TestPanel;

use App\Entity\Panel;
use App\Enum\PaymentRequestStatus;
use App\Panel\Dto\DepositAddressResult;
use App\Panel\Dto\WithdrawalExecutionRequest;
use App\Panel\Dto\WithdrawalExecutionResult;
use App\Panel\Exception\PanelWalletProvisioningException;
use App\Panel\PanelInterface;
use Psr\Log\LoggerInterface;

/**
 * "Test Panel": behaves like BinancePanel from the point of view of every
 * caller (same interface, same statuses, same pool/slot semantics, same
 * callbacks), except where Binance would call its external API. There,
 * deposit addresses are FAKE (TestAddressGenerator) and nothing is ever
 * sent, so "funds arrived" / "withdrawal executed" cannot be observed by
 * polling -- checkDeposits() / checkWithdrawals() stay empty and an operator
 * drives those transitions through TestPanelSimulator (admin buttons or
 * app:test-panel:confirm), which feeds the SAME DepositRequestService /
 * WithdrawalRequestService + CallbackDispatcher path the real pollers use.
 *
 * The code stays "binance_test": Okean's payment methods already reference it
 * in their gatewayPanel field.
 */
final class TestPanel implements PanelInterface
{
    public const CODE = 'binance_test';
    public const LABEL = 'Test Panel';
    public const LOG_PREFIX = '[TEST PANEL]';

    /** Bounds pool growth: each concurrent open request occupies one slot. */
    private const MAX_SLOTS = 50;

    public function __construct(
        private readonly TestAddressGenerator $addressGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function fetchDepositAddress(Panel $panel, string $currency, ?string $network, int $slotIndex): DepositAddressResult
    {
        if ($slotIndex >= self::MAX_SLOTS) {
            throw new PanelWalletProvisioningException(sprintf('%s address pool limit (%d) reached.', self::LOG_PREFIX, self::MAX_SLOTS));
        }

        $result = $this->addressGenerator->generate($currency, $network, $slotIndex);

        $this->logger->warning(self::LOG_PREFIX.' issued a FAKE deposit address', [
            'currency' => $currency,
            'network' => $network,
            'slotIndex' => $slotIndex,
            'address' => $result->address,
        ]);

        return $result;
    }

    public function checkDeposits(Panel $panel, array $activeRequests): iterable
    {
        return [];
    }

    public function executeWithdrawal(Panel $panel, WithdrawalExecutionRequest $request): WithdrawalExecutionResult
    {
        $this->logger->warning(self::LOG_PREFIX.' accepted a withdrawal WITHOUT sending anything', [
            'currency' => $request->currency,
            'network' => $request->network,
            'amount' => $request->amount,
            'clientWithdrawalId' => $request->clientWithdrawalId,
        ]);

        return new WithdrawalExecutionResult(
            'TEST-WD-'.substr(hash('sha256', 'binance_test|wd|'.$request->clientWithdrawalId), 0, 16),
            PaymentRequestStatus::SUBMITTED,
        );
    }

    public function checkWithdrawals(Panel $panel, array $activeRequests): iterable
    {
        return [];
    }

    public static function fakeTxHash(string $seed): string
    {
        return 'test-'.hash('sha256', 'binance_test|tx|'.$seed);
    }

    public static function fakeDepositReference(string $seed): string
    {
        return 'TEST-DEP-'.substr(hash('sha256', 'binance_test|dep|'.$seed), 0, 16);
    }
}
