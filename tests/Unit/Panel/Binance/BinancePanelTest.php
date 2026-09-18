<?php

declare(strict_types=1);

namespace App\Tests\Unit\Panel\Binance;

use App\Entity\DepositRequest;
use App\Entity\Panel;
use App\Entity\PanelWalletAddress;
use App\Entity\WithdrawalRequest;
use App\Enum\PaymentRequestStatus;
use App\Panel\Binance\BinancePanel;
use App\Panel\Dto\WithdrawalExecutionRequest;
use App\Panel\Exception\PanelException;
use App\Panel\Exception\PanelWalletProvisioningException;
use App\Security\PanelCredentialsEncryptor;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * No real network calls -- MockHttpClient intercepts every request and lets
 * us assert exactly what BinancePanel sent (method, path, signed params,
 * X-MBX-APIKEY header) plus how it parses Binance's response shapes.
 */
final class BinancePanelTest extends TestCase
{
    /**
     * MockHttpClient normalizes 'headers' into a list of "Name: value"
     * strings by the time the callback sees them, rather than the assoc
     * array shape BinancePanel passed in -- handle both.
     */
    private static function headersContain(array $headers, string $name, string $value): bool
    {
        if (isset($headers[$name]) && $headers[$name] === $value) {
            return true;
        }

        foreach ($headers as $key => $headerValue) {
            $line = \is_int($key) ? (string) $headerValue : $key.': '.$headerValue;
            if (0 === strcasecmp($line, $name.': '.$value)) {
                return true;
            }
        }

        return false;
    }

    private function makeEncryptor(): PanelCredentialsEncryptor
    {
        return new PanelCredentialsEncryptor(base64_encode(str_repeat('k', SODIUM_CRYPTO_SECRETBOX_KEYBYTES)));
    }

    private function makePanel(PanelCredentialsEncryptor $encryptor, array $config = []): Panel
    {
        $panel = new Panel('binance', 'Binance');
        $panel->setConfig($config);
        $panel->setEncryptedCredentials($encryptor->encrypt(['apiKey' => 'test-key', 'apiSecret' => 'test-secret']));

        return $panel;
    }

    public function testFetchDepositAddressSlotZeroSignsRequestAndParsesAddress(): void
    {
        $capturedUrl = null;
        $capturedHeaders = null;

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedUrl, &$capturedHeaders) {
            $capturedUrl = $url;
            $capturedHeaders = $options['headers'] ?? [];

            self::assertSame('GET', $method);

            return new MockResponse(json_encode(['address' => 'Txyz123', 'coin' => 'USDT', 'tag' => '']));
        });

        $encryptor = $this->makeEncryptor();
        $panel = $this->makePanel($encryptor);
        $binancePanel = new BinancePanel($httpClient, new NullLogger(), $encryptor);

        $result = $binancePanel->fetchDepositAddress($panel, 'USDT', 'TRC20', 0);

        self::assertSame('Txyz123', $result->address);
        self::assertNull($result->addressTag);

        self::assertStringStartsWith('https://api.binance.com/sapi/v1/capital/deposit/address?', $capturedUrl);
        self::assertStringContainsString('coin=USDT', $capturedUrl);
        self::assertStringContainsString('network=TRC20', $capturedUrl);
        self::assertStringContainsString('signature=', $capturedUrl);
        self::assertTrue(
            self::headersContain($capturedHeaders, 'X-MBX-APIKEY', 'test-key'),
            'Expected X-MBX-APIKEY: test-key header, got: '.json_encode($capturedHeaders),
        );

        // The signature must be a valid HMAC-SHA256 of the query string built
        // from every other param (Binance's own signing convention).
        parse_str(explode('?', $capturedUrl, 2)[1], $params);
        $signature = $params['signature'];
        unset($params['signature']);
        $expectedSignature = hash_hmac('sha256', http_build_query($params, '', '&', \PHP_QUERY_RFC3986), 'test-secret');
        self::assertSame($expectedSignature, $signature);
    }

    public function testFetchDepositAddressSlotWithoutSubAccountThrowsWithoutHttpCall(): void
    {
        $httpClient = new MockHttpClient(function () {
            self::fail('No HTTP call should be made when no sub-account slot is configured.');
        });

        $encryptor = $this->makeEncryptor();
        $panel = $this->makePanel($encryptor);
        $binancePanel = new BinancePanel($httpClient, new NullLogger(), $encryptor);

        $this->expectException(PanelWalletProvisioningException::class);
        $binancePanel->fetchDepositAddress($panel, 'USDT', 'TRC20', 1);
    }

    public function testFetchDepositAddressSlotWithConfiguredSubAccountCallsSubAddressEndpoint(): void
    {
        $capturedUrl = null;
        $httpClient = new MockHttpClient(function (string $method, string $url) use (&$capturedUrl) {
            $capturedUrl = $url;

            return new MockResponse(json_encode(['address' => 'Tsub456', 'tag' => '']));
        });

        $encryptor = $this->makeEncryptor();
        $panel = $this->makePanel($encryptor, ['subAccounts' => ['USDT:TRC20' => ['sub1@example.com']]]);
        $binancePanel = new BinancePanel($httpClient, new NullLogger(), $encryptor);

        $result = $binancePanel->fetchDepositAddress($panel, 'USDT', 'TRC20', 1);

        self::assertSame('Tsub456', $result->address);
        self::assertStringContainsString('/sapi/v1/capital/deposit/subAddress', $capturedUrl);
        self::assertStringContainsString('email=sub1%40example.com', $capturedUrl);
    }

    public function testCheckDepositsMatchesByAddressAndMapsStatus(): void
    {
        $panel = $this->makePanel($this->makeEncryptor());

        $matching = new DepositRequest('ext-1', $panel, 'USDT', 'TRC20', '100.00', new \DateTimeImmutable('+1 hour'));
        $matching->assignWalletAddress(new PanelWalletAddress($panel, 'USDT', 'TRC20', 0, 'Tmatch'));

        $httpClient = new MockHttpClient(function () {
            return new MockResponse(json_encode([
                ['address' => 'Tmatch', 'addressTag' => '', 'amount' => '100.00000000', 'status' => 1, 'txId' => 'tx-abc'],
                ['address' => 'Tother', 'addressTag' => '', 'amount' => '5.00', 'status' => 1, 'txId' => 'tx-xyz'],
            ]));
        });

        $encryptor = $this->makeEncryptor();
        $binancePanel = new BinancePanel($httpClient, new NullLogger(), $encryptor);

        $updates = iterator_to_array($binancePanel->checkDeposits($panel, [$matching]));

        self::assertCount(1, $updates);
        self::assertSame($matching->getId()->toRfc4122(), $updates[0]->depositRequestId->toRfc4122());
        self::assertSame(PaymentRequestStatus::COMPLETED, $updates[0]->status);
        self::assertSame('100.00000000', $updates[0]->observedAmount);
        self::assertSame('tx-abc', $updates[0]->panelDepositReference);
    }

    public function testExecuteWithdrawalSendsClientWithdrawalIdAsWithdrawOrderId(): void
    {
        $capturedBody = null;
        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (&$capturedBody) {
            self::assertSame('POST', $method);
            $capturedBody = $options['body'] ?? '';

            return new MockResponse(json_encode(['id' => 'binance-withdraw-1']));
        });

        $encryptor = $this->makeEncryptor();
        $panel = $this->makePanel($encryptor);
        $binancePanel = new BinancePanel($httpClient, new NullLogger(), $encryptor);

        $result = $binancePanel->executeWithdrawal($panel, new WithdrawalExecutionRequest(
            currency: 'USDT',
            network: 'TRC20',
            amount: '10.00',
            destinationAddress: 'Tdest',
            destinationTag: null,
            clientWithdrawalId: 'client-abc-123',
        ));

        self::assertSame('binance-withdraw-1', $result->panelWithdrawalReference);
        self::assertSame(PaymentRequestStatus::SUBMITTED, $result->status);
        self::assertStringContainsString('withdrawOrderId=client-abc-123', $capturedBody);
    }

    public function testBinanceErrorResponseIsMappedToPanelException(): void
    {
        $httpClient = new MockHttpClient(function () {
            return new MockResponse(json_encode(['code' => -2008, 'msg' => 'Invalid Api-Key ID.']), ['http_code' => 401]);
        });

        $encryptor = $this->makeEncryptor();
        $panel = $this->makePanel($encryptor);
        $binancePanel = new BinancePanel($httpClient, new NullLogger(), $encryptor);

        $this->expectException(PanelException::class);
        $this->expectExceptionMessageMatches('/Invalid Api-Key ID/');
        $binancePanel->fetchDepositAddress($panel, 'USDT', 'TRC20', 0);
    }
}
