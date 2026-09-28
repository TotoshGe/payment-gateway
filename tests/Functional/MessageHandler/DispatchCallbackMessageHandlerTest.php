<?php

declare(strict_types=1);

namespace App\Tests\Functional\MessageHandler;

use App\Entity\CallbackDelivery;
use App\Entity\Panel;
use App\Enum\CallbackDeliveryStatus;
use App\Enum\PaymentRequestStatus;
use App\Message\DispatchCallbackMessage;
use App\MessageHandler\DispatchCallbackMessageHandler;
use App\Repository\CallbackDeliveryRepository;
use App\Repository\DepositRequestRepository;
use App\Service\DepositRequestService;
use App\Tests\Fixture\FakePanel;
use App\Tests\Fixture\ScriptedHttpClient;
use App\Tests\Functional\FunctionalTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\Exception\TransportException;

/**
 * Regression test for a bug where DepositRequest/WithdrawalRequest's own
 * `callbackStatus` field was set to PENDING once (in CallbackDispatcher::
 * dispatchFor()) and never updated again, so it stayed "pending" forever in
 * the admin UI no matter what actually happened to the CallbackDelivery
 * (delivered, failed, exhausted) -- see App\Service\CallbackDeliveryTargetResolver.
 */
final class DispatchCallbackMessageHandlerTest extends FunctionalTestCase
{
    protected function tearDown(): void
    {
        ScriptedHttpClient::$script = null;
        parent::tearDown();
    }

    public function testSuccessfulDeliveryMarksRequestCallbackStatusSent(): void
    {
        $delivery = $this->queueCallbackForCompletedDeposit('handler-success-');

        ScriptedHttpClient::$script = static fn (): array => ['ok' => true];

        $container = self::getContainer();
        $handler = $container->get(DispatchCallbackMessageHandler::class);
        $handler(new DispatchCallbackMessage($delivery->getId()));

        $em = $container->get(EntityManagerInterface::class);
        $em->refresh($delivery);
        self::assertSame(CallbackDeliveryStatus::SENT, $delivery->getStatus());

        $depositRequest = $container->get(DepositRequestRepository::class)->find($delivery->getRequestId());
        self::assertNotNull($depositRequest);
        self::assertSame(
            CallbackDeliveryStatus::SENT,
            $depositRequest->getCallbackStatus(),
            'the request must mirror the CallbackDelivery outcome, not stay stuck on the PENDING set at dispatch time',
        );
    }

    public function testFailedDeliveryMarksRequestCallbackStatusFailed(): void
    {
        $delivery = $this->queueCallbackForCompletedDeposit('handler-failure-');

        ScriptedHttpClient::$script = static function (): never {
            throw new TransportException('connection refused');
        };

        $container = self::getContainer();
        $handler = $container->get(DispatchCallbackMessageHandler::class);

        try {
            $handler(new DispatchCallbackMessage($delivery->getId()));
            self::fail('expected the handler to rethrow so Messenger retries');
        } catch (\RuntimeException) {
            // expected: rethrown for Messenger's retry_strategy, see the handler's docblock
        }

        $em = $container->get(EntityManagerInterface::class);
        $em->refresh($delivery);
        self::assertSame(CallbackDeliveryStatus::FAILED, $delivery->getStatus());

        $depositRequest = $container->get(DepositRequestRepository::class)->find($delivery->getRequestId());
        self::assertNotNull($depositRequest);
        self::assertSame(CallbackDeliveryStatus::FAILED, $depositRequest->getCallbackStatus());
    }

    private function queueCallbackForCompletedDeposit(string $referencePrefix): CallbackDelivery
    {
        self::bootKernel();
        $container = self::getContainer();

        $em = $container->get(EntityManagerInterface::class);
        $panel = $em->getRepository(Panel::class)->findOneBy(['code' => 'fake']);
        if (null === $panel) {
            $panel = new Panel('fake', 'Fake panel');
            $em->persist($panel);
            $em->flush();
        }

        $depositRequestService = $container->get(DepositRequestService::class);
        $result = $depositRequestService->createOrGetExisting($referencePrefix.uniqid(), 'fake', 'USDT', 'TRC20', '42.00');
        $depositRequest = $result['request'];
        self::assertSame(PaymentRequestStatus::AWAITING_PAYMENT, $depositRequest->getStatus());

        /** @var FakePanel $fakePanel */
        $fakePanel = $container->get(FakePanel::class);
        $fakePanel->queueDepositCompleted($depositRequest, '42.00');

        $application = new Application(self::$kernel);
        $command = $application->find('app:payment-gateway:poll-deposits');
        $tester = new CommandTester($command);
        $tester->execute(['panel-code' => 'fake', '--once' => true]);
        self::assertSame(0, $tester->getStatusCode());

        $em->refresh($depositRequest);
        self::assertSame(PaymentRequestStatus::COMPLETED, $depositRequest->getStatus());
        self::assertSame(CallbackDeliveryStatus::PENDING, $depositRequest->getCallbackStatus());

        /** @var CallbackDeliveryRepository $callbackRepository */
        $callbackRepository = $container->get(CallbackDeliveryRepository::class);
        $deliveries = $callbackRepository->findBy(['requestId' => $depositRequest->getId()]);
        self::assertCount(1, $deliveries);

        return $deliveries[0];
    }
}
