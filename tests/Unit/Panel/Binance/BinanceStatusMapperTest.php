<?php

declare(strict_types=1);

namespace App\Tests\Unit\Panel\Binance;

use App\Enum\PaymentRequestStatus;
use App\Panel\Binance\BinanceStatusMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BinanceStatusMapperTest extends TestCase
{
    #[DataProvider('depositCases')]
    public function testDepositStatus(int $binanceStatus, ?PaymentRequestStatus $expected): void
    {
        self::assertSame($expected, BinanceStatusMapper::depositStatus($binanceStatus));
    }

    /**
     * @return iterable<string, array{0: int, 1: ?PaymentRequestStatus}>
     */
    public static function depositCases(): iterable
    {
        yield 'pending (0) is not actionable yet' => [0, null];
        yield 'credited but not withdrawable (6) is RECEIVED' => [6, PaymentRequestStatus::RECEIVED];
        yield 'success (1) is COMPLETED' => [1, PaymentRequestStatus::COMPLETED];
        yield 'unknown code maps to null' => [99, null];
    }

    #[DataProvider('withdrawalCases')]
    public function testWithdrawalStatus(int $binanceStatus, PaymentRequestStatus $expected): void
    {
        self::assertSame($expected, BinanceStatusMapper::withdrawalStatus($binanceStatus));
    }

    /**
     * @return iterable<string, array{0: int, 1: PaymentRequestStatus}>
     */
    public static function withdrawalCases(): iterable
    {
        yield 'completed (6)' => [6, PaymentRequestStatus::COMPLETED];
        yield 'cancelled (1) is FAILED' => [1, PaymentRequestStatus::FAILED];
        yield 'rejected (3) is FAILED' => [3, PaymentRequestStatus::FAILED];
        yield 'failure (5) is FAILED' => [5, PaymentRequestStatus::FAILED];
        yield 'email sent (0) is still PROCESSING' => [0, PaymentRequestStatus::PROCESSING];
        yield 'awaiting approval (2) is still PROCESSING' => [2, PaymentRequestStatus::PROCESSING];
        yield 'processing (4) is PROCESSING' => [4, PaymentRequestStatus::PROCESSING];
    }
}
