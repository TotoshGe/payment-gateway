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

    public function findOneByUuid(string $uuid): ?DepositRequest
    {
        return $this->findOneBy(['uuid' => $uuid]);
    }

    /**
     * Requests whose address must keep being watched: nothing seen yet, a
     * payment still confirming, or a paused one (a fresh payment can still
     * arrive at the held address).
     *
     * @return DepositRequest[]
     */
    public function findPollableForPanel(Panel $panel): array
    {
        return $this->findBy([
            'panel' => $panel,
            'status' => [PaymentRequestStatus::AWAITING_PAYMENT, PaymentRequestStatus::RECEIVED, PaymentRequestStatus::PAUSED],
        ]);
    }
}
