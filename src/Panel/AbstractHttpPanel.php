<?php

declare(strict_types=1);

namespace App\Panel;

use App\Panel\Exception\PanelException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface as HttpClientExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Shared "how to call a REST panel API reliably" layer: exponential
 * backoff with jitter on 429/5xx (mirrors the reconnect backoff rates-
 * service's AbstractExchangeConnector uses for WS reconnects), consistent
 * error mapping to PanelException. Concrete panels only implement their own
 * request signing/parsing.
 */
abstract class AbstractHttpPanel
{
    private const MAX_ATTEMPTS = 3;
    private const BASE_BACKOFF_SECONDS = 0.5;
    private const MAX_BACKOFF_SECONDS = 5.0;

    public function __construct(
        protected readonly HttpClientInterface $httpClient,
        protected readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @throws PanelException
     */
    protected function requestWithBackoff(string $method, string $url, array $options = []): ResponseInterface
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < self::MAX_ATTEMPTS) {
            $attempt++;

            try {
                $response = $this->httpClient->request($method, $url, $options);
                $statusCode = $response->getStatusCode();

                if ($statusCode < 500 && 429 !== $statusCode) {
                    return $response;
                }

                $lastException = new PanelException(sprintf(
                    '%s %s -> HTTP %d: %s',
                    $method,
                    $url,
                    $statusCode,
                    substr($response->getContent(false), 0, 500),
                ));
            } catch (HttpClientExceptionInterface $exception) {
                $lastException = new PanelException(sprintf('%s %s failed: %s', $method, $url, $exception->getMessage()), previous: $exception);
            }

            if ($attempt < self::MAX_ATTEMPTS) {
                $backoff = min(self::MAX_BACKOFF_SECONDS, self::BASE_BACKOFF_SECONDS * 2 ** ($attempt - 1));
                $jitter = $backoff * (mt_rand(0, 1000) / 1000) * 0.5;

                $this->logger->warning('Panel HTTP call failed, retrying', [
                    'panel' => $this->getCode(),
                    'attempt' => $attempt,
                    'error' => $lastException->getMessage(),
                ]);

                usleep((int) (($backoff + $jitter) * 1_000_000));
            }
        }

        throw $lastException ?? new PanelException(sprintf('%s %s failed after %d attempts', $method, $url, self::MAX_ATTEMPTS));
    }

    abstract public function getCode(): string;
}
