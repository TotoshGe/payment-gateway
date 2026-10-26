<?php

declare(strict_types=1);

namespace App\Dto\Api;

use Symfony\Component\Validator\Constraints as Assert;

final class CreateDepositRequestDto
{
    #[Assert\NotBlank]
    #[Assert\Uuid(message: 'uuid must be a valid UUID.')]
    public string $uuid = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 32)]
    public string $currency = '';

    #[Assert\Length(max: 32)]
    public ?string $network = null;

    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^\d+(\.\d+)?$/', message: 'expected_amount must be a plain decimal string.')]
    public string $expectedAmount = '';

    /** How long the client has to pay, asked by the caller; null = app.deposit_address_ttl_minutes. */
    #[Assert\Range(min: 5, max: 1440, notInRangeMessage: 'expires_in_minutes must be between {{ min }} and {{ max }}.')]
    public ?int $expiresInMinutes = null;
}
