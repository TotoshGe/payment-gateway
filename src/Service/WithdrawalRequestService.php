<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\WithdrawalRequest;
use App\Entity\Payment;
use App\Enum\PaymentRequestStatus;
use App\Enum\PaymentStatus;
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
        private readonly PanelRouter $panelRouter,
        private readonly PaymentRequestSynchronizer $synchronizer,
        private readonly CallbackDispatcher $callbackDispatcher,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{request: WithdrawalRequest, created: bool}
     */
    public function createOrGetExisting(
        string $uuid,
        ?string $panelCode,
        string $currency,
        ?string $network,
        string $amount,
        string $destinationAddress,
        ?string $destinationTag,
    ): array {
        $existing = $this->withdrawalRequestRepository->findOneByUuid($uuid);
        if (null !== $existing) {
            return ['request' => $existing, 'created' => false];
        }

        if (null === $panelCode) {
            $panel = $this->panelRouter->resolve($currency, $network);
        } else {
            // Only internal callers/tests name a panel; the public API never does.
            $panel = $this->panelRepository->findOneByCode($panelCode);
            if (null === $panel || !$panel->isActive()) {
                throw new PanelNotFoundException(sprintf('No active panel "%s".', $panelCode));
            }
        }

        $clientWithdrawalId = Uuid::v4()->toRfc4122();
        $withdrawalRequest = new WithdrawalRequest($uuid, $panel, $currency, $network, $amount, $destinationAddress, $destinationTag, $clientWithdrawalId);

        $payment = Payment::forWithdrawal($withdrawalRequest);

        $this->entityManager->persist($withdrawalRequest);
        $this->entityManager->persist($payment);
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
            $payment->setPanelReference($result->panelWithdrawalReference);
            $this->entityManager->flush();
        } catch (PanelException $exception) {
            $this->logger->error('Panel rejected withdrawal submission', [
                'panel' => $panel->getCode(),
                'uuid' => $uuid,
                'error' => $exception->getMessage(),
            ]);

            $payment->setStatus(PaymentStatus::FAILED)->setReason($exception->getMessage());
            $withdrawalRequest->setStatus(PaymentRequestStatus::SUBMIT_FAILED);
            $withdrawalRequest->setFailureReason($exception->getMessage());
            $this->entityManager->flush();
            $this->callbackDispatcher->dispatchFor($withdrawalRequest);
        }

        return ['request' => $withdrawalRequest, 'created' => true];
    }

    public function applyPaymentUpdate(WithdrawalRequest $withdrawalRequest, PaymentStatus $status, ?string $txHash, ?string $reason): void
    {
        if ($withdrawalRequest->getStatus()->isTerminal()) {
            return;
        }

        $payment = $withdrawalRequest->getLeadPayment();
        if (null === $payment) {
            return;
        }

        if ($payment->getStatus()->isFinal() && $payment->getStatus() !== $status) {
            return;
        }

        if ($payment->getStatus() !== $status || $payment->getTxHash() !== $txHash) {
            $payment->setStatus($status)->setTxHash($txHash)->setReason($reason);
        }

        $changed = $this->synchronizer->syncWithdrawal($withdrawalRequest);
        $this->entityManager->flush();

        if ($changed && ($withdrawalRequest->getStatus()->isTerminal() || PaymentRequestStatus::PAUSED === $withdrawalRequest->getStatus())) {
            $this->callbackDispatcher->dispatchFor($withdrawalRequest);
        }
    }

    /**
     * Admin action for a paused withdrawal (its payment was cancelled): the
     * funds were not sent, so it is closed as FAILED and Okean is told
     * (withdrawal.failed). Never re-sent automatically.
     */
    public function failPaused(WithdrawalRequest $withdrawalRequest): void
    {
        if (PaymentRequestStatus::PAUSED !== $withdrawalRequest->getStatus()) {
            return;
        }

        $withdrawalRequest->setStatus(PaymentRequestStatus::FAILED);
        $this->entityManager->flush();
        $this->callbackDispatcher->dispatchFor($withdrawalRequest);
    }
}
