<?php

declare(strict_types=1);

namespace App\Panel\Binance;

use App\Entity\DepositRequest;
use App\Entity\Panel;
use App\Entity\WithdrawalRequest;
use App\Panel\AbstractHttpPanel;
use App\Panel\Dto\DepositAddressResult;
use App\Panel\Dto\DepositStatusUpdate;
use App\Panel\Dto\WithdrawalExecutionRequest;
use App\Panel\Dto\WithdrawalExecutionResult;
use App\Panel\Dto\WithdrawalStatusUpdate;
use App\Panel\Exception\PanelException;
use App\Panel\Exception\PanelWalletProvisioningException;
use App\Panel\PanelInterface;
use App\Security\PanelCredentialsEncryptor;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Binance REST integration, signed with the account's own api key/secret
 * (HMAC-SHA256 over the query string, per Binance's convention) rather than
 * a third-party SDK -- see ARCHITECTURE.md section 8 for why.
 *
 * IMPORTANT (see ARCHITECTURE.md R1 resolution): Binance's standard deposit
 * address endpoint returns the SAME address for every call with the same
 * (coin, network) on one account -- there is no "generate a new address"
 * call for ordinary accounts. Slot 0 always resolves to that one master-
 * account address; any additional pool slot (>=1) must be backed by a
 * pre-existing Binance sub-account whose email is listed in
 * Panel::config['subAccounts']['{currency}:{network}'][slotIndex-1], using
 * the master-account "Get Sub-account Deposit Address" endpoint. If no such
 * sub-account is configured for that slot, provisioning fails loudly
 * (PanelWalletProvisioningException) instead of silently reusing an address
 * that's already held by another request.
 */
class BinancePanel extends AbstractHttpPanel implements PanelInterface
{
    private const DEFAULT_BASE_URL = 'https://api.binance.com';
    private const RECV_WINDOW = 5000;

    public function __construct(
        HttpClientInterface $httpClient,
        LoggerInterface $logger,
        private readonly PanelCredentialsEncryptor $credentialsEncryptor,
    ) {
        parent::__construct($httpClient, $logger);
    }

    public function getCode(): string
    {
        return 'binance';
    }

    public function fetchDepositAddress(Panel $panel, string $currency, ?string $network, int $slotIndex): DepositAddressResult
    {
        $params = array_filter([
            'coin' => $currency,
            'network' => $network,
        ], static fn ($value) => null !== $value);

        if ($slotIndex > 0) {
            $email = $this->resolveSubAccountEmail($panel, $currency, $network, $slotIndex);
            $params['email'] = $email;

            $data = $this->signedRequest($panel, 'GET', '/sapi/v1/capital/deposit/subAddress', $params);

            $address = $data['address'] ?? null;
            if (!\is_string($address) || '' === $address) {
                throw new PanelException(sprintf('Binance sub-account %s returned no deposit address for %s/%s.', $email, $currency, $network ?? '-'));
            }

            return new DepositAddressResult($address, $data['tag'] ?? null);
        }

        $data = $this->signedRequest($panel, 'GET', '/sapi/v1/capital/deposit/address', $params);

        $address = $data['address'] ?? null;
        if (!\is_string($address) || '' === $address) {
            throw new PanelException(sprintf('Binance returned no deposit address for %s/%s.', $currency, $network ?? '-'));
        }

        return new DepositAddressResult($address, ('' !== ($data['tag'] ?? '')) ? $data['tag'] : null);
    }

    public function checkDeposits(Panel $panel, array $activeRequests): iterable
    {
        /** @var array<string, DepositRequest[]> $byCurrencyNetwork */
        $byCurrencyNetwork = [];
        foreach ($activeRequests as $request) {
            $byCurrencyNetwork[$request->getCurrency().':'.($request->getNetwork() ?? '')][] = $request;
        }

        foreach ($byCurrencyNetwork as $key => $requests) {
            [$currency, $network] = array_pad(explode(':', $key, 2), 2, null);

            $params = array_filter([
                'coin' => $currency,
                'network' => '' === $network ? null : $network,
                'startTime' => (time() - 7 * 86400) * 1000,
            ], static fn ($value) => null !== $value);

            $deposits = $this->signedRequest($panel, 'GET', '/sapi/v1/capital/deposit/hisrec', $params);

            if (!\is_array($deposits)) {
                continue;
            }

            $byAddress = [];
            foreach ($requests as $request) {
                $byAddress[$request->getAddress().'|'.($request->getAddressTag() ?? '')] = $request;
            }

            foreach ($deposits as $deposit) {
                $address = $deposit['address'] ?? null;
                $tag = $deposit['addressTag'] ?? '';
                $matched = $byAddress[$address.'|'.$tag] ?? null;
                if (null === $matched) {
                    continue;
                }

                $status = BinanceStatusMapper::depositStatus((int) ($deposit['status'] ?? -1));
                if (null === $status) {
                    continue;
                }

                yield new DepositStatusUpdate(
                    depositRequestId: $matched->getId(),
                    status: $status,
                    observedAmount: (string) ($deposit['amount'] ?? '0'),
                    confirmations: self::parseConfirmations($deposit['confirmTimes'] ?? null),
                    panelDepositReference: isset($deposit['txId']) ? (string) $deposit['txId'] : null,
                );
            }
        }
    }

    public function executeWithdrawal(Panel $panel, WithdrawalExecutionRequest $request): WithdrawalExecutionResult
    {
        $params = array_filter([
            'coin' => $request->currency,
            'network' => $request->network,
            'address' => $request->destinationAddress,
            'addressTag' => $request->destinationTag,
            'amount' => $request->amount,
            'withdrawOrderId' => $request->clientWithdrawalId,
        ], static fn ($value) => null !== $value);

        $data = $this->signedRequest($panel, 'POST', '/sapi/v1/capital/withdraw/apply', $params);

        $id = $data['id'] ?? null;
        if (!\is_string($id) || '' === $id) {
            throw new PanelException('Binance withdraw/apply did not return a withdrawal id.');
        }

        return new WithdrawalExecutionResult($id, \App\Enum\PaymentRequestStatus::SUBMITTED);
    }

    public function checkWithdrawals(Panel $panel, array $activeRequests): iterable
    {
        /** @var array<string, WithdrawalRequest> $byClientId */
        $byClientId = [];
        foreach ($activeRequests as $request) {
            $byClientId[$request->getClientWithdrawalId()] = $request;
        }

        $params = [
            'startTime' => (time() - 30 * 86400) * 1000,
        ];

        $withdrawals = $this->signedRequest($panel, 'GET', '/sapi/v1/capital/withdraw/history', $params);

        if (!\is_array($withdrawals)) {
            return;
        }

        foreach ($withdrawals as $withdrawal) {
            $clientId = $withdrawal['withdrawOrderId'] ?? null;
            if (!\is_string($clientId) || !isset($byClientId[$clientId])) {
                continue;
            }

            $status = BinanceStatusMapper::withdrawalStatus((int) ($withdrawal['status'] ?? -1));

            yield new WithdrawalStatusUpdate(
                panelWithdrawalReference: (string) ($withdrawal['id'] ?? ''),
                status: $status,
                txHash: isset($withdrawal['txId']) && '' !== $withdrawal['txId'] ? (string) $withdrawal['txId'] : null,
                failureReason: \App\Enum\PaymentRequestStatus::FAILED === $status ? 'Binance withdraw status '.($withdrawal['status'] ?? '?') : null,
            );
        }
    }

    private function resolveSubAccountEmail(Panel $panel, string $currency, ?string $network, int $slotIndex): string
    {
        $config = $panel->getConfig();
        $key = $currency.':'.($network ?? '');
        /** @var list<string> $emails */
        $emails = $config['subAccounts'][$key] ?? [];

        $email = $emails[$slotIndex - 1] ?? null;
        if (null === $email) {
            throw new PanelWalletProvisioningException(sprintf(
                'No Binance sub-account configured for pool slot %d of %s (config.subAccounts["%s"]). '.
                'Binance\'s standard API only gives one deterministic deposit address per (coin, network) '.
                'on a single account -- extra pool slots require pre-provisioned sub-accounts.',
                $slotIndex,
                $key,
                $key,
            ));
        }

        return $email;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<mixed>
     */
    private function signedRequest(Panel $panel, string $method, string $path, array $params): array
    {
        $credentials = $this->credentialsEncryptor->decrypt($panel->getEncryptedCredentials() ?? '');
        $apiKey = $credentials['apiKey'] ?? null;
        $apiSecret = $credentials['apiSecret'] ?? null;

        if (!\is_string($apiKey) || !\is_string($apiSecret) || '' === $apiKey || '' === $apiSecret) {
            throw new PanelException(sprintf('Panel "%s" has no Binance apiKey/apiSecret configured.', $panel->getCode()));
        }

        $baseUrl = rtrim((string) ($panel->getConfig()['baseUrl'] ?? self::DEFAULT_BASE_URL), '/');

        $params['timestamp'] = (int) round(microtime(true) * 1000);
        $params['recvWindow'] = self::RECV_WINDOW;

        $query = http_build_query($params, '', '&', \PHP_QUERY_RFC3986);
        $signature = hash_hmac('sha256', $query, $apiSecret);
        $query .= '&signature='.$signature;

        $url = 'GET' === $method
            ? $baseUrl.$path.'?'.$query
            : $baseUrl.$path;

        $response = $this->requestWithBackoff($method, $url, [
            'headers' => ['X-MBX-APIKEY' => $apiKey],
            'body' => 'GET' === $method ? null : $query,
        ]);

        $decoded = json_decode($response->getContent(false), true);

        if (!\is_array($decoded)) {
            throw new PanelException(sprintf('Binance %s %s returned a non-JSON/non-array response.', $method, $path));
        }

        if (isset($decoded['code']) && isset($decoded['msg']) && $response->getStatusCode() >= 400) {
            throw new PanelException(sprintf('Binance %s %s failed: [%s] %s', $method, $path, $decoded['code'], $decoded['msg']));
        }

        return $decoded;
    }

    private static function parseConfirmations(mixed $confirmTimes): ?int
    {
        if (!\is_string($confirmTimes) || !str_contains($confirmTimes, '/')) {
            return null;
        }

        [$current] = explode('/', $confirmTimes, 2);

        return is_numeric($current) ? (int) $current : null;
    }
}
