<?php

declare(strict_types=1);

namespace App\Tests\Unit\Panel\TestPanel;

use App\Entity\Panel;
use App\Enum\PaymentRequestStatus;
use App\Panel\Dto\WithdrawalExecutionRequest;
use App\Panel\Exception\PanelWalletProvisioningException;
use App\Panel\PanelRegistry;
use App\Panel\TestPanel\TestAddressGenerator;
use App\Panel\TestPanel\TestPanel;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class TestPanelTest extends TestCase
{
    private function panel(): TestPanel
    {
        return new TestPanel(new TestAddressGenerator(), new NullLogger());
    }

    private function row(): Panel
    {
        return new Panel(TestPanel::CODE, TestPanel::LABEL);
    }

    private function withdrawalRequest(): WithdrawalExecutionRequest
    {
        return new WithdrawalExecutionRequest('USDT', 'TRC20', '10', 'TAnyRealLookingAddress', null, 'client-id-1');
    }

    public function testCodeStaysBinanceTestAndLabelIsTestPanel(): void
    {
        self::assertSame('binance_test', $this->panel()->getCode());
        self::assertSame('Test Panel', TestPanel::LABEL);
        self::assertSame('Test Panel', (string) $this->row());
    }

    public function testIssuesDeterministicFakeAddress(): void
    {
        $panel = $this->panel();

        $first = $panel->fetchDepositAddress($this->row(), 'USDT', 'TRC20', 0);
        $second = $panel->fetchDepositAddress($this->row(), 'USDT', 'TRC20', 0);

        self::assertEquals($first, $second);
        self::assertStringStartsWith('TTEST', $first->address);
    }

    public function testPoolIsBounded(): void
    {
        $this->expectException(PanelWalletProvisioningException::class);

        $this->panel()->fetchDepositAddress($this->row(), 'USDT', 'TRC20', 50);
    }

    public function testWithdrawalIsAcceptedWithoutSendingAnythingAndIsDeterministic(): void
    {
        $panel = $this->panel();

        $result = $panel->executeWithdrawal($this->row(), $this->withdrawalRequest());

        self::assertSame(PaymentRequestStatus::SUBMITTED, $result->status);
        self::assertStringStartsWith('TEST-WD-', $result->panelWithdrawalReference);
        self::assertSame($result->panelWithdrawalReference, $panel->executeWithdrawal($this->row(), $this->withdrawalRequest())->panelWithdrawalReference);
    }

    public function testNothingIsDetectedAutomatically(): void
    {
        $panel = $this->panel();

        self::assertSame([], $panel->checkDeposits($this->row(), []));
        self::assertSame([], $panel->checkWithdrawals($this->row(), []));
    }

    public function testFakeIdentifiersAreVisiblyMarked(): void
    {
        self::assertStringStartsWith('test-', TestPanel::fakeTxHash('x'));
        self::assertStringStartsWith('TEST-DEP-', TestPanel::fakeDepositReference('x'));
        self::assertSame(TestPanel::fakeTxHash('x'), TestPanel::fakeTxHash('x'));
    }

    public function testRegistryResolvesItLikeAnyOtherDriver(): void
    {
        $registry = new PanelRegistry([$this->panel()]);

        self::assertSame(TestPanel::CODE, $registry->getDriverFor($this->row())->getCode());
    }
}
