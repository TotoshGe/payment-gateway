<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\DepositRequest;
use App\Entity\Panel;
use App\Enum\PaymentRequestStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DepositRequest>
 */
class DepositRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DepositRequest::class);
    }

    public function findOneByExternalReference(string $externalReference): ?DepositRequest
    {
        return $this->findOneBy(['externalReference' => $externalReference]);
    }

    /**
     * @return DepositRequest[]
     */
    public function findAwaitingPaymentForPanel(Panel $panel): array
    {
        return $this->findBy(['panel' => $panel, 'status' => PaymentRequestStatus::AWAITING_PAYMENT]);
    }
}
