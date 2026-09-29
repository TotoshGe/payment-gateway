<?php

declare(strict_types=1);

namespace App\Panel\Dto;

use App\Enum\PaymentStatus;

final readonly class WithdrawalStatusUpdate
{
    public function __construct(
        public string $panelWithdrawalReference,
        public PaymentStatus $status,
        public ?string $txHash,
        public ?string $failureReason,
    ) {
    }
}
