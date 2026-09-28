<?php

declare(strict_types=1);

namespace App\Enum;

enum PanelWalletAddressStatus: string
{
    case FREE = 'free';
    case HELD = 'held';

    /**
     * Russian label for the admin UI. The stored value is the DB contract
     * and never changes.
     */
    public function label(): string
    {
        return match ($this) {
            self::FREE => 'Свободен',
            self::HELD => 'Занят',
        };
    }

    /**
     * EasyAdmin ChoiceField::renderAsBadges() severity, purely presentational
     * (admin/PanelWalletAddressCrudController).
     */
    public function badgeType(): string
    {
        return match ($this) {
            self::FREE => 'success',
            self::HELD => 'warning',
        };
    }
}
