<?php

declare(strict_types=1);

namespace App\Panel\Binance;

use App\Enum\PaymentStatus;

/**
 * Binance's deposit/withdraw status codes, documented at
 * https://binance-docs.github.io/apidocs/spot/en/#deposit-history-supporting-network.
 */
final class BinanceStatusMapper
{
    /**
     * Deposit history `status`: 0 pending, 8 waiting for user confirm, 6
     * credited but not yet withdrawable, 1 success, 2 rejected, 7 wrong
     * deposit. Rejected/wrong deposits are CANCELLED so the request pauses
     * for a human instead of silently ignoring funds that landed.
     */
    public static function depositStatus(int $binanceStatus): ?PaymentStatus
    {
        return match ($binanceStatus) {
            0, 8 => PaymentStatus::PENDING,
            6 => PaymentStatus::CONFIRMING,
            1 => PaymentStatus::COMPLETED,
            2, 7 => PaymentStatus::CANCELLED,
            default => null,
        };
    }

    /**
     * Withdraw history `status`: 0 email sent, 1 cancelled, 2 awaiting
     * approval, 3 rejected, 4 processing, 5 failure, 6 completed.
     */
    public static function withdrawalStatus(int $binanceStatus): PaymentStatus
    {
        return match ($binanceStatus) {
            6 => PaymentStatus::COMPLETED,
            1 => PaymentStatus::CANCELLED,
            3, 5 => PaymentStatus::FAILED,
            default => PaymentStatus::CONFIRMING,
        };
    }
}
