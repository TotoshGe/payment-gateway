<?php

declare(strict_types=1);

namespace App\Enum;

enum PaymentRequestType: string
{
    case DEPOSIT = 'deposit';
    case WITHDRAWAL = 'withdrawal';

    /** Payment direction as shown in the admin: money in (deposit) or out (withdrawal). */
    public function paymentLabel(): string
    {
        return match ($this) {
            self::DEPOSIT => 'Входящий',
            self::WITHDRAWAL => 'Исходящий',
        };
    }

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
