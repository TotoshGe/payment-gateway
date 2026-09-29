<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Panel;
use App\Repository\PanelRepository;
use App\Repository\PanelRouteRepository;
use App\Service\Exception\PanelNotFoundException;

/**
 * Picks the panel for a coin; the caller (Okean) never names one.
 * Order: enabled route for the exact (currency, network) -> enabled route for
 * the currency with any network -> the default panel (DEFAULT_PANEL env).
 * A disabled route is treated as absent. A chosen panel that does not exist
 * or is inactive is an error, never a silent fallthrough to another panel.
 */
final class PanelRouter
{
    public function __construct(
        private readonly PanelRouteRepository $routeRepository,
        private readonly PanelRepository $panelRepository,
        private readonly string $defaultPanelCode,
    ) {
    }

    public function getDefaultPanelCode(): string
    {
        return $this->defaultPanelCode;
    }

    /**
     * @throws PanelNotFoundException
     */
    public function resolve(string $currency, ?string $network): Panel
    {
        $route = (null !== $network ? $this->routeRepository->findEnabled($currency, $network) : null)
            ?? $this->routeRepository->findEnabled($currency, null);

        $panel = null !== $route ? $route->getPanel() : $this->panelRepository->findOneByCode($this->defaultPanelCode);

        if (null === $panel || !$panel->isActive()) {
            throw new PanelNotFoundException(sprintf(
                'No active panel available for %s%s (%s).',
                $currency,
                null !== $network ? '/'.$network : '',
                null !== $route ? 'route points to "'.($panel?->getCode() ?? '?').'"' : 'default panel "'.$this->defaultPanelCode.'"',
            ));
        }

        return $panel;
    }
}
