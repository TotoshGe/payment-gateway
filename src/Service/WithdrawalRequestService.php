<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\WithdrawalRequest;
use App\Enum\PaymentRequestStatus;
use App\Panel\Dto\WithdrawalExecutionRequest;
use App\Panel\Exception\PanelException;
use App\Panel\PanelRegistry;
use App\Repository\PanelRepository;
use App\Repository\WithdrawalRequestRepository;
use App\Service\Exception\PanelNotFoundException;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Uid\Uuid;

final class WithdrawalRequestService
{
    public function __construct(
        private readonly WithdrawalRequestRepository $withdrawalRequestRepository,
        private readonly PanelRepository $panelRepository,
        private readonly PanelRegistry $panelRegistry,
        private readonly CallbackDispatcher $callbackDispatcher,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{request: WithdrawalRequest, created: bool}
     */
    public function createOrGetExisting(
        string $externalReference,
        string $panelCode,
        string $currency,
        ?string $network,
        string $amount,
        string $destinationAddress,
        ?string $destinationTag,
    ): array {
        $existing = $this->withdrawalRequestRepository->findOneByExternalReference($externalReference);
        if (null !== $existing) {
            return ['request' => $existing, 'created' => false];
        }

        $panel = $this->panelRepository->findOneByCode($panelCode);
        if (null === $panel || !$panel->isActive()) {
            throw new PanelNotFoundException(sprintf('No active panel "%s".', $panelCode));
        }

        $clientWithdrawalId = Uuid::v4()->toRfc4122();
        $withdrawalRequest = new WithdrawalRequest($externalReference, $panel, $currency, $network, $amount, $destinationAddress, $destinationTag, $clientWithdrawalId);

        $this->entityManager->persist($withdrawalRequest);
        $this->entityManager->flush();

        $driver = $this->panelRegistry->getDriverFor($panel);

        try {
            $result = $driver->executeWithdrawal($panel, new WithdrawalExecutionRequest(
                currency: $currency,
                network: $network,
                amount: $amount,
                destinationAddress: $destinationAddress,
                destinationTag: $destinationTag,
                clientWithdrawalId: $clientWithdrawalId,
            ));

            $withdrawalRequest->setPanelWithdrawalReference($result->panelWithdrawalReference);
            $withdrawalRequest->setStatus($result->status);
            $this->entityManager->flush();
        } catch (PanelException $exception) {
            $this->logger->error('Panel rejected withdrawal submission', [
                'panel' => $panelCode,
                'externalReference' => $externalReference,
                'error' => $exception->getMessage(),
            ]);

            $withdrawalRequest->setStatus(PaymentRequestStatus::SUBMIT_FAILED);
            $withdrawalRequest->setFailureReason($exception->getMessage());
            $this->entityManager->flush();
            $this->callbackDispatcher->dispatchFor($withdrawalRequest);
        }

        return ['request' => $withdrawalRequest, 'created' => true];
    }

    public function applyStatusUpdate(WithdrawalRequest $withdrawalRequest, PaymentRequestStatus $status, ?string $txHash, ?string $failureReason): void
    {
        if ($withdrawalRequest->getStatus() === $status) {
            return;
        }

        $withdrawalRequest->setTxHash($txHash);
        $withdrawalRequest->setFailureReason($failureReason);
        $withdrawalRequest->setStatus($status);
        $this->entityManager->flush();

        if ($status->isTerminal()) {
            $this->callbackDispatcher->dispatchFor($withdrawalRequest);
        }
    }
}
