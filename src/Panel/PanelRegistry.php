<?php

declare(strict_types=1);

namespace App\Panel;

use App\Entity\Panel;
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

        return $driver;
    }
}
