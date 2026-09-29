<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Panel;
use App\Repository\WithdrawalRequestRepository;
use App\Tests\Fixture\FakePanel;
use App\Tests\Functional\FunctionalTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class WithdrawalApiTest extends FunctionalTestCase
{
    private const API_KEY = 'test_api_key';

    private function persistFakePanel(EntityManagerInterface $em, string $code = 'fake'): Panel
    {
        $existing = $em->getRepository(Panel::class)->findOneBy(['code' => $code]);
        if (null !== $existing) {
            return $existing;
        }

        $panel = new Panel($code, 'Fake panel');
        $panel->setActive(true);
        $em->persist($panel);
        $em->flush();

        return $panel;
    }

    public function testCreateWithdrawalRequestReturns201AndSubmitsToPanel(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->persistFakePanel($em);

        $client->request('POST', '/api/v1/withdrawals', server: [
            'HTTP_X_API_KEY' => self::API_KEY,
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'uuid' => \Symfony\Component\Uid\Uuid::v4()->toRfc4122(),
            'panel' => 'fake',
            'currency' => 'USDT',
            'network' => 'TRC20',
            'amount' => '42.50',
            'destination_address' => 'Tdestination',
        ]));

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('submitted', $data['status']);
        self::assertCount(1, $data['payments']);
        self::assertSame('pending', $data['payments'][0]['status']);
        self::assertSame('42.50', $data['payments'][0]['amount']);
        self::assertSame('USDT', $data['payments'][0]['currency']);
    }

    public function testCreateWithdrawalRequestIsIdempotentByUuid(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->persistFakePanel($em);
        $uuid = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();

        $payload = json_encode([
            'uuid' => $uuid,
            'panel' => 'fake',
            'currency' => 'USDT',
            'network' => 'TRC20',
            'amount' => '42.50',
            'destination_address' => 'Tdestination',
        ]);

        $client->request('POST', '/api/v1/withdrawals', server: ['HTTP_X_API_KEY' => self::API_KEY, 'CONTENT_TYPE' => 'application/json'], content: $payload);
        self::assertResponseStatusCodeSame(201);
        $first = json_decode($client->getResponse()->getContent(), true);

        $client->request('POST', '/api/v1/withdrawals', server: ['HTTP_X_API_KEY' => self::API_KEY, 'CONTENT_TYPE' => 'application/json'], content: $payload);
        self::assertResponseStatusCodeSame(200);
        $second = json_decode($client->getResponse()->getContent(), true);

        self::assertSame($first['id'], $second['id']);

        /** @var WithdrawalRequestRepository $repository */
        $repository = self::getContainer()->get(WithdrawalRequestRepository::class);
        self::assertCount(1, $repository->findBy(['uuid' => $uuid]));
    }

    public function testPanelRejectionResultsInSubmitFailedWith502(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->persistFakePanel($em);

        /** @var FakePanel $fakePanel */
        $fakePanel = self::getContainer()->get(FakePanel::class);
        $fakePanel->setNextWithdrawalException(new \App\Panel\Exception\PanelException('insufficient balance'));

        $client->request('POST', '/api/v1/withdrawals', server: ['HTTP_X_API_KEY' => self::API_KEY, 'CONTENT_TYPE' => 'application/json'], content: json_encode([
            'uuid' => \Symfony\Component\Uid\Uuid::v4()->toRfc4122(),
            'panel' => 'fake',
            'currency' => 'USDT',
            'network' => 'TRC20',
            'amount' => '42.50',
            'destination_address' => 'Tdestination',
        ]));

        self::assertResponseStatusCodeSame(502);
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('submit_failed', $data['status']);
    }

    public function testMissingApiKeyReturns401(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/v1/withdrawals', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');

        self::assertResponseStatusCodeSame(401);
    }
}
