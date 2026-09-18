<?php

declare(strict_types=1);

namespace App\Panel\Dto;

final readonly class WithdrawalExecutionRequest
{
    public function __construct(
        public string $currency,
        public ?string $network,
        public string $amount,
        public string $destinationAddress,
        public ?string $destinationTag,
        public string $clientWithdrawalId,
    ) {
    }
}
