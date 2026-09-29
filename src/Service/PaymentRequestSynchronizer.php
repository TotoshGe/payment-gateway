<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\DepositRequest;
use App\Entity\Payment;
use App\Entity\WithdrawalRequest;
use App\Enum\PaymentRequestStatus;
use App\Enum\PaymentStatus;

/**
 * The single place where a request's status is derived from its payments
 * (mapping table: ARCHITECTURE.md section 1.6). Mutates the request only;
 * persistence, address release and callbacks stay in the request services.
 */
final class PaymentRequestSynchronizer
{
    /**
     * A deposit can see several payments to its address. A completed one
     * closes the request; otherwise any live one keeps it RECEIVED; only when
     * every payment is dead does the request pause. No payments -> unchanged.
     *
     * @return bool whether the request status changed
     */
    public function syncDeposit(DepositRequest $request): bool
    {
        $payments = $request->getPayments()->toArray();
        if ([] === $payments) {
            return false;
        }

        $completed = self::firstWithStatus($payments, PaymentStatus::COMPLETED);
        $live = self::lastMatching($payments, static fn (Payment $p) => !$p->getStatus()->isFinal());

        if (null !== $completed) {
            $lead = $completed;
            $status = PaymentStatus::COMPLETED->toDepositRequestStatus();
        } elseif (null !== $live) {
            $lead = $live;
            $status = $live->getStatus()->toDepositRequestStatus();
        } else {
            $lead = $payments[array_key_last($payments)];
            $status = $lead->getStatus()->toDepositRequestStatus();
        }

        if (PaymentRequestStatus::PAUSED !== $status) {
            $request->setReceivedAmount($lead->getAmount());
        }
        $request->setConfirmations($lead->getConfirmations());
        $request->setPanelDepositReference($lead->getPanelReference());

        if ($request->getStatus() === $status) {
            return false;
        }
        $request->setStatus($status);

        return true;
    }

    /**
     * A withdrawal has one payment (created with the request); the newest one
     * wins if an operator ever adds another.
     */
    public function syncWithdrawal(WithdrawalRequest $request): bool
    {
        $lead = $request->getLeadPayment();
        if (null === $lead) {
            return false;
        }

        $request->setTxHash($lead->getTxHash());
        $request->setFailureReason($lead->getReason());

        $status = $lead->getStatus()->toWithdrawalRequestStatus();
        if ($request->getStatus() === $status) {
            return false;
        }
        $request->setStatus($status);

        return true;
    }

    /**
     * @param Payment[] $payments
     */
    private static function firstWithStatus(array $payments, PaymentStatus $status): ?Payment
    {
        foreach ($payments as $payment) {
            if ($payment->getStatus() === $status) {
                return $payment;
            }
        }

        return null;
    }

    /**
     * @param Payment[] $payments
     */
    private static function lastMatching(array $payments, callable $predicate): ?Payment
    {
        for ($i = \count($payments) - 1; $i >= 0; --$i) {
            if ($predicate($payments[$i])) {
                return $payments[$i];
            }
        }

        return null;
    }
}
