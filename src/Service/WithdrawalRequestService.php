<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\WithdrawalRequest;
use App\Entity\Payment;
use App\Enum\PaymentRequestStatus;
use App\Enum\PaymentStatus;
use App\Panel\Dto\WithdrawalExecutionRequest;
use App\Panel\PanelRegistry;
use App\Repository\PanelRepository;
use App\Repository\WithdrawalRequestRepository;
use App\Repository\PaymentRepository;
use App\Service\Exception\PanelNotFoundException;
use App\Service\Exception\WithdrawalPaymentException;
use Doctrine\DBAL\LockMode;
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
        private readonly PaymentRepository $paymentRepository,
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

        $this->entityManager->persist($withdrawalRequest);
        $this->entityManager->flush();

        return ['request' => $withdrawalRequest, 'created' => true];
    }

    /**
     * Operator action ("Создать платёж"): the only place a withdrawal is sent
     * to a panel. The payment row is committed under a row lock before the
     * panel is called, so a double click cannot send the funds twice. Any
     * failure after that still leaves the payment (status FAILED, reason
     * stored) and pauses the request -- silently, Okean is not told.
     *
     * @throws WithdrawalPaymentException when the request is not eligible; nothing is created
     */
    public function createPayment(WithdrawalRequest $withdrawalRequest): Payment
    {
        $connection = $this->entityManager->getConnection();
        $connection->beginTransaction();
        try {
            $this->entityManager->refresh($withdrawalRequest, LockMode::PESSIMISTIC_WRITE);

            if (PaymentRequestStatus::AWAITING_PAYOUT !== $withdrawalRequest->getStatus()) {
                throw new WithdrawalPaymentException(sprintf('Платёж можно создать только для заявки в статусе «%s».', PaymentRequestStatus::AWAITING_PAYOUT->label()));
            }
            if ($this->paymentRepository->count(['withdrawalRequest' => $withdrawalRequest]) > 0) {
                throw new WithdrawalPaymentException('У заявки уже есть платёж.');
            }
            if (!$withdrawalRequest->getPanel()->isActive()) {
                throw new WithdrawalPaymentException(sprintf('Панель «%s» выключена; платёж не создан.', $withdrawalRequest->getPanel()->getCode()));
            }

            $payment = Payment::forWithdrawal($withdrawalRequest);
            $this->entityManager->persist($payment);
            $this->entityManager->flush();
            $connection->commit();
        } catch (\Throwable $exception) {
            // Not wrapInTransaction(): it would close the EntityManager on a plain refusal.
            $connection->rollBack();

            throw $exception;
        }

        $panel = $withdrawalRequest->getPanel();

        try {
            $result = $this->panelRegistry->getDriverFor($panel)->executeWithdrawal($panel, new WithdrawalExecutionRequest(
                currency: $withdrawalRequest->getCurrency(),
                network: $withdrawalRequest->getNetwork(),
                amount: $withdrawalRequest->getAmount(),
                destinationAddress: $withdrawalRequest->getDestinationAddress(),
                destinationTag: $withdrawalRequest->getDestinationTag(),
                clientWithdrawalId: $withdrawalRequest->getClientWithdrawalId(),
            ));

            $withdrawalRequest->setPanelWithdrawalReference($result->panelWithdrawalReference);
            $payment->setPanelReference($result->panelWithdrawalReference);
        } catch (\Throwable $exception) {
            $this->logger->error('Withdrawal payment failed on the panel', [
                'panel' => $panel->getCode(),
                'uuid' => $withdrawalRequest->getUuid(),
                'error' => $exception->getMessage(),
            ]);

            $payment->setStatus(PaymentStatus::FAILED)->setReason($exception->getMessage() ?: $exception::class);
        }

        $this->synchronizer->syncWithdrawal($withdrawalRequest);
        $this->entityManager->flush();

        return $payment;
    }

    public function applyPaymentUpdate(
        WithdrawalRequest $withdrawalRequest,
        PaymentStatus $status,
        ?string $txHash,
        ?string $reason,
        ?string $observedAmount = null,
        ?int $confirmations = null,
        ?int $requiredConfirmations = null,
    ): void {
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

        $paymentChanged = $payment->getStatus() !== $status
            || (null !== $txHash && $payment->getTxHash() !== $txHash)
            || (null !== $observedAmount && $payment->getAmount() !== $observedAmount)
            || $payment->getConfirmations() !== $confirmations
            || $payment->getRequiredConfirmations() !== $requiredConfirmations;

        if ($paymentChanged) {
            $payment->setStatus($status)->setTxHash($txHash ?? $payment->getTxHash())->setReason($reason)
                ->setConfirmations($confirmations)->setRequiredConfirmations($requiredConfirmations);
            if (null !== $observedAmount) {
                $payment->setAmount($observedAmount);
            }
        }

        $changed = $this->synchronizer->syncWithdrawal($withdrawalRequest);
        $this->entityManager->flush();

        $newStatus = $withdrawalRequest->getStatus();
        if (PaymentRequestStatus::COMPLETED === $newStatus && PaymentStatus::COMPLETED === $status && $changed) {
            $this->callbackDispatcher->dispatchFor($withdrawalRequest);
        } elseif (PaymentRequestStatus::AWAITING_CONFIRMATIONS === $newStatus && ($changed || $paymentChanged)) {
            // Repeats as confirmations grow; the status itself stays put.
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
