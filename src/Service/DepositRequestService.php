<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DepositRequest;
use App\Enum\PaymentRequestStatus;
use App\Repository\DepositRequestRepository;
use App\Repository\PanelRepository;
use App\Service\Exception\PanelNotFoundException;
use Doctrine\ORM\EntityManagerInterface;

final class DepositRequestService
{
    public function __construct(
        private readonly DepositRequestRepository $depositRequestRepository,
        private readonly PanelRepository $panelRepository,
        private readonly WalletAddressPoolService $walletAddressPoolService,
        private readonly CallbackDispatcher $callbackDispatcher,
        private readonly EntityManagerInterface $entityManager,
        private readonly int $addressTtlMinutes,
    ) {
    }

    /**
     * Idempotent by externalReference: a retried call with the same
     * reference returns the already-created request instead of reserving a
     * second address for it.
     *
     * @return array{request: DepositRequest, created: bool}
     */
    public function createOrGetExisting(
        string $externalReference,
        string $panelCode,
        string $currency,
        ?string $network,
        string $expectedAmount,
    ): array {
        $existing = $this->depositRequestRepository->findOneByExternalReference($externalReference);
        if (null !== $existing) {
            return ['request' => $existing, 'created' => false];
        }

        $panel = $this->panelRepository->findOneByCode($panelCode);
        if (null === $panel || !$panel->isActive()) {
            throw new PanelNotFoundException(sprintf('No active panel "%s".', $panelCode));
        }

        $expiresAt = (new \DateTimeImmutable())->modify(sprintf('+%d minutes', $this->addressTtlMinutes));
        $depositRequest = new DepositRequest($externalReference, $panel, $currency, $network, $expectedAmount, $expiresAt);

        $this->entityManager->persist($depositRequest);
        $this->entityManager->flush();

        try {
            $walletAddress = $this->walletAddressPoolService->reserveFor($depositRequest);
        } catch (\Throwable $exception) {
            // Defense in depth: WalletAddressPoolService already catches
            // PanelException internally, but the request row was already
            // flushed above (status NEW) -- any other unexpected failure
            // here must still resolve the request to a terminal status
            // instead of leaving an orphaned NEW row with no address and
            // no callback ever sent for it.
            $walletAddress = null;
        }

        if (null === $walletAddress) {
            $depositRequest->markSubmitFailed();
            $this->entityManager->flush();
            $this->callbackDispatcher->dispatchFor($depositRequest);

            return ['request' => $depositRequest, 'created' => true];
        }

        $depositRequest->assignWalletAddress($walletAddress);
        $this->entityManager->flush();

        return ['request' => $depositRequest, 'created' => true];
    }

    /**
     * Called by the deposit poller for requests past expiresAt with no
     * funds seen -- releases the address back to the pool and marks the
     * request EXPIRED (terminal, triggers a callback).
     */
    public function expire(DepositRequest $depositRequest): void
    {
        if (PaymentRequestStatus::AWAITING_PAYMENT !== $depositRequest->getStatus()) {
            return;
        }

        $this->walletAddressPoolService->release($depositRequest);
        $depositRequest->setStatus(PaymentRequestStatus::EXPIRED);
        $this->entityManager->flush();

        $this->callbackDispatcher->dispatchFor($depositRequest);
    }

    /**
     * Applies a status update from the panel poller. Releases the pooled
     * address once the request reaches a terminal status so it becomes
     * available for the next request.
     */
    public function applyStatusUpdate(DepositRequest $depositRequest, PaymentRequestStatus $status, string $observedAmount, ?int $confirmations, ?string $panelDepositReference): void
    {
        if ($depositRequest->getStatus() === $status) {
            return;
        }

        $depositRequest->setReceivedAmount($observedAmount);
        $depositRequest->setConfirmations($confirmations);
        $depositRequest->setPanelDepositReference($panelDepositReference);
        $depositRequest->setStatus($status);
        $this->entityManager->flush();

        if ($status->isTerminal()) {
            $this->walletAddressPoolService->release($depositRequest);
            $this->entityManager->flush();
            $this->callbackDispatcher->dispatchFor($depositRequest);
        }
    }
}
