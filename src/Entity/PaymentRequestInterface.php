<?php

declare(strict_types=1);

namespace App\Entity;

use App\Enum\CallbackDeliveryStatus;
use App\Enum\PaymentRequestStatus;
use Symfony\Component\Uid\Uuid;

/**
 * Common shape shared by DepositRequest and WithdrawalRequest so that
 * CallbackDispatcher and the callback delivery pipeline can work with
 * either without knowing which flow it's handling.
 */
interface PaymentRequestInterface
{
    public function getId(): Uuid;

    public function getExternalReference(): string;

    public function getStatus(): PaymentRequestStatus;

    public function getCallbackStatus(): CallbackDeliveryStatus;

    public function setCallbackStatus(CallbackDeliveryStatus $callbackStatus): static;

    public function requestType(): string;

    /**
     * @return array<string, mixed>
     */
    public function toCallbackPayload(): array;

    public function callbackEventType(): string;
}
