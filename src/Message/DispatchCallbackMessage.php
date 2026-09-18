<?php

declare(strict_types=1);

namespace App\Message;

use Symfony\Component\Uid\Uuid;

final readonly class DispatchCallbackMessage
{
    public function __construct(public Uuid $callbackDeliveryId)
    {
    }
}
