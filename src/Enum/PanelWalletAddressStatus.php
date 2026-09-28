<?php

declare(strict_types=1);

namespace App\Enum;

enum PanelWalletAddressStatus: string
{
    case FREE = 'free';
    case HELD = 'held';

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
