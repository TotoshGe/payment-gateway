<?php

declare(strict_types=1);

namespace App\Enum;

enum PaymentRequestType: string
{
    case DEPOSIT = 'deposit';
    case WITHDRAWAL = 'withdrawal';
}
