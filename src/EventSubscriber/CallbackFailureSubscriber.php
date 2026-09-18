<?php

declare(strict_types=1);

namespace App\EventSubscriber;

use App\Message\DispatchCallbackMessage;
use App\Repository\CallbackDeliveryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;

/**
 * Marks a CallbackDelivery EXHAUSTED once Messenger's retry_strategy has
 * genuinely given up (not on every individual failed attempt -- those stay
 * FAILED and get retried, see DispatchCallbackMessageHandler).
 */
final class CallbackFailureSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly CallbackDeliveryRepository $callbackDeliveryRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            WorkerMessageFailedEvent::class => 'onMessageFailed',
        ];
    }

    public function onMessageFailed(WorkerMessageFailedEvent $event): void
    {
        if ($event->willRetry()) {
            return;
        }

        $message = $event->getEnvelope()->getMessage();
        if (!$message instanceof DispatchCallbackMessage) {
            return;
        }

        $delivery = $this->callbackDeliveryRepository->find($message->callbackDeliveryId);
        if (null === $delivery) {
            return;
        }

        $delivery->markExhausted();
        $this->entityManager->flush();
    }
}
