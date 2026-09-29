<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Status of one Payment (a concrete money movement: an incoming on-chain
 * deposit, or an outgoing withdrawal). The request status is derived from it,
 * never the other way round -- see ARCHITECTURE.md section 1.6.
 */
enum PaymentStatus: string
{
    /** Deposit: transfer seen, not yet confirming. Withdrawal: accepted by the panel, not yet processing. */
    case PENDING = 'pending';

    /** Deposit: confirmations accumulating. Withdrawal: the panel is processing/broadcasting. */
    case CONFIRMING = 'confirming';

    case COMPLETED = 'completed';

    /** Final: the movement did not happen (panel rejected/failed it). */
    case FAILED = 'failed';

    /** Final: the movement was cancelled (by the panel or an operator). Pauses the request. */
    case CANCELLED = 'cancelled';

    public function isFinal(): bool
    {
        return match ($this) {
            self::COMPLETED, self::FAILED, self::CANCELLED => true,
            default => false,
        };
    }

    /**
     * Only meaningful for a deposit payment: what the request shows while
     * this is its single payment.
     */
    public function toDepositRequestStatus(): PaymentRequestStatus
    {
        return match ($this) {
            self::PENDING, self::CONFIRMING => PaymentRequestStatus::RECEIVED,
            self::COMPLETED => PaymentRequestStatus::COMPLETED,
            self::FAILED, self::CANCELLED => PaymentRequestStatus::PAUSED,
        };
    }

    /**
     * Only meaningful for a withdrawal payment. FAILED stays FAILED (Okean
     * already treats withdrawal.failed as "freeze for manual review");
     * CANCELLED pauses.
     */
    public function toWithdrawalRequestStatus(): PaymentRequestStatus
    {
        return match ($this) {
            self::PENDING => PaymentRequestStatus::SUBMITTED,
            self::CONFIRMING => PaymentRequestStatus::PROCESSING,
            self::COMPLETED => PaymentRequestStatus::COMPLETED,
            self::FAILED => PaymentRequestStatus::FAILED,
            self::CANCELLED => PaymentRequestStatus::PAUSED,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Ожидает',
            self::CONFIRMING => 'Подтверждается',
            self::COMPLETED => 'Завершён',
            self::FAILED => 'Ошибка',
            self::CANCELLED => 'Отменён',
        };
    }

    public function badgeType(): string
    {
        return match ($this) {
            self::COMPLETED => 'success',
            self::FAILED => 'danger',
            self::CANCELLED, self::CONFIRMING => 'warning',
            self::PENDING => 'info',
        };
    }
}
