<?php

declare(strict_types=1);

namespace App\Panel\Binance;

use App\Enum\PaymentRequestStatus;

/**
 * Binance's deposit/withdraw status codes, documented at
 * https://binance-docs.github.io/apidocs/spot/en/#deposit-history-supporting-network.
 */
final class BinanceStatusMapper
{
    /**
     * Deposit history `status`: 0 pending, 6 credited but not yet
     * withdrawable, 1 success (fully credited) -- maps naturally onto our
     * RECEIVED (funds seen, not final) / COMPLETED (final) split.
     */
    public static function depositStatus(int $binanceStatus): ?PaymentRequestStatus
    {
        return match ($binanceStatus) {
            6 => PaymentRequestStatus::RECEIVED,
            1 => PaymentRequestStatus::COMPLETED,
            default => null,
        };
    }

    /**
     * Withdraw history `status`: 0 email sent, 1 cancelled, 2 awaiting
     * approval, 3 rejected, 4 processing, 5 failure, 6 completed.
     */
    public static function withdrawalStatus(int $binanceStatus): PaymentRequestStatus
    {
        return match ($binanceStatus) {
            6 => PaymentRequestStatus::COMPLETED,
            1, 3, 5 => PaymentRequestStatus::FAILED,
            default => PaymentRequestStatus::PROCESSING,
        };
    }
}
