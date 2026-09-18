<?php

declare(strict_types=1);

namespace App\Panel\Dto;

final readonly class DepositAddressResult
{
    public function __construct(
        public string $address,
        public ?string $addressTag = null,
    ) {
    }
}
