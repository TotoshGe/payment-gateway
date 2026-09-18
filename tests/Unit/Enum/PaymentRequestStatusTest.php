<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\PaymentRequestStatus;
use PHPUnit\Framework\TestCase;

final class PaymentRequestStatusTest extends TestCase
{
    public function testOnlyTheDocumentedFourStatusesAreTerminal(): void
    {
        $terminal = array_values(array_filter(PaymentRequestStatus::cases(), static fn (PaymentRequestStatus $s) => $s->isTerminal()));

        self::assertSame(
            ['completed', 'failed', 'expired', 'submit_failed'],
            array_map(static fn (PaymentRequestStatus $s) => $s->value, $terminal),
        );
    }

    public function testOnlyCompletedIsSuccess(): void
    {
        foreach (PaymentRequestStatus::cases() as $status) {
            self::assertSame(PaymentRequestStatus::COMPLETED === $status, $status->isSuccess());
        }
    }
}
