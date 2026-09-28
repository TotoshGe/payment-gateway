<?php

declare(strict_types=1);

namespace App\Admin;

use App\Entity\CallbackDelivery;
use App\Entity\DepositRequest;
use App\Entity\Panel;
use App\Entity\PanelWalletAddress;
use App\Entity\WithdrawalRequest;
use App\Enum\CallbackDeliveryStatus;
use App\Enum\PanelWalletAddressStatus;
use App\Enum\PaymentRequestStatus;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Read-only counts for the dashboard KPI cards. Deliberately just counts
 * (no money aggregation, no time-period filter, no charts) -- the shell
 * redesign only needed a natural, cheap place to show the .pg-card/.pg-kpi
 * components; a fuller analytics dashboard is a separate feature.
 */
final class AdminDashboardStats
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function build(): array
    {
        return [
            'deposits' => $this->byStatus(DepositRequest::class),
            'withdrawals' => $this->byStatus(WithdrawalRequest::class),
            'panels' => $this->panelCounts(),
            'walletPool' => $this->walletPoolCounts(),
            'callbacksNeedingAttention' => $this->callbackDeliveryCount([CallbackDeliveryStatus::FAILED, CallbackDeliveryStatus::EXHAUSTED]),
        ];
    }

    /**
     * @return array{total: int, buckets: list<array{state: string, count: int}>}
     */
    private function byStatus(string $entityClass): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('e.status AS status', 'COUNT(e.id) AS c')
            ->from($entityClass, 'e')
            ->groupBy('e.status')
            ->getQuery()
            ->getArrayResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['status'] instanceof PaymentRequestStatus ? $row['status']->value : (string) $row['status']] = (int) $row['c'];
        }

        $buckets = [];
        $total = 0;
        foreach (PaymentRequestStatus::cases() as $case) {
            $count = $counts[$case->value] ?? 0;
            $total += $count;
            $buckets[] = ['state' => $case->value, 'count' => $count];
        }

        return ['total' => $total, 'buckets' => $buckets];
    }

    /**
     * @return array{total: int, active: int}
     */
    private function panelCounts(): array
    {
        $total = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(p.id)')->from(Panel::class, 'p')
            ->getQuery()->getSingleScalarResult();

        $active = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(p.id)')->from(Panel::class, 'p')
            ->andWhere('p.active = true')
            ->getQuery()->getSingleScalarResult();

        return ['total' => $total, 'active' => $active];
    }

    /**
     * @return array{free: int, held: int}
     */
    private function walletPoolCounts(): array
    {
        $rows = $this->entityManager->createQueryBuilder()
            ->select('a.status AS status', 'COUNT(a.id) AS c')
            ->from(PanelWalletAddress::class, 'a')
            ->groupBy('a.status')
            ->getQuery()
            ->getArrayResult();

        $counts = ['free' => 0, 'held' => 0];
        foreach ($rows as $row) {
            $status = $row['status'] instanceof PanelWalletAddressStatus ? $row['status']->value : (string) $row['status'];
            $counts[$status] = (int) $row['c'];
        }

        return $counts;
    }

    /**
     * @param list<CallbackDeliveryStatus> $statuses
     */
    private function callbackDeliveryCount(array $statuses): int
    {
        return (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(d.id)')->from(CallbackDelivery::class, 'd')
            ->andWhere('d.status IN (:statuses)')
            ->setParameter('statuses', $statuses)
            ->getQuery()->getSingleScalarResult();
    }
}
