<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Shared status set for both DepositRequest and WithdrawalRequest (product
 * decision: the same terminal-status names mean the same thing regardless of
 * flow, instead of e.g. "PAID" for deposits and "SENT" for withdrawals).
 * Not every value is reachable from every flow -- see DepositRequest/
 * WithdrawalRequest for the actual per-flow transition graphs.
 */
enum PaymentRequestStatus: string
{
    case NEW = 'new';

    /** Deposit only: address obtained from the panel, waiting for on-chain funds. */
    case AWAITING_PAYMENT = 'awaiting_payment';

    /** Withdrawal only: panel accepted the withdrawal and assigned it a reference. */
    case SUBMITTED = 'submitted';

    /** Withdrawal only: panel is actively processing (Binance withdraw status 4). */
    case PROCESSING = 'processing';

    /**
     * Deposit only, intermediate: funds observed on-chain but not yet fully
     * credited/withdrawable on the panel (Binance deposit status 6 "credited
     * but cannot withdraw" -- funds exist but confirmations aren't final).
     */
    case RECEIVED = 'received';

    /** Terminal success, shared by both flows. */
    case COMPLETED = 'completed';

    /** Terminal failure after the panel had already accepted the request. */
    case FAILED = 'failed';

    /** Terminal: deposit address expired with no funds received. */
    case EXPIRED = 'expired';

    /** Terminal: the panel call itself failed (never accepted the request). */
    case SUBMIT_FAILED = 'submit_failed';

    public function isTerminal(): bool
    {
        return match ($this) {
            self::COMPLETED, self::FAILED, self::EXPIRED, self::SUBMIT_FAILED => true,
            default => false,
        };
    }

    public function isSuccess(): bool
    {
        return self::COMPLETED === $this;
    }
}
