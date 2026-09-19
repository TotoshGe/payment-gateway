<?php

declare(strict_types=1);

namespace App\Panel\BinanceTest;

use App\Entity\Panel;
use App\Enum\PaymentRequestStatus;
use App\Panel\Dto\DepositAddressResult;
use App\Panel\Dto\WithdrawalExecutionRequest;
use App\Panel\Dto\WithdrawalExecutionResult;
use App\Panel\Exception\PanelDisabledException;
use App\Panel\Exception\PanelWalletProvisioningException;
use App\Panel\GatedPanelInterface;
use App\Panel\PanelInterface;
use Psr\Log\LoggerInterface;

/**
 * "Binance Test": a FAKE panel for manually walking the full Okean exchange
 * flow without a real provider. It talks to no external API, holds no keys
 * and moves no money. Deposit details come from TestAddressGenerator;
 * withdrawals are accepted and left SUBMITTED with a fake reference.
 *
 * Nothing is ever detected or confirmed automatically -- checkDeposits() /
 * checkWithdrawals() are deliberately empty. Status changes only happen when
 * an operator triggers them through BinanceTestSimulator (admin button or
 * app:binance-test:confirm), which then feeds the SAME
 * DepositRequestService/WithdrawalRequestService + CallbackDispatcher path
 * the real pollers use.
 *
 * Off unless BINANCE_TEST_PANEL_ENABLED is truthy (see GatedPanelInterface).
 */
final class BinanceTestPanel implements PanelInterface, GatedPanelInterface
{
    public const CODE = 'binance_test';
    public const LABEL = 'Binance Test';
    public const NOTICE = 'BINANCE TEST PANEL - FAKE DETAILS, DO NOT SEND REAL FUNDS';
    public const LOG_PREFIX = '[BINANCE TEST]';

    /** Bounds pool growth: each concurrent open request occupies one slot. */
    private const MAX_SLOTS = 50;

    public function __construct(
        private readonly bool $enabled,
        private readonly TestAddressGenerator $addressGenerator,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getCode(): string
    {
        return self::CODE;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function fetchDepositAddress(Panel $panel, string $currency, ?string $network, int $slotIndex): DepositAddressResult
    {
        $this->assertEnabled();

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
        $this->assertEnabled();

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

    private function assertEnabled(): void
    {
        if (!$this->enabled) {
            throw new PanelDisabledException(self::LOG_PREFIX.' panel is disabled (BINANCE_TEST_PANEL_ENABLED is off).');
        }
    }
}
