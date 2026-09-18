<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DepositRequest;
use App\Entity\Panel;
use App\Entity\PanelWalletAddress;
use App\Panel\Exception\PanelException;
use App\Panel\Exception\PanelWalletProvisioningException;
use App\Panel\PanelRegistry;
use App\Repository\PanelWalletAddressRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Reserve/release of pre-provisioned deposit addresses (ARCHITECTURE.md R1
 * resolution): instead of asking the panel for a fresh address per request
 * (Binance would just hand back the same reused address), we hold an
 * exclusive claim on one pooled address per active DepositRequest and give
 * it back to the pool once that request reaches a terminal status.
 */
final class WalletAddressPoolService
{
    public function __construct(
        private readonly PanelWalletAddressRepository $walletAddressRepository,
        private readonly PanelRegistry $panelRegistry,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Reserves one free address for $depositRequest, provisioning one more
     * pool slot on the fly (bounded: exactly one extra slot, not an
     * unbounded loop) if the pool is currently fully held. Returns null if
     * the pool is exhausted and no further slot can be provisioned either.
     */
    public function reserveFor(DepositRequest $depositRequest): ?PanelWalletAddress
    {
        $panel = $depositRequest->getPanel();
        $currency = $depositRequest->getCurrency();
        $network = $depositRequest->getNetwork();

        $reserved = $this->walletAddressRepository->tryReserve($panel, $currency, $network, $depositRequest->getId());
        if (null !== $reserved) {
            return $reserved;
        }

        if ($this->provisionNextSlot($panel, $currency, $network)) {
            return $this->walletAddressRepository->tryReserve($panel, $currency, $network, $depositRequest->getId());
        }

        return null;
    }

    public function release(DepositRequest $depositRequest): void
    {
        $this->walletAddressRepository->releaseByDepositRequestId($depositRequest->getId());
    }

    /**
     * Fetches and persists one more pool slot from the panel. Returns false
     * if the panel has no further slot available (e.g. no sub-account
     * configured) -- that's an expected, non-fatal outcome the caller
     * surfaces as "no capacity right now", not a crash.
     */
    private function provisionNextSlot(Panel $panel, string $currency, ?string $network): bool
    {
        $slotIndex = $this->walletAddressRepository->nextSlotIndex($panel, $currency, $network);
        $driver = $this->panelRegistry->getDriverFor($panel);

        try {
            $result = $driver->fetchDepositAddress($panel, $currency, $network, $slotIndex);
        } catch (PanelWalletProvisioningException $exception) {
            // Expected/non-fatal: no sub-account configured for this slot yet.
            $this->logger->info('Wallet pool exhausted, no further slot could be provisioned', [
                'panel' => $panel->getCode(),
                'currency' => $currency,
                'network' => $network,
                'slotIndex' => $slotIndex,
                'reason' => $exception->getMessage(),
            ]);

            return false;
        } catch (PanelException $exception) {
            // Unexpected (API/network/credentials failure): still surfaced
            // as "no capacity right now" to the caller rather than a 500,
            // but logged louder since ops should investigate.
            $this->logger->error('Wallet pool provisioning failed unexpectedly', [
                'panel' => $panel->getCode(),
                'currency' => $currency,
                'network' => $network,
                'slotIndex' => $slotIndex,
                'error' => $exception->getMessage(),
            ]);

            return false;
        }

        $address = new PanelWalletAddress($panel, $currency, $network, $slotIndex, $result->address, $result->addressTag);
        $this->entityManager->persist($address);
        $this->entityManager->flush();

        $this->logger->info('Provisioned an extra wallet pool slot', [
            'panel' => $panel->getCode(),
            'currency' => $currency,
            'network' => $network,
            'slotIndex' => $slotIndex,
        ]);

        return true;
    }
}
