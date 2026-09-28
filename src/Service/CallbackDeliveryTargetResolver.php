<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CallbackDelivery;
use App\Entity\PaymentRequestInterface;
use App\Enum\PaymentRequestType;
use App\Repository\DepositRequestRepository;
use App\Repository\WithdrawalRequestRepository;

/**
 * CallbackDelivery only knows its target by requestType+requestId (loose
 * coupling by design, see CallbackDelivery docblock); this is the one place
 * that turns that pair back into the actual DepositRequest/WithdrawalRequest,
 * so the callback pipeline's own state can be mirrored onto it.
 */
final class CallbackDeliveryTargetResolver
{
    public function __construct(
        private readonly DepositRequestRepository $depositRequestRepository,
        private readonly WithdrawalRequestRepository $withdrawalRequestRepository,
    ) {
    }

    public function resolve(CallbackDelivery $delivery): ?PaymentRequestInterface
    {
        return match ($delivery->getRequestType()) {
            PaymentRequestType::DEPOSIT => $this->depositRequestRepository->find($delivery->getRequestId()),
            PaymentRequestType::WITHDRAWAL => $this->withdrawalRequestRepository->find($delivery->getRequestId()),
        };
    }
}
