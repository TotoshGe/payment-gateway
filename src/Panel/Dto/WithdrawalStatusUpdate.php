<?php

declare(strict_types=1);

namespace App\Panel\Dto;

use App\Enum\PaymentRequestStatus;

final readonly class WithdrawalStatusUpdate
{
    public function __construct(
        public string $panelWithdrawalReference,
        public PaymentRequestStatus $status,
        public ?string $txHash,
        public ?string $failureReason,
    ) {
    }
}
