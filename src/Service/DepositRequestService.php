<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DepositRequest;
use App\Entity\Payment;
use App\Enum\PaymentRequestStatus;
use App\Enum\PaymentRequestType;
use App\Enum\PaymentStatus;
use App\Repository\PaymentRepository;
use App\Panel\PanelRegistry;
use App\Repository\DepositRequestRepository;
use App\Repository\PanelRepository;
use App\Service\Exception\PanelNotFoundException;
use Doctrine\ORM\EntityManagerInterface;

final class DepositRequestService
{
    public function __construct(
        private readonly DepositRequestRepository $depositRequestRepository,
        private readonly PanelRepository $panelRepository,
        private readonly PanelRegistry $panelRegistry,
        private readonly PanelRouter $panelRouter,
        private readonly WalletAddressPoolService $walletAddressPoolService,
        private readonly PaymentRepository $paymentRepository,
        private readonly PaymentRequestSynchronizer $synchronizer,
        private readonly CallbackDispatcher $callbackDispatcher,
        private readonly EntityManagerInterface $entityManager,
        private readonly int $addressTtlMinutes,
        private readonly int $pausedTimeoutHours = 72,
    ) {
    }

    /**
     * Idempotent by uuid: a retried call with the same
     * uuid returns the already-created request instead of reserving a
     * second address for it.
     *
     * @return array{request: DepositRequest, created: bool}
     */
    public function createOrGetExisting(
        string $uuid,
        ?string $panelCode,
        string $currency,
        ?string $network,
        string $expectedAmount,
    ): array {
        $existing = $this->depositRequestRepository->findOneByUuid($uuid);
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

        $expiresAt = (new \DateTimeImmutable())->modify(sprintf('+%d minutes', $this->addressTtlMinutes));
        $depositRequest = new DepositRequest($uuid, $panel, $currency, $network, $expectedAmount, $expiresAt);

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
     * Paused longer than the configured timeout with no payment rescuing it:
     * same terminal outcome and callback (deposit.expired) as an ordinary
     * expiry. Only deposits -- a paused withdrawal always needs a human.
     * Funds arriving after this are not tracked (the address goes back to the
     * pool); see ARCHITECTURE.md 1.6.
     */
    public function expirePaused(DepositRequest $depositRequest, \DateTimeImmutable $now = new \DateTimeImmutable()): bool
    {
        $pausedAt = $depositRequest->getPausedAt();
        if (PaymentRequestStatus::PAUSED !== $depositRequest->getStatus() || null === $pausedAt) {
            return false;
        }
        if ($pausedAt > $now->modify(sprintf('-%d hours', $this->pausedTimeoutHours))) {
            return false;
        }

        $this->walletAddressPoolService->release($depositRequest);
        $depositRequest->setStatus(PaymentRequestStatus::EXPIRED);
        $this->entityManager->flush();
        $this->callbackDispatcher->dispatchFor($depositRequest);

        return true;
    }

    /**
     * Applies one observed payment fact from the panel poller: links the
     * payment to this request (creating it on first sight -- deposit requests
     * are created without one), updates it, then re-derives the request
     * status from its payments. The pooled address is released once the
     * request is terminal; PAUSED keeps it held.
     */
    public function applyPaymentUpdate(DepositRequest $depositRequest, PaymentStatus $status, string $observedAmount, ?int $confirmations, ?string $panelReference, ?string $reason = null): void
    {
        if ($depositRequest->getStatus()->isTerminal()) {
            return;
        }

        $payment = $this->findOrLinkPayment($depositRequest, $observedAmount, $panelReference);
        if (null === $payment) {
            return;
        }

        if ($payment->getStatus()->isFinal() && $payment->getStatus() !== $status) {
            return;
        }

        $isNew = $this->entityManager->getUnitOfWork()->isScheduledForInsert($payment);
        $changed = $payment->getStatus() !== $status
            || $payment->getAmount() !== $observedAmount
            || $payment->getConfirmations() !== $confirmations;

        if ($changed) {
            $payment->setStatus($status)->setAmount($observedAmount)->setConfirmations($confirmations)->setReason($reason);
        }
        if (null !== $panelReference) {
            $payment->setPanelReference($panelReference)->setTxHash($panelReference);
        }

        // Re-observing an unchanged payment must not re-derive the status, or
        // a resumed request would be paused again by the same cancelled payment.
        if (!$isNew && !$changed) {
            return;
        }

        $requestChanged = $this->synchronizer->syncDeposit($depositRequest);
        $this->entityManager->flush();

        if (!$requestChanged) {
            // Payment moved (status/confirmations) but the request status did
            // not: still tell Okean, unless the request is paused/terminal
            // (those already sent their own event).
            if (PaymentRequestStatus::RECEIVED === $depositRequest->getStatus()) {
                $this->callbackDispatcher->dispatchFor($depositRequest);
            }

            return;
        }

        $newStatus = $depositRequest->getStatus();
        if ($newStatus->isTerminal()) {
            $this->walletAddressPoolService->release($depositRequest);
            $this->entityManager->flush();
        }
        // RECEIVED maps to deposit.updated, terminal/PAUSED to their own event.
        $this->callbackDispatcher->dispatchFor($depositRequest);
    }

    /**
     * Admin action: a paused deposit goes back to waiting; a new payment to
     * the still-held address re-derives the status on its own.
     */
    public function resume(DepositRequest $depositRequest): void
    {
        if (PaymentRequestStatus::PAUSED !== $depositRequest->getStatus()) {
            return;
        }

        $depositRequest->setStatus(PaymentRequestStatus::AWAITING_PAYMENT);
        $this->entityManager->flush();
    }

    /**
     * The panel reference (tx id) identifies the transfer across polls. A
     * transfer already linked to a different request (address reused by the
     * pool) is never re-linked.
     */
    private function findOrLinkPayment(DepositRequest $depositRequest, string $observedAmount, ?string $panelReference): ?Payment
    {
        if (null !== $panelReference) {
            $existing = $this->paymentRepository->findOneByPanelReference($depositRequest->getPanel(), PaymentRequestType::DEPOSIT, $panelReference);
            if (null !== $existing) {
                return $existing->getDepositRequest() === $depositRequest ? $existing : null;
            }
        } else {
            foreach ($depositRequest->getPayments() as $candidate) {
                if (!$candidate->getStatus()->isFinal()) {
                    return $candidate;
                }
            }
        }

        $payment = Payment::forDeposit($depositRequest, $observedAmount);
        $this->entityManager->persist($payment);

        return $payment;
    }
}
