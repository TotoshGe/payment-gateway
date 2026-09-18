<?php

declare(strict_types=1);

namespace App\Panel\Dto;

use App\Enum\PaymentRequestStatus;

final readonly class WithdrawalExecutionResult
{
    public function __construct(
        public string $panelWithdrawalReference,
        public PaymentRequestStatus $status,
    ) {
    }
}
