<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\CallbackDelivery;
use App\Entity\PaymentRequestInterface;
use App\Enum\CallbackDeliveryStatus;
use App\Enum\PaymentRequestType;
use App\Message\DispatchCallbackMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Builds the CallbackDelivery row for a request's current (terminal)
 * status and queues its first delivery attempt. Works against
 * PaymentRequestInterface so it's identical for deposits and withdrawals.
 */
final class CallbackDispatcher
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    public function dispatchFor(PaymentRequestInterface $request): CallbackDelivery
    {
        $payload = $request->toCallbackPayload();
        $payload['occurred_at'] = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        $delivery = new CallbackDelivery(
            PaymentRequestType::from($request->requestType()),
            $request->getId(),
            $request->callbackEventType(),
            $payload,
        );

        $this->entityManager->persist($delivery);
        $request->setCallbackStatus(CallbackDeliveryStatus::PENDING);
        $this->entityManager->flush();

        $this->messageBus->dispatch(new DispatchCallbackMessage($delivery->getId()));

        return $delivery;
    }

    public function redispatch(CallbackDelivery $delivery): void
    {
        $delivery->resetForManualResend();
        $this->entityManager->flush();

        $this->messageBus->dispatch(new DispatchCallbackMessage($delivery->getId()));
    }
}
