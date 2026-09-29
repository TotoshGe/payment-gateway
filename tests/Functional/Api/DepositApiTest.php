<?php

declare(strict_types=1);

namespace App\Tests\Functional\Api;

use App\Entity\Panel;
use App\Repository\DepositRequestRepository;
use App\Tests\Fixture\FakePanel;
use App\Tests\Functional\FunctionalTestCase;
use Doctrine\ORM\EntityManagerInterface;

final class DepositApiTest extends FunctionalTestCase
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

    public function testCreateDepositRequestReturns201WithAddressFromPool(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->persistFakePanel($em);

        $client->request('POST', '/api/v1/deposits', server: [
            'HTTP_X_API_KEY' => self::API_KEY,
            'CONTENT_TYPE' => 'application/json',
        ], content: json_encode([
            'uuid' => \Symfony\Component\Uid\Uuid::v4()->toRfc4122(),
            'currency' => 'USDT',
            'network' => 'TRC20',
            'expected_amount' => '100.00',
        ]));

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('awaiting_payment', $data['status']);
        self::assertSame('fake-address-0', $data['address']);
    }

    public function testCreateDepositRequestIsIdempotentByUuid(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->persistFakePanel($em);
        $uuid = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();

        $payload = json_encode([
            'uuid' => $uuid,
            'currency' => 'USDT',
            'network' => 'TRC20',
            'expected_amount' => '100.00',
        ]);

        $client->request('POST', '/api/v1/deposits', server: ['HTTP_X_API_KEY' => self::API_KEY, 'CONTENT_TYPE' => 'application/json'], content: $payload);
        self::assertResponseStatusCodeSame(201);
        $first = json_decode($client->getResponse()->getContent(), true);

        $client->request('POST', '/api/v1/deposits', server: ['HTTP_X_API_KEY' => self::API_KEY, 'CONTENT_TYPE' => 'application/json'], content: $payload);
        self::assertResponseStatusCodeSame(200);
        $second = json_decode($client->getResponse()->getContent(), true);

        self::assertSame($first['id'], $second['id']);

        /** @var DepositRequestRepository $repository */
        $repository = self::getContainer()->get(DepositRequestRepository::class);
        self::assertCount(1, $repository->findBy(['uuid' => $uuid]), 'must not create a second row for a retried request');
    }

    public function testResponseCarriesOkeanUuidAndNoPaymentsYetAndNetworkIsOptional(): void
    {
        $client = static::createClient();
        $this->persistFakePanel(self::getContainer()->get(EntityManagerInterface::class));
        $uuid = \Symfony\Component\Uid\Uuid::v4()->toRfc4122();

        $client->request('POST', '/api/v1/deposits', server: ['HTTP_X_API_KEY' => self::API_KEY, 'CONTENT_TYPE' => 'application/json'], content: json_encode([
            'uuid' => strtoupper($uuid),
            'currency' => 'USDT',
            'expected_amount' => '5',
        ]));

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame($uuid, $data['uuid'], 'the uuid is normalised to lower case');
        self::assertArrayNotHasKey('external_reference', $data);
        self::assertNotSame($uuid, $data['id'], 'id is the gateway own key, uuid is the Okean one');
        self::assertNull($data['network']);
        self::assertSame([], $data['payments']);

        $client->request('POST', '/api/v1/deposits', server: ['HTTP_X_API_KEY' => self::API_KEY, 'CONTENT_TYPE' => 'application/json'], content: json_encode([
            'uuid' => $uuid, 'currency' => 'USDT', 'expected_amount' => '5',
        ]));
        self::assertResponseStatusCodeSame(200);
        self::assertSame($data['id'], json_decode($client->getResponse()->getContent(), true)['id'], 'same uuid in another letter case is the same request');
    }

    public function testLegacyExternalReferenceAndNonUuidAreRejected(): void
    {
        $client = static::createClient();
        $this->persistFakePanel(self::getContainer()->get(EntityManagerInterface::class));

        foreach ([['external_reference' => 'okean-payin-'.\Symfony\Component\Uid\Uuid::v4()->toRfc4122()], ['uuid' => 'okean-payin-'.\Symfony\Component\Uid\Uuid::v4()->toRfc4122()]] as $identity) {
            $client->request('POST', '/api/v1/deposits', server: ['HTTP_X_API_KEY' => self::API_KEY, 'CONTENT_TYPE' => 'application/json'], content: json_encode($identity + [ 'currency' => 'USDT', 'expected_amount' => '5',
            ]));
            self::assertResponseStatusCodeSame(422);
        }
    }

    public function testMissingApiKeyReturns401(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/v1/deposits', server: ['CONTENT_TYPE' => 'application/json'], content: '{}');

        self::assertResponseStatusCodeSame(401);
    }

    public function testInvalidPayloadReturns422WithValidationDetails(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/v1/deposits', server: ['HTTP_X_API_KEY' => self::API_KEY, 'CONTENT_TYPE' => 'application/json'], content: json_encode([
            'uuid' => 'not-a-uuid',
            'currency' => '',
            'expected_amount' => 'not-a-number',
        ]));

        self::assertResponseStatusCodeSame(422);
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertNotEmpty($data['details']);
    }

    public function testPoolExhaustionResultsInSubmitFailedWith503(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->persistFakePanel($em);

        /** @var FakePanel $fakePanel */
        $fakePanel = self::getContainer()->get(FakePanel::class);
        $fakePanel->setMaxSlots(0);

        $client->request('POST', '/api/v1/deposits', server: ['HTTP_X_API_KEY' => self::API_KEY, 'CONTENT_TYPE' => 'application/json'], content: json_encode([
            'uuid' => \Symfony\Component\Uid\Uuid::v4()->toRfc4122(),
            'currency' => 'USDT',
            'network' => 'TRC20',
            'expected_amount' => '100.00',
        ]));

        self::assertResponseStatusCodeSame(503);
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('submit_failed', $data['status']);

        $fakePanel->setMaxSlots(1);
    }
}
