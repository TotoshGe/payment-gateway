<?php

declare(strict_types=1);

namespace App\Panel\Exception;

/**
 * Thrown when a panel can't provision an additional pool slot for a
 * (currency, network) -- e.g. Binance's standard API gives one deterministic
 * address per (coin, network) on a given account, so slot >= 1 requires a
 * configured sub-account and there isn't one. See ARCHITECTURE.md R1.
 */
class PanelWalletProvisioningException extends PanelException
{
}
