<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Panel;
use App\Entity\PanelWalletAddress;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Uuid;

/**
 * @extends ServiceEntityRepository<PanelWalletAddress>
 */
class PanelWalletAddressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PanelWalletAddress::class);
    }

    /**
     * Atomically claims one free address for (panel, currency, network) and
     * assigns it to $depositRequestId, or claims nothing if none are free.
     *
     * Implemented as a single UPDATE ... WHERE id = (SELECT id FROM (...) )
     * rather than "SELECT free rows, then UPDATE the first one" on purpose:
     * two concurrent deposit-request creations hitting the same currency/
     * network must never be able to both read the same free row before
     * either commits. A single atomic UPDATE lets MySQL/MariaDB's own
     * row-level locking serialize the two statements instead of us having
     * to manage explicit transactions/locks in application code.
     */
    public function tryReserve(Panel $panel, string $currency, ?string $network, Uuid $depositRequestId): ?PanelWalletAddress
    {
        $connection = $this->getEntityManager()->getConnection();

        $sql = <<<'SQL'
            UPDATE panel_wallet_address
            SET status = 'held', held_by_deposit_request_id = :requestId, held_at = :heldAt
            WHERE id = (
                SELECT id FROM (
                    SELECT id FROM panel_wallet_address
                    WHERE panel_id = :panelId
                        AND currency = :currency
                        AND network <=> :network
                        AND status = 'free'
                    ORDER BY slot_index ASC
                    LIMIT 1
                ) AS pick
            )
            SQL;

        // UuidType (see config/packages/doctrine.yaml) stores ids as
        // BINARY(16), not the RFC4122 string form -- raw SQL has to match
        // that encoding explicitly, ORM-built queries do this conversion
        // automatically but executeStatement() doesn't.
        $affected = $connection->executeStatement($sql, [
            'requestId' => $depositRequestId->toBinary(),
            'heldAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
            'panelId' => $panel->getId()->toBinary(),
            'currency' => $currency,
            'network' => $network,
        ]);

        if (0 === $affected) {
            return null;
        }

        // The UPDATE above happened via raw SQL, bypassing the ORM -- if
        // this PanelWalletAddress row was already loaded earlier in the
        // request, the identity map would otherwise still hand back a
        // stale (status=free) copy. Query::HINT_REFRESH forces a re-hydrate
        // of just this one entity instead of a blanket
        // EntityManager::clear(), which as of Doctrine ORM 3.x detaches the
        // *entire* unit of work regardless of the class name argument and
        // would silently drop unrelated pending changes (e.g. the caller's
        // own DepositRequest) elsewhere in the same request/command cycle.
        return $this->createQueryBuilder('a')
            ->andWhere('a.heldByDepositRequestId = :requestId')
            ->setParameter('requestId', $depositRequestId, 'uuid')
            ->getQuery()
            ->setHint(\Doctrine\ORM\Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    public function releaseByDepositRequestId(Uuid $depositRequestId): void
    {
        $address = $this->findOneBy(['heldByDepositRequestId' => $depositRequestId]);
        if (null === $address) {
            return;
        }

        $address->release();
        $this->getEntityManager()->flush();
    }

    public function countByPool(Panel $panel, string $currency, ?string $network): int
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('IDENTITY(a.panel) = :panelId')
            ->andWhere('a.currency = :currency')
            // Binding the Panel entity directly (setParameter('x', $panel))
            // doesn't reliably infer the custom "uuid" DBAL type for the
            // comparison in this Doctrine ORM version -- compare against
            // the raw identifier with an explicit type instead.
            ->setParameter('panelId', $panel->getId(), 'uuid')
            ->setParameter('currency', $currency);

        if (null === $network) {
            $qb->andWhere('a.network IS NULL');
        } else {
            $qb->andWhere('a.network = :network')->setParameter('network', $network);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function nextSlotIndex(Panel $panel, string $currency, ?string $network): int
    {
        $qb = $this->createQueryBuilder('a')
            ->select('MAX(a.slotIndex)')
            ->andWhere('IDENTITY(a.panel) = :panelId')
            ->andWhere('a.currency = :currency')
            ->setParameter('panelId', $panel->getId(), 'uuid')
            ->setParameter('currency', $currency);

        if (null === $network) {
            $qb->andWhere('a.network IS NULL');
        } else {
            $qb->andWhere('a.network = :network')->setParameter('network', $network);
        }

        $max = $qb->getQuery()->getSingleScalarResult();

        return null === $max ? 0 : ((int) $max) + 1;
    }
}
