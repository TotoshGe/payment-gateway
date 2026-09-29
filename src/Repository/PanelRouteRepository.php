<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\PanelRoute;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PanelRoute>
 */
class PanelRouteRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PanelRoute::class);
    }

    public function findEnabled(string $currency, ?string $network): ?PanelRoute
    {
        return $this->findOneBy(['currency' => $currency, 'network' => $network, 'enabled' => true]);
    }
}
