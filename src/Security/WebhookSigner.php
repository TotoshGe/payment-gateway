<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Signs outbound callback bodies to Okean: HMAC-SHA256 over
 * "{timestamp}.{rawBody}" with a secret separate from the inbound
 * X-Api-Key, so compromising one direction doesn't compromise the other.
 * See ARCHITECTURE.md 3.4.
 */
final class WebhookSigner
{
    public function __construct(private readonly string $secret)
    {
    }

    public function sign(string $rawBody, int $timestamp): string
    {
        return hash_hmac('sha256', $timestamp.'.'.$rawBody, $this->secret);
    }
}
