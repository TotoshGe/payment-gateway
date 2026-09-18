<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Panel;
use App\Entity\WithdrawalRequest;
use App\Enum\PaymentRequestStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WithdrawalRequest>
 */
class WithdrawalRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WithdrawalRequest::class);
    }

    public function findOneByExternalReference(string $externalReference): ?WithdrawalRequest
    {
        return $this->findOneBy(['externalReference' => $externalReference]);
    }

    /**
     * @return WithdrawalRequest[]
     */
    public function findInFlightForPanel(Panel $panel): array
    {
        return $this->createQueryBuilder('w')
            // See PanelWalletAddressRepository::countByPool() docblock:
            // binding the entity directly doesn't reliably infer the
            // custom "uuid" type for this comparison.
            ->andWhere('IDENTITY(w.panel) = :panelId')
            ->andWhere('w.status IN (:statuses)')
            ->setParameter('panelId', $panel->getId(), 'uuid')
            ->setParameter('statuses', [PaymentRequestStatus::SUBMITTED, PaymentRequestStatus::PROCESSING])
            ->getQuery()
            ->getResult();
    }
}
