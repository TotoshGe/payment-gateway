<?php

declare(strict_types=1);

namespace App\Panel;

use App\Entity\Panel;
use App\Panel\Exception\PanelDisabledException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Resolves a Panel entity's `code` to its PanelInterface driver. New panels
 * only need to implement PanelInterface and be tagged 'app.panel_driver'
 * (autoconfigured below via services.yaml _instanceof) -- nothing else in
 * the service layer needs to know a new panel exists.
 */
final class PanelRegistry
{
    /** @var array<string, PanelInterface> */
    private array $driversByCode = [];

    /**
     * @param iterable<PanelInterface> $drivers
     */
    public function __construct(#[AutowireIterator('app.panel_driver')] iterable $drivers)
    {
        foreach ($drivers as $driver) {
            $this->driversByCode[$driver->getCode()] = $driver;
        }
    }

    public function getDriverFor(Panel $panel): PanelInterface
    {
        $driver = $this->driversByCode[$panel->getCode()] ?? null;
        if (null === $driver) {
            throw new \RuntimeException(sprintf('No panel driver registered for panel code "%s".', $panel->getCode()));
        }

        if ($driver instanceof GatedPanelInterface && !$driver->isEnabled()) {
            throw new PanelDisabledException(sprintf('Panel "%s" is disabled.', $panel->getCode()));
        }

        return $driver;
    }

    /**
     * False only for a gated panel (e.g. binance_test) whose env flag is
     * off. Panels without a registered driver keep the pre-existing
     * behaviour (this returns true; getDriverFor() then fails as before).
     */
    public function isAvailable(Panel $panel): bool
    {
        $driver = $this->driversByCode[$panel->getCode()] ?? null;

        return !($driver instanceof GatedPanelInterface) || $driver->isEnabled();
    }
}
