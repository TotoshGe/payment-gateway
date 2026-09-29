<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Panel;
use App\Entity\Payment;
use App\Enum\PaymentRequestType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Payment>
 */
class PaymentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Payment::class);
    }

    public function findOneByPanelReference(Panel $panel, PaymentRequestType $type, string $panelReference): ?Payment
    {
        return $this->createQueryBuilder('p')
            ->andWhere('IDENTITY(p.panel) = :panelId')
            ->andWhere('p.type = :type')
            ->andWhere('p.panelReference = :reference')
            ->setParameter('panelId', $panel->getId(), 'uuid')
            ->setParameter('type', $type)
            ->setParameter('reference', $panelReference)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
