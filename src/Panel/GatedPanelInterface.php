<?php

declare(strict_types=1);

namespace App\Panel;

/**
 * Marker for panels that must be switched on out-of-band (env flag) before
 * anything may use them. PanelRegistry and PanelAvailability refuse to hand
 * out / accept a gated panel while isEnabled() is false, regardless of the
 * Panel row's `active` column.
 */
interface GatedPanelInterface
{
    public function isEnabled(): bool;
}
