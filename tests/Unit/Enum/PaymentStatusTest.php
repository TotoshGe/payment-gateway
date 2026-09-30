<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\PaymentRequestStatus;
use App\Enum\PaymentStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PaymentStatusTest extends TestCase
{
    /**
     * @return iterable<string, array{PaymentStatus, PaymentRequestStatus, PaymentRequestStatus}>
     */
    public static function mapping(): iterable
    {
        yield 'pending' => [PaymentStatus::PENDING, PaymentRequestStatus::RECEIVED, PaymentRequestStatus::SUBMITTED];
        yield 'confirming' => [PaymentStatus::CONFIRMING, PaymentRequestStatus::RECEIVED, PaymentRequestStatus::PROCESSING];
        yield 'completed' => [PaymentStatus::COMPLETED, PaymentRequestStatus::COMPLETED, PaymentRequestStatus::COMPLETED];
        yield 'failed' => [PaymentStatus::FAILED, PaymentRequestStatus::PAUSED, PaymentRequestStatus::PAUSED];
        yield 'cancelled' => [PaymentStatus::CANCELLED, PaymentRequestStatus::PAUSED, PaymentRequestStatus::PAUSED];
    }

    #[DataProvider('mapping')]
    public function testMappingToRequestStatus(PaymentStatus $payment, PaymentRequestStatus $deposit, PaymentRequestStatus $withdrawal): void
    {
        self::assertSame($deposit, $payment->toDepositRequestStatus());
        self::assertSame($withdrawal, $payment->toWithdrawalRequestStatus());
    }

    public function testCancelledPaymentAlwaysPausesTheRequest(): void
    {
        self::assertSame(PaymentRequestStatus::PAUSED, PaymentStatus::CANCELLED->toDepositRequestStatus());
        self::assertSame(PaymentRequestStatus::PAUSED, PaymentStatus::CANCELLED->toWithdrawalRequestStatus());
    }

    public function testPausedIsNotTerminal(): void
    {
        self::assertFalse(PaymentRequestStatus::PAUSED->isTerminal());
    }

    public function testFinalStatuses(): void
    {
        $final = array_values(array_filter(PaymentStatus::cases(), static fn (PaymentStatus $s) => $s->isFinal()));

        self::assertSame([PaymentStatus::COMPLETED, PaymentStatus::FAILED, PaymentStatus::CANCELLED], $final);
    }
}
