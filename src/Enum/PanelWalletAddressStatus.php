<?php

declare(strict_types=1);

namespace App\Enum;

enum PanelWalletAddressStatus: string
{
    case FREE = 'free';
    case HELD = 'held';
}
