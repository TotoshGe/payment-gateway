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
        /** Amount as the panel reports it; compared with the request amount before a payment may close it. */
        public ?string $observedAmount = null,
        public ?int $confirmations = null,
        public ?int $requiredConfirmations = null,
    ) {
    }
}
