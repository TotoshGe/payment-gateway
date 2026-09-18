<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\WebhookSigner;
use PHPUnit\Framework\TestCase;

final class WebhookSignerTest extends TestCase
{
    public function testSignMatchesReferenceHmacComputation(): void
    {
        $signer = new WebhookSigner('super-secret');
        $body = '{"event_id":"abc"}';
        $timestamp = 1700000000;

        $expected = hash_hmac('sha256', $timestamp.'.'.$body, 'super-secret');

        self::assertSame($expected, $signer->sign($body, $timestamp));
    }

    public function testDifferentBodyProducesDifferentSignature(): void
    {
        $signer = new WebhookSigner('super-secret');

        self::assertNotSame(
            $signer->sign('{"a":1}', 1700000000),
            $signer->sign('{"a":2}', 1700000000),
        );
    }

    public function testDifferentTimestampProducesDifferentSignature(): void
    {
        $signer = new WebhookSigner('super-secret');

        self::assertNotSame(
            $signer->sign('{"a":1}', 1700000000),
            $signer->sign('{"a":1}', 1700000001),
        );
    }
}
