<?php

declare(strict_types=1);

namespace App\Enum;

enum PaymentRequestType: string
{
    case DEPOSIT = 'deposit';
    case WITHDRAWAL = 'withdrawal';

    /**
     * Russian label for the admin UI. The stored value is the DB contract
     * and never changes.
     */
    public function label(): string
    {
        return match ($this) {
            self::DEPOSIT => 'Пополнение',
            self::WITHDRAWAL => 'Вывод',
        };
    }
}
