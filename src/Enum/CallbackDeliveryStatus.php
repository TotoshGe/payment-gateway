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
}
