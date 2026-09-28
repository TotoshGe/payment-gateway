<?php

declare(strict_types=1);

namespace App\MessageHandler;

use App\Entity\CallbackDelivery;
use App\Message\DispatchCallbackMessage;
use App\Repository\CallbackDeliveryRepository;
use App\Security\WebhookSigner;
use App\Service\CallbackDeliveryTargetResolver;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Actually POSTs the callback to Okean. Failures are recorded on the
 * CallbackDelivery row (for admin visibility) and then rethrown so
 * Messenger's own retry_strategy (see config/packages/messenger.yaml)
 * handles the backoff/retry count; CallbackFailureSubscriber marks the
 * delivery EXHAUSTED once Messenger gives up for good.
 */
#[AsMessageHandler]
final class DispatchCallbackMessageHandler
{
    public function __construct(
        private readonly CallbackDeliveryRepository $callbackDeliveryRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly HttpClientInterface $httpClient,
        private readonly WebhookSigner $webhookSigner,
        private readonly LoggerInterface $logger,
        private readonly CallbackDeliveryTargetResolver $targetResolver,
        private readonly string $okeanCallbackUrl,
    ) {
    }

    public function __invoke(DispatchCallbackMessage $message): void
    {
        $delivery = $this->callbackDeliveryRepository->find($message->callbackDeliveryId);
        if (null === $delivery) {
            $this->logger->warning('DispatchCallbackMessage for unknown CallbackDelivery', [
                'callbackDeliveryId' => (string) $message->callbackDeliveryId,
            ]);

            return;
        }

        $body = json_encode([
            'event_id' => (string) $delivery->getEventId(),
            'type' => $delivery->getEventType(),
            ...$delivery->getPayload(),
        ], \JSON_THROW_ON_ERROR);

        $timestamp = time();

        try {
            $response = $this->httpClient->request('POST', $this->okeanCallbackUrl, [
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-Timestamp' => (string) $timestamp,
                    'X-Signature' => $this->webhookSigner->sign($body, $timestamp),
                ],
                'body' => $body,
                'timeout' => 10,
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode >= 200 && $statusCode < 300) {
                $delivery->recordSuccess($statusCode);
                $this->syncTargetCallbackStatus($delivery);
                $this->entityManager->flush();

                return;
            }

            $delivery->recordFailure($statusCode, 'Non-2xx response');
            $this->syncTargetCallbackStatus($delivery);
            $this->entityManager->flush();

            throw new \RuntimeException(sprintf('Callback to Okean returned HTTP %d', $statusCode));
        } catch (HttpClientExceptionInterface $exception) {
            $delivery->recordFailure(null, $exception->getMessage());
            $this->syncTargetCallbackStatus($delivery);
            $this->entityManager->flush();

            throw new \RuntimeException('Callback to Okean failed: '.$exception->getMessage(), previous: $exception);
        }
    }

    /**
     * DepositRequest/WithdrawalRequest.callbackStatus exists so the admin
     * grid can show delivery state without joining CallbackDelivery -- it
     * was only ever being set to PENDING at dispatch time and never
     * updated again, so it stayed "pending" forever even once the
     * callback was actually sent (or gave up). Mirror it here, on every
     * attempt, so it always reflects the CallbackDelivery it came from.
     */
    private function syncTargetCallbackStatus(CallbackDelivery $delivery): void
    {
        $this->targetResolver->resolve($delivery)?->setCallbackStatus($delivery->getStatus());
    }
}
