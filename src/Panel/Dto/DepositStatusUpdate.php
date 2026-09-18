<?php

declare(strict_types=1);

namespace App\Panel\Dto;

use App\Enum\PaymentRequestStatus;
use Symfony\Component\Uid\Uuid;

/**
 * One (address -> observed state) fact from checkDeposits(), matched back to
 * a DepositRequest by address (see PanelInterface::checkDeposits()).
 */
final readonly class DepositStatusUpdate
{
    public function __construct(
        public Uuid $depositRequestId,
        public PaymentRequestStatus $status,
        public string $observedAmount,
        public ?int $confirmations,
        public ?string $panelDepositReference,
    ) {
    }
}
