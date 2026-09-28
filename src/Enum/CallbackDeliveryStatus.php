<?php

declare(strict_types=1);

namespace App\Enum;

enum CallbackDeliveryStatus: string
{
    case PENDING = 'pending';
    case SENT = 'sent';
    case FAILED = 'failed';

    /** Retries exhausted -- needs a human (admin resend) from here on. */
    case EXHAUSTED = 'exhausted';

    /**
     * EasyAdmin ChoiceField::renderAsBadges() severity, purely presentational
     * (admin/CallbackDeliveryCrudController).
     */
    public function badgeType(): string
    {
        return match ($this) {
            self::SENT => 'success',
            self::FAILED, self::EXHAUSTED => 'danger',
            self::PENDING => 'secondary',
        };
    }
}
