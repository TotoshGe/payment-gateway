<?php

declare(strict_types=1);

namespace App\Tests\Unit\Panel\Binance;

use App\Enum\PaymentStatus;
use App\Panel\Binance\BinanceStatusMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BinanceStatusMapperTest extends TestCase
{
    #[DataProvider('depositCases')]
    public function testDepositStatus(int $binanceStatus, ?PaymentStatus $expected): void
    {
        self::assertSame($expected, BinanceStatusMapper::depositStatus($binanceStatus));
    }

    /**
     * @return iterable<string, array{0: int, 1: ?PaymentStatus}>
     */
    public static function depositCases(): iterable
    {
        yield 'pending (0) is PENDING' => [0, PaymentStatus::PENDING];
        yield 'waiting user confirm (8) is PENDING' => [8, PaymentStatus::PENDING];
        yield 'credited but not withdrawable (6) is CONFIRMING' => [6, PaymentStatus::CONFIRMING];
        yield 'success (1) is COMPLETED' => [1, PaymentStatus::COMPLETED];
        yield 'rejected (2) is CANCELLED' => [2, PaymentStatus::CANCELLED];
        yield 'wrong deposit (7) is CANCELLED' => [7, PaymentStatus::CANCELLED];
        yield 'unknown code maps to null' => [99, null];
    }

    #[DataProvider('withdrawalCases')]
    public function testWithdrawalStatus(int $binanceStatus, PaymentStatus $expected): void
    {
        self::assertSame($expected, BinanceStatusMapper::withdrawalStatus($binanceStatus));
    }

    /**
     * @return iterable<string, array{0: int, 1: PaymentStatus}>
     */
    public static function withdrawalCases(): iterable
    {
        yield 'completed (6)' => [6, PaymentStatus::COMPLETED];
        yield 'cancelled (1) is CANCELLED' => [1, PaymentStatus::CANCELLED];
        yield 'rejected (3) is FAILED' => [3, PaymentStatus::FAILED];
        yield 'failure (5) is FAILED' => [5, PaymentStatus::FAILED];
        yield 'email sent (0) is still CONFIRMING' => [0, PaymentStatus::CONFIRMING];
        yield 'awaiting approval (2) is still CONFIRMING' => [2, PaymentStatus::CONFIRMING];
        yield 'processing (4) is CONFIRMING' => [4, PaymentStatus::CONFIRMING];
    }
}
