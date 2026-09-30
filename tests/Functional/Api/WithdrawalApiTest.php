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
        $panel = $em->getRepository(Panel::class)->findOneBy(['code' => $code]);
        if (null === $panel) {
            $panel = new Panel($code, 'Fake panel');
            $panel->setActive(true);
            $em->persist($panel);
            $em->flush();
        }
        $this->route('USDT', null, $code);

        return $panel;
    }

    public function testCreateWithdrawalRequestReturns201AwaitingPayoutWithoutTouchingThePanel(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->persistFakePanel($em);

        $client->request('POST', '/api/v1/withdrawals', server: [
            'HTTP_X_API_KEY' => self::API_KEY,
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'uuid' => \Symfony\Component\Uid\Uuid::v4()->toRfc4122(),
            'currency' => 'USDT',
            'network' => 'TRC20',
            'amount' => '42.50',
            'destination_address' => 'Tdestination',
        ]));

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('awaiting_payout', $data['status']);
        self::assertSame([], $data['payments']);
        self::assertSame(0, self::getContainer()->get(FakePanel::class)->withdrawalCalls);
    }

    public function testCreateWithdrawalRequestIsIdempotentByUuid(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->persistFakePanel($em);
        $uuid = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();

        $payload = json_encode([
            'uuid' => $uuid,
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

    public function testMissingApiKeyReturns401(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/v1/withdrawals', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');

        self::assertResponseStatusCodeSame(401);
    }
}
