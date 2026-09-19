<?php

declare(strict_types=1);

namespace App\Tests\Fixture;

use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Stands in for the real HTTP client of BinancePanel in the test container.
 * The script is static because KernelBrowser reboots the kernel between
 * requests, which would otherwise drop instance state.
 */
final class ScriptedHttpClient extends MockHttpClient
{
    /** @var (callable(string, string, array<string, mixed>): array<mixed>)|null */
    public static $script = null;

    public function __construct()
    {
        parent::__construct(static function (string $method, string $url, array $options): MockResponse {
            if (null === self::$script) {
                throw new \LogicException('ScriptedHttpClient has no script; a test forgot to set one.');
            }

            return new MockResponse(json_encode((self::$script)($method, $url, $options), \JSON_THROW_ON_ERROR));
        });
    }
}
