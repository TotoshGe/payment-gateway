<?php

declare(strict_types=1);

namespace App\Dto\Api;

use Symfony\Component\Validator\Constraints as Assert;

final class CreateWithdrawalRequestDto
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 190)]
    public string $externalReference = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 64)]
    public string $panel = 'binance';

    #[Assert\NotBlank]
    #[Assert\Length(max: 32)]
    public string $currency = '';

    #[Assert\Length(max: 32)]
    public ?string $network = null;

    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^\d+(\.\d+)?$/', message: 'amount must be a plain decimal string.')]
    public string $amount = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $destinationAddress = '';

    #[Assert\Length(max: 128)]
    public ?string $destinationTag = null;
}
