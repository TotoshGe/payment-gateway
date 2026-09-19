<?php

declare(strict_types=1);

namespace App\Tests\Unit\Panel\BinanceTest;

use App\Entity\Panel;
use App\Enum\PaymentRequestStatus;
use App\Panel\BinanceTest\BinanceTestPanel;
use App\Panel\BinanceTest\TestAddressGenerator;
use App\Panel\Dto\WithdrawalExecutionRequest;
use App\Panel\Exception\PanelDisabledException;
use App\Panel\Exception\PanelWalletProvisioningException;
use App\Panel\PanelRegistry;
use App\Tests\Fixture\FakePanel;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class BinanceTestPanelTest extends TestCase
{
    private function panel(bool $enabled): BinanceTestPanel
    {
        return new BinanceTestPanel($enabled, new TestAddressGenerator(), new NullLogger());
    }

    private function row(): Panel
    {
        return new Panel(BinanceTestPanel::CODE, BinanceTestPanel::LABEL);
    }

    private function withdrawalRequest(): WithdrawalExecutionRequest
    {
        return new WithdrawalExecutionRequest('USDT', 'TRC20', '10', 'TAnyRealLookingAddress', null, 'client-id-1');
    }

    public function testDisabledPanelRefusesToIssueAddresses(): void
    {
        $this->expectException(PanelDisabledException::class);

        $this->panel(false)->fetchDepositAddress($this->row(), 'USDT', 'TRC20', 0);
    }

    public function testDisabledPanelRefusesWithdrawals(): void
    {
        $this->expectException(PanelDisabledException::class);

        $this->panel(false)->executeWithdrawal($this->row(), $this->withdrawalRequest());
    }

    public function testEnabledPanelIssuesDeterministicFakeAddress(): void
    {
        $panel = $this->panel(true);

        $first = $panel->fetchDepositAddress($this->row(), 'USDT', 'TRC20', 0);
        $second = $panel->fetchDepositAddress($this->row(), 'USDT', 'TRC20', 0);

        self::assertEquals($first, $second);
        self::assertStringStartsWith('TTEST', $first->address);
    }

    public function testPoolIsBounded(): void
    {
        $this->expectException(PanelWalletProvisioningException::class);

        $this->panel(true)->fetchDepositAddress($this->row(), 'USDT', 'TRC20', 50);
    }

    public function testWithdrawalIsAcceptedWithoutSendingAnythingAndIsDeterministic(): void
    {
        $panel = $this->panel(true);

        $result = $panel->executeWithdrawal($this->row(), $this->withdrawalRequest());

        self::assertSame(PaymentRequestStatus::SUBMITTED, $result->status);
        self::assertStringStartsWith('TEST-WD-', $result->panelWithdrawalReference);
        self::assertSame($result->panelWithdrawalReference, $panel->executeWithdrawal($this->row(), $this->withdrawalRequest())->panelWithdrawalReference);
    }

    public function testNothingIsDetectedAutomatically(): void
    {
        $panel = $this->panel(true);

        self::assertSame([], $panel->checkDeposits($this->row(), []));
        self::assertSame([], $panel->checkWithdrawals($this->row(), []));
    }

    public function testFakeIdentifiersAreVisiblyMarked(): void
    {
        self::assertStringStartsWith('test-', BinanceTestPanel::fakeTxHash('x'));
        self::assertStringStartsWith('TEST-DEP-', BinanceTestPanel::fakeDepositReference('x'));
        self::assertSame(BinanceTestPanel::fakeTxHash('x'), BinanceTestPanel::fakeTxHash('x'));
    }

    public function testRegistryHidesDisabledPanelButNotOthers(): void
    {
        $row = $this->row();

        $disabledRegistry = new PanelRegistry([$this->panel(false), new FakePanel()]);
        self::assertFalse($disabledRegistry->isAvailable($row));
        self::assertTrue($disabledRegistry->isAvailable(new Panel('fake', 'Fake')));
        self::assertSame('fake', $disabledRegistry->getDriverFor(new Panel('fake', 'Fake'))->getCode());

        $enabledRegistry = new PanelRegistry([$this->panel(true)]);
        self::assertTrue($enabledRegistry->isAvailable($row));
        self::assertSame(BinanceTestPanel::CODE, $enabledRegistry->getDriverFor($row)->getCode());
    }

    public function testRegistryThrowsForDisabledPanelDriverLookup(): void
    {
        $this->expectException(PanelDisabledException::class);

        (new PanelRegistry([$this->panel(false)]))->getDriverFor($this->row());
    }

    public function testPanelRowIsRecognisedAsTestPanelOnlyByExactCode(): void
    {
        self::assertTrue($this->row()->isTestPanel());
        self::assertFalse((new Panel('binance', 'Binance'))->isTestPanel());
        self::assertFalse((new Panel('binance_test2', 'x'))->isTestPanel());
        self::assertSame('Binance Test [TEST]', (string) $this->row());
    }
}
