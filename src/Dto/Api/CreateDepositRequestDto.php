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
}
