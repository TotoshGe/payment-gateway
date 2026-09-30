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

    /**
     * Withdrawal only: created without a payment, waits for an operator to
     * press "create payment" (also where a withdrawal returns to if its
     * completed payment does not match the requested amount).
     */
    case AWAITING_PAYOUT = 'awaiting_payout';

    /**
     * Both flows: the on-chain transfer is visible (has a tx hash, amount
     * matches the request) but is not confirmed yet. Payment status
     * pending/confirming on the deposit side, confirming on the withdrawal side.
     */
    case AWAITING_CONFIRMATIONS = 'awaiting_confirmations';

    /** Withdrawal only: panel accepted the withdrawal and assigned it a reference. */
    case SUBMITTED = 'submitted';

    /** Withdrawal only: panel is actively processing (Binance withdraw status 4). */
    case PROCESSING = 'processing';

    /**
     * Deposit only, intermediate: a transfer was seen but it does not (yet)
     * qualify for AWAITING_CONFIRMATIONS: amount differs from the request or
     * no tx hash yet.
     */
    case RECEIVED = 'received';

    /**
     * Non-terminal, both flows: the request's payment was cancelled/failed
     * (deposit) or cancelled (withdrawal) and needs attention. The pooled
     * deposit address stays held; a new payment arriving or an admin action
     * moves it on.
     */
    case PAUSED = 'paused';

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

    /**
     * Russian label for the admin UI (EasyAdmin ChoiceField choices/badges).
     * The stored value (e.g. "awaiting_payment") is the API/DB contract and
     * never changes.
     */
    public function label(): string
    {
        return match ($this) {
            self::NEW => 'Новая',
            self::AWAITING_PAYOUT => 'Ожидание выплаты',
            self::AWAITING_CONFIRMATIONS => 'Ожидает подтверждений в сети',
            self::AWAITING_PAYMENT => 'Ожидает оплаты',
            self::SUBMITTED => 'Отправлена',
            self::PROCESSING => 'В обработке',
            self::RECEIVED => 'Получена',
            self::PAUSED => 'Приостановлена',
            self::COMPLETED => 'Завершена',
            self::FAILED => 'Ошибка',
            self::EXPIRED => 'Истекла',
            self::SUBMIT_FAILED => 'Не удалось отправить',
        };
    }

    /**
     * EasyAdmin ChoiceField::renderAsBadges() severity, purely presentational
     * (admin/DepositRequestCrudController, WithdrawalRequestCrudController).
     */
    public function badgeType(): string
    {
        return match ($this) {
            self::COMPLETED => 'success',
            self::FAILED, self::EXPIRED, self::SUBMIT_FAILED => 'danger',
            self::PROCESSING, self::RECEIVED, self::PAUSED, self::AWAITING_CONFIRMATIONS => 'warning',
            self::AWAITING_PAYMENT, self::AWAITING_PAYOUT, self::SUBMITTED => 'info',
            self::NEW => 'secondary',
        };
    }
}
