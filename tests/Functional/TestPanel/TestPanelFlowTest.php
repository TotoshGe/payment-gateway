<?php

declare(strict_types=1);

namespace App\Tests\Functional\TestPanel;

use App\Entity\DepositRequest;
use App\Entity\Panel;
use App\Entity\WithdrawalRequest;
use App\Enum\PanelWalletAddressStatus;
use App\Enum\PaymentRequestStatus;
use App\Panel\TestPanel\TestPanelSimulationException;
use App\Panel\TestPanel\TestPanelSimulator;
use App\Repository\CallbackDeliveryRepository;
use App\Repository\DepositRequestRepository;
use App\Repository\PanelWalletAddressRepository;
use App\Repository\WithdrawalRequestRepository;
use App\Service\DepositRequestService;
use App\Tests\Functional\FunctionalTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Console\Tester\CommandTester;

final class TestPanelFlowTest extends FunctionalTestCase
{
    private const API_KEY = 'test_api_key';
    private function ensurePanel(EntityManagerInterface $em, string $code, bool $active = true): Panel
    {
        $panel = $em->getRepository(Panel::class)->findOneBy(['code' => $code]);
        if (null === $panel) {
            $panel = new Panel($code, 'binance_test' === $code ? 'Test Panel' : 'Fake panel');
            $em->persist($panel);
        }
        $panel->setActive($active);
        $em->flush();

        return $panel;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function post(KernelBrowser $client, string $path, array $payload, int $expectedStatus): array
    {
        $client->request('POST', $path, server: ['HTTP_X_API_KEY' => self::API_KEY, 'CONTENT_TYPE' => 'application/json'], content: json_encode($payload));
        self::assertResponseStatusCodeSame($expectedStatus);

        return json_decode($client->getResponse()->getContent(), true);
    }

    private function createDeposit(KernelBrowser $client, string $reference, string $network = 'TRC20', int $expectedStatus = 201): array
    {
        return $this->post($client, '/api/v1/deposits', [
            'external_reference' => $reference,
            'panel' => 'binance_test',
            'currency' => 'USDT',
            'network' => $network,
            'expected_amount' => '100.00',
        ], $expectedStatus);
    }

    public function testDepositGetsFakeAddressWithoutAnyTestMarkersInTheResponseAndIsIdempotent(): void
    {
        $client = static::createClient();
        $this->ensurePanel(self::getContainer()->get(EntityManagerInterface::class), 'binance_test');

        $first = $this->createDeposit($client, 'bt-'.uniqid());
        self::assertSame('awaiting_payment', $first['status']);
        self::assertStringStartsWith('TTEST', $first['address']);
        self::assertArrayNotHasKey('test_mode', $first);
        self::assertArrayNotHasKey('notice', $first);

        $client->request('GET', '/api/v1/deposits/'.$first['id'], server: ['HTTP_X_API_KEY' => self::API_KEY]);
        $fetched = json_decode($client->getResponse()->getContent(), true);
        self::assertArrayNotHasKey('test_mode', $fetched);
        self::assertArrayNotHasKey('notice', $fetched);

        $reference = 'bt-idem-'.uniqid();
        $a = $this->createDeposit($client, $reference);
        $b = $this->createDeposit($client, $reference, expectedStatus: 200);
        self::assertSame($a['id'], $b['id']);
        self::assertSame($a['address'], $b['address']);

        $other = $this->createDeposit($client, 'bt-other-'.uniqid());
        self::assertNotSame($a['address'], $other['address'], 'a concurrent open request must not share an address');
    }

    public function testConfirmingDepositCompletesItReleasesAddressAndQueuesCallbackWithoutTestMarkers(): void
    {
        $client = static::createClient();
        $container = self::getContainer();
        $this->ensurePanel($container->get(EntityManagerInterface::class), 'binance_test');
        $created = $this->createDeposit($client, 'bt-confirm-'.uniqid());

        $deposit = $container->get(DepositRequestRepository::class)->find(\Symfony\Component\Uid\Uuid::fromString($created['id']));
        $container->get(TestPanelSimulator::class)->transitionDeposit($deposit, PaymentRequestStatus::COMPLETED, '99.50');

        $em = $container->get(EntityManagerInterface::class);
        $em->refresh($deposit);
        self::assertSame(PaymentRequestStatus::COMPLETED, $deposit->getStatus());
        self::assertSame('99.50', $deposit->getReceivedAmount());

        $wallet = $container->get(PanelWalletAddressRepository::class)->findOneBy(['address' => $created['address']]);
        self::assertSame(PanelWalletAddressStatus::FREE, $wallet->getStatus());

        $deliveries = $container->get(CallbackDeliveryRepository::class)->findBy(['requestId' => $deposit->getId()]);
        self::assertCount(1, $deliveries);
        self::assertSame('deposit.received', $deliveries[0]->getEventType());
        self::assertArrayNotHasKey('test_mode', $deliveries[0]->getPayload());
        self::assertSame('99.50', $deliveries[0]->getPayload()['amount']);
    }

    public function testDepositStatusTransitions(): void
    {
        $client = static::createClient();
        $container = self::getContainer();
        $this->ensurePanel($container->get(EntityManagerInterface::class), 'binance_test');
        $simulator = $container->get(TestPanelSimulator::class);
        $repository = $container->get(DepositRequestRepository::class);
        $callbacks = $container->get(CallbackDeliveryRepository::class);

        $find = static fn (array $created): DepositRequest => $repository->find(\Symfony\Component\Uid\Uuid::fromString($created['id']));

        $received = $find($this->createDeposit($client, 'bt-recv-'.uniqid()));
        $simulator->transitionDeposit($received, PaymentRequestStatus::RECEIVED);
        self::assertSame(PaymentRequestStatus::RECEIVED, $received->getStatus());
        self::assertCount(0, $callbacks->findBy(['requestId' => $received->getId()]), 'non-terminal status sends no callback, same as the real poller');
        $simulator->transitionDeposit($received, PaymentRequestStatus::COMPLETED);
        self::assertSame(PaymentRequestStatus::COMPLETED, $received->getStatus());
        self::assertSame('100.00', $received->getReceivedAmount(), 'defaults to the expected amount');

        $failed = $find($this->createDeposit($client, 'bt-fail-'.uniqid()));
        try {
            $simulator->transitionDeposit($failed, PaymentRequestStatus::FAILED);
            self::fail('a deposit cannot fail on Binance, so it cannot on the Test Panel either');
        } catch (TestPanelSimulationException) {
        }
        $simulator->transitionDeposit($failed, PaymentRequestStatus::COMPLETED);

        $expired = $find($this->createDeposit($client, 'bt-exp-'.uniqid()));
        $simulator->transitionDeposit($expired, PaymentRequestStatus::EXPIRED);
        self::assertSame(PaymentRequestStatus::EXPIRED, $expired->getStatus());
        self::assertSame('deposit.expired', $callbacks->findBy(['requestId' => $expired->getId()])[0]->getEventType());

        foreach ([$received, $failed, $expired] as $terminal) {
            try {
                $simulator->transitionDeposit($terminal, PaymentRequestStatus::COMPLETED);
                self::fail('a terminal deposit must not be movable');
            } catch (TestPanelSimulationException) {
            }
        }
        self::assertCount(1, $callbacks->findBy(['requestId' => $failed->getId()]), 'no duplicate callback from the rejected transition');
        self::assertSame(PaymentRequestStatus::COMPLETED, $failed->getStatus());

        $fresh = $find($this->createDeposit($client, 'bt-bad-'.uniqid()));
        $this->expectException(TestPanelSimulationException::class);
        $simulator->transitionDeposit($fresh, PaymentRequestStatus::PROCESSING);
    }

    public function testWithdrawalIsAcceptedWithFakeReferenceAndCanBeConfirmedOrFailed(): void
    {
        $client = static::createClient();
        $container = self::getContainer();
        $this->ensurePanel($container->get(EntityManagerInterface::class), 'binance_test');

        $body = static fn (string $ref): array => [
            'external_reference' => $ref,
            'panel' => 'binance_test',
            'currency' => 'USDT',
            'network' => 'TRC20',
            'amount' => '25.00',
            'destination_address' => 'TRealLookingUserAddressXXXXXXXXXXXX',
        ];

        $created = $this->post($client, '/api/v1/withdrawals', $body('bt-wd-'.uniqid()), 201);
        self::assertSame('submitted', $created['status']);
        self::assertArrayNotHasKey('test_mode', $created);
        self::assertArrayNotHasKey('notice', $created);

        $repository = $container->get(WithdrawalRequestRepository::class);
        /** @var WithdrawalRequest $withdrawal */
        $withdrawal = $repository->find(\Symfony\Component\Uid\Uuid::fromString($created['id']));
        self::assertStringStartsWith('TEST-WD-', (string) $withdrawal->getPanelWithdrawalReference());

        $simulator = $container->get(TestPanelSimulator::class);
        $simulator->transitionWithdrawal($withdrawal, PaymentRequestStatus::PROCESSING);
        $simulator->transitionWithdrawal($withdrawal, PaymentRequestStatus::COMPLETED);
        self::assertSame(PaymentRequestStatus::COMPLETED, $withdrawal->getStatus());
        self::assertStringStartsWith('test-', (string) $withdrawal->getTxHash());

        $deliveries = $container->get(CallbackDeliveryRepository::class)->findBy(['requestId' => $withdrawal->getId()]);
        self::assertCount(1, $deliveries);
        self::assertSame('withdrawal.completed', $deliveries[0]->getEventType());
        self::assertArrayNotHasKey('test_mode', $deliveries[0]->getPayload());

        $second = $this->post($client, '/api/v1/withdrawals', $body('bt-wd2-'.uniqid()), 201);
        $failing = $repository->find(\Symfony\Component\Uid\Uuid::fromString($second['id']));
        $simulator->transitionWithdrawal($failing, PaymentRequestStatus::FAILED);
        self::assertSame(PaymentRequestStatus::FAILED, $failing->getStatus());
        self::assertNotSame('', (string) $failing->getFailureReason());
        self::assertSame('withdrawal.failed', $container->get(CallbackDeliveryRepository::class)->findBy(['requestId' => $failing->getId()])[0]->getEventType());
    }

    public function testSimulatorRefusesRequestsOnOtherPanels(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->ensurePanel($container->get(EntityManagerInterface::class), 'fake');
        $result = $container->get(DepositRequestService::class)->createOrGetExisting('real-'.uniqid(), 'fake', 'USDT', 'TRC20', '5');

        self::assertSame([], $container->get(TestPanelSimulator::class)->availableDepositTargets($result['request']));

        $this->expectException(TestPanelSimulationException::class);
        $container->get(TestPanelSimulator::class)->transitionDeposit($result['request'], PaymentRequestStatus::COMPLETED);
    }

    public function testInactivePanelRowIsUnavailable(): void
    {
        $client = static::createClient();
        $this->ensurePanel(self::getContainer()->get(EntityManagerInterface::class), 'binance_test', active: false);

        $this->createDeposit($client, 'bt-inactive-'.uniqid(), expectedStatus: 422);
    }

    public function testOldConfirmCommandNameIsAnAlias(): void
    {
        static::createClient();
        $application = new Application(self::$kernel);

        self::assertSame($application->find('app:test-panel:confirm'), $application->find('app:binance-test:confirm'));
    }

    public function testInstallCommandIsIdempotentAndActivatesPanelCopyingBinanceCurrencies(): void
    {
        static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $existing = $em->getRepository(Panel::class)->findOneBy(['code' => 'binance_test']);
        if (null !== $existing) {
            $em->remove($existing);
            $em->flush();
        }
        $binance = $em->getRepository(Panel::class)->findOneBy(['code' => 'binance']);
        if (null === $binance) {
            $binance = new Panel('binance', 'Binance');
            $em->persist($binance);
        }
        $binance->setSupportedCurrencies([['currency' => 'USDT', 'network' => 'TON']]);
        $em->flush();

        $tester = new CommandTester((new Application(self::$kernel))->find('app:binance-test:install'));
        $tester->execute([]);
        $tester->execute([]);
        self::assertSame(0, $tester->getStatusCode());

        $em->clear();
        $panels = $em->getRepository(Panel::class)->findBy(['code' => 'binance_test']);
        self::assertCount(1, $panels);
        self::assertTrue($panels[0]->isActive());
        self::assertSame('Test Panel', $panels[0]->getLabel());
        self::assertSame([['currency' => 'USDT', 'network' => 'TON']], $panels[0]->getSupportedCurrencies());
    }

    public function testConfirmCommandByUuidAndByExternalReference(): void
    {
        $client = static::createClient();
        $container = self::getContainer();
        $this->ensurePanel($container->get(EntityManagerInterface::class), 'binance_test');
        $reference = 'bt-cmd-'.uniqid();
        $created = $this->createDeposit($client, $reference);

        $tester = new CommandTester((new Application(self::$kernel))->find('app:test-panel:confirm'));

        $tester->execute(['id' => $created['id'], '--status' => 'received']);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('TEST PANEL', $tester->getDisplay());

        $tester->execute(['id' => $reference, '--amount' => '42']);
        self::assertSame(0, $tester->getStatusCode());

        $deposit = $container->get(DepositRequestRepository::class)->find(\Symfony\Component\Uid\Uuid::fromString($created['id']));
        $container->get(EntityManagerInterface::class)->refresh($deposit);
        self::assertSame(PaymentRequestStatus::COMPLETED, $deposit->getStatus());
        self::assertSame('42', $deposit->getReceivedAmount());

        $tester->execute(['id' => $created['id']]);
        self::assertSame(1, $tester->getStatusCode(), 'terminal request cannot be confirmed twice');

        $tester->execute(['id' => 'does-not-exist']);
        self::assertSame(1, $tester->getStatusCode());

        $tester->execute(['id' => $created['id'], '--status' => 'bogus']);
        self::assertSame(2, $tester->getStatusCode());
    }
}
