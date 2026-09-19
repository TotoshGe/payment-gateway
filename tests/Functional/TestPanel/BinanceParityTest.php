<?php

declare(strict_types=1);

namespace App\Tests\Functional\TestPanel;

use App\Entity\DepositRequest;
use App\Entity\Panel;
use App\Entity\WithdrawalRequest;
use App\Enum\PanelWalletAddressStatus;
use App\Enum\PaymentRequestStatus;
use App\Panel\TestPanel\TestPanelSimulator;
use App\Repository\CallbackDeliveryRepository;
use App\Repository\PanelWalletAddressRepository;
use App\Security\PanelCredentialsEncryptor;
use App\Service\DepositRequestService;
use App\Tests\Fixture\ScriptedHttpClient;
use App\Tests\Functional\FunctionalTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/**
 * Runs the identical scenario against the real BinancePanel driver (HTTP
 * mocked) and against the Test Panel, through the same API, services,
 * pollers and callback dispatcher, and requires the observable transcript --
 * response fields, status sequence, callback events and payload fields,
 * address pool behaviour -- to be equal. The only thing allowed to differ is
 * how "funds arrived" / "withdrawal executed" are triggered.
 */
final class BinanceParityTest extends FunctionalTestCase
{
    private const API_KEY = 'test_api_key';

    /** @var list<array<string, mixed>> */
    private array $binanceDepositHistory = [];

    /** @var list<array<string, mixed>> */
    private array $binanceWithdrawalHistory = [];

    private ?string $binanceClientWithdrawalId = null;

    protected function tearDown(): void
    {
        ScriptedHttpClient::$script = null;
        parent::tearDown();
    }

    public function testBothPanelsProduceTheSameObservableFlow(): void
    {
        $binance = $this->runScenario('binance');
        $this->resetState();
        $test = $this->runScenario('binance_test');

        self::assertSame($binance, $test);
        self::assertSame(['id', 'external_reference', 'status', 'currency', 'network', 'expected_amount', 'received_amount', 'address', 'address_tag', 'expires_at'], $test['deposit_response_keys']);
        self::assertSame(['id', 'external_reference', 'status', 'currency', 'network', 'amount', 'destination_address', 'tx_hash'], $test['withdrawal_response_keys']);
    }

    private function resetState(): void
    {
        self::ensureKernelShutdown();
        $this->binanceDepositHistory = [];
        $this->binanceWithdrawalHistory = [];
        $this->binanceClientWithdrawalId = null;
        parent::setUp();
    }

    private function installBinanceScript(): void
    {
        ScriptedHttpClient::$script = function (string $method, string $url, array $options): array {
            $path = parse_url($url, \PHP_URL_PATH);
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

            return match ($path) {
                '/sapi/v1/capital/deposit/address' => ['address' => 'binance-master-address', 'tag' => ''],
                '/sapi/v1/capital/deposit/subAddress' => ['address' => 'binance-sub-address-'.$query['email']],
                '/sapi/v1/capital/deposit/hisrec' => $this->binanceDepositHistory,
                '/sapi/v1/capital/withdraw/apply' => (function () use ($options): array {
                    parse_str((string) $options['body'], $body);
                    $this->binanceClientWithdrawalId = $body['withdrawOrderId'];

                    return ['id' => 'binance-wd-1'];
                })(),
                '/sapi/v1/capital/withdraw/history' => $this->binanceWithdrawalHistory,
                default => throw new \LogicException('Unexpected Binance call '.$path),
            };
        };
    }

    private function preparePanel(string $code): void
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $panel = $em->getRepository(Panel::class)->findOneBy(['code' => $code]) ?? new Panel($code, $code);
        $panel->setActive(true);
        $panel->setSupportedCurrencies([['currency' => 'USDT', 'network' => 'TRC20']]);
        $panel->setConfig(['subAccounts' => ['USDT:TRC20' => ['sub1@example.com']]]);
        $panel->setEncryptedCredentials(self::getContainer()->get(PanelCredentialsEncryptor::class)->encrypt(['apiKey' => 'k', 'apiSecret' => 's']));
        $em->persist($panel);
        $em->flush();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{int, array<string, mixed>}
     */
    private function call(KernelBrowser $client, string $method, string $path, array $payload = []): array
    {
        $client->request($method, $path, server: ['HTTP_X_API_KEY' => self::API_KEY, 'CONTENT_TYPE' => 'application/json'], content: [] === $payload ? null : json_encode($payload));

        return [$client->getResponse()->getStatusCode(), json_decode($client->getResponse()->getContent(), true)];
    }

    private function pollDeposits(string $code): void
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:payment-gateway:poll-deposits'));
        $tester->execute(['panel-code' => $code, '--once' => true]);
    }

    private function pollWithdrawals(string $code): void
    {
        $tester = new CommandTester((new Application(self::$kernel))->find('app:payment-gateway:poll-withdrawals'));
        $tester->execute(['panel-code' => $code, '--once' => true]);
    }

    /**
     * @return list<array{string, list<string>, string}>
     */
    private function callbacks(Uuid $requestId): array
    {
        $rows = self::getContainer()->get(CallbackDeliveryRepository::class)->findBy(['requestId' => $requestId], ['createdAt' => 'ASC']);

        return array_map(static function ($delivery): array {
            $keys = array_keys($delivery->getPayload());
            sort($keys);

            return [$delivery->getEventType(), $keys, $delivery->getPayload()['status']];
        }, $rows);
    }

    private function reload(string $class, string $id): DepositRequest|WithdrawalRequest
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();

        return $em->getRepository($class)->find(Uuid::fromString($id));
    }

    /**
     * @return array<string, mixed>
     */
    private function runScenario(string $code): array
    {
        $client = static::createClient();
        $this->installBinanceScript();
        $this->preparePanel($code);
        $isTest = 'binance_test' === $code;
        $transcript = [];

        $body = ['external_reference' => 'parity-dep-'.$code, 'panel' => $code, 'currency' => 'USDT', 'network' => 'TRC20', 'expected_amount' => '100.00'];
        [$status, $first] = $this->call($client, 'POST', '/api/v1/deposits', $body);
        $transcript['deposit_create_http'] = $status;
        $transcript['deposit_response_keys'] = array_keys($first);
        $transcript['deposit_status'] = $first['status'];

        [$status, $again] = $this->call($client, 'POST', '/api/v1/deposits', $body);
        $transcript['deposit_repeat_http'] = $status;
        $transcript['deposit_repeat_same_id_and_address'] = $again['id'] === $first['id'] && $again['address'] === $first['address'];

        [, $second] = $this->call($client, 'POST', '/api/v1/deposits', ['external_reference' => 'parity-dep2-'.$code] + $body);
        $transcript['second_open_deposit_status'] = $second['status'];
        $transcript['second_open_deposit_distinct_address'] = $second['address'] !== $first['address'];

        [$status, $fetched] = $this->call($client, 'GET', '/api/v1/deposits/'.$first['id']);
        $transcript['deposit_get_http'] = $status;
        $transcript['deposit_get_keys'] = array_keys($fetched);

        $deposit = $this->reload(DepositRequest::class, $first['id']);
        if ($isTest) {
            self::getContainer()->get(TestPanelSimulator::class)->transitionDeposit($deposit, PaymentRequestStatus::COMPLETED, '99.50');
        } else {
            $this->binanceDepositHistory = [['address' => $first['address'], 'addressTag' => '', 'amount' => '99.50', 'status' => 1, 'confirmTimes' => '12/12', 'txId' => 'tx-1']];
            $this->pollDeposits($code);
        }
        $deposit = $this->reload(DepositRequest::class, $first['id']);
        $transcript['deposit_final_status'] = $deposit->getStatus()->value;
        $transcript['deposit_received_amount'] = $deposit->getReceivedAmount();
        $transcript['deposit_callbacks'] = $this->callbacks($deposit->getId());
        $transcript['deposit_address_released'] = PanelWalletAddressStatus::FREE === self::getContainer()->get(PanelWalletAddressRepository::class)->findOneBy(['address' => $first['address']])->getStatus();

        $intermediate = $this->reload(DepositRequest::class, $second['id']);
        if ($isTest) {
            self::getContainer()->get(TestPanelSimulator::class)->transitionDeposit($intermediate, PaymentRequestStatus::RECEIVED);
        } else {
            $this->binanceDepositHistory = [['address' => $second['address'], 'addressTag' => '', 'amount' => '100.00', 'status' => 6, 'confirmTimes' => '1/12', 'txId' => 'tx-2']];
            $this->pollDeposits($code);
        }
        $intermediate = $this->reload(DepositRequest::class, $second['id']);
        $transcript['received_status'] = $intermediate->getStatus()->value;
        $transcript['received_callbacks'] = $this->callbacks($intermediate->getId());

        $open = $this->call($client, 'POST', '/api/v1/deposits', ['external_reference' => 'parity-exp-'.$code] + $body)[1];
        self::getContainer()->get(DepositRequestService::class)->expire($this->reload(DepositRequest::class, $open['id']));
        $expired = $this->reload(DepositRequest::class, $open['id']);
        $transcript['expired_status'] = $expired->getStatus()->value;
        $transcript['expired_callbacks'] = $this->callbacks($expired->getId());

        $withdrawalBody = ['external_reference' => 'parity-wd-'.$code, 'panel' => $code, 'currency' => 'USDT', 'network' => 'TRC20', 'amount' => '25.00', 'destination_address' => 'TDestinationAddressXXXXXXXXXXXXXXXXX'];
        [$status, $withdrawal] = $this->call($client, 'POST', '/api/v1/withdrawals', $withdrawalBody);
        $transcript['withdrawal_create_http'] = $status;
        $transcript['withdrawal_response_keys'] = array_keys($withdrawal);
        $transcript['withdrawal_status'] = $withdrawal['status'];
        $transcript['withdrawal_repeat_http'] = $this->call($client, 'POST', '/api/v1/withdrawals', $withdrawalBody)[0];

        $entity = $this->reload(WithdrawalRequest::class, $withdrawal['id']);
        $steps = [];
        foreach ([PaymentRequestStatus::PROCESSING, PaymentRequestStatus::COMPLETED] as $target) {
            if ($isTest) {
                self::getContainer()->get(TestPanelSimulator::class)->transitionWithdrawal($entity, $target);
            } else {
                $this->binanceWithdrawalHistory = [[
                    'id' => 'binance-wd-1',
                    'withdrawOrderId' => $this->binanceClientWithdrawalId,
                    'status' => PaymentRequestStatus::PROCESSING === $target ? 4 : 6,
                    'txId' => PaymentRequestStatus::PROCESSING === $target ? '' : 'tx-out',
                ]];
                $this->pollWithdrawals($code);
            }
            $entity = $this->reload(WithdrawalRequest::class, $withdrawal['id']);
            $steps[] = $entity->getStatus()->value;
        }
        $transcript['withdrawal_statuses'] = $steps;
        $transcript['withdrawal_has_tx_hash'] = '' !== (string) $entity->getTxHash();
        $transcript['withdrawal_callbacks'] = $this->callbacks($entity->getId());

        [, $failing] = $this->call($client, 'POST', '/api/v1/withdrawals', ['external_reference' => 'parity-wd2-'.$code] + $withdrawalBody);
        $entity = $this->reload(WithdrawalRequest::class, $failing['id']);
        if ($isTest) {
            self::getContainer()->get(TestPanelSimulator::class)->transitionWithdrawal($entity, PaymentRequestStatus::FAILED);
        } else {
            $this->binanceWithdrawalHistory = [['id' => 'binance-wd-1', 'withdrawOrderId' => $this->binanceClientWithdrawalId, 'status' => 5, 'txId' => '']];
            $this->pollWithdrawals($code);
        }
        $entity = $this->reload(WithdrawalRequest::class, $failing['id']);
        $transcript['withdrawal_failed_status'] = $entity->getStatus()->value;
        $transcript['withdrawal_failed_callbacks'] = $this->callbacks($entity->getId());

        return $transcript;
    }
}
