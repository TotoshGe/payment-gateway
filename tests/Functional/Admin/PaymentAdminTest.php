<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Controller\Admin\DepositRequestCrudController;
use App\Controller\Admin\PaymentCrudController;
use App\Controller\Admin\WithdrawalRequestCrudController;
use App\Entity\AdminUser;
use App\Entity\DepositRequest;
use App\Entity\Panel;
use App\Entity\WithdrawalRequest;
use App\Enum\PaymentStatus;
use App\Service\DepositRequestService;
use App\Service\WithdrawalRequestService;
use App\Tests\Functional\FunctionalTestCase;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\Uid\Uuid;

final class PaymentAdminTest extends FunctionalTestCase
{
    private function url(string $controller, string $action, ?string $entityId = null): string
    {
        $generator = self::getContainer()->get(AdminUrlGenerator::class)->setController($controller)->setAction($action);
        if (null !== $entityId) {
            $generator->setEntityId($entityId);
        }

        return $generator->generateUrl();
    }

    /**
     * A client request reboots the kernel and resets the entity manager, so
     * services and entities obtained before it are detached: re-fetch both.
     */
    private function fresh(string $entityClass, Uuid $id): object
    {
        return self::getContainer()->get(EntityManagerInterface::class)->find($entityClass, $id);
    }

    private function login($client, EntityManagerInterface $em): void
    {
        $admin = $em->getRepository(AdminUser::class)->findOneBy(['email' => 'payment-admin@example.com']);
        if (null === $admin) {
            $admin = new AdminUser('payment-admin@example.com');
            $admin->setPassword('unused');
            $em->persist($admin);
            $em->flush();
        }
        $client->loginUser($admin, 'admin');
        if (null === $em->getRepository(Panel::class)->findOneBy(['code' => 'fake'])) {
            $em->persist(new Panel('fake', 'Fake panel'));
            $em->flush();
        }
    }

    public function testDepositAndPaymentPagesLinkToEachOther(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->login($client, $em);

        $service = self::getContainer()->get(DepositRequestService::class);
        $deposit = $service->createOrGetExisting(Uuid::v4()->toRfc4122(), 'fake', 'USDT', null, '25')['request'];

        $crawler = $client->request('GET', $this->url(DepositRequestCrudController::class, 'detail', (string) $deposit->getId()));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($deposit->getUuid(), $crawler->text());
        self::assertStringContainsString('Платежи', $crawler->text());
        self::assertCount(0, $crawler->filter('a[href*="/admin/payment/"]'), 'no payment yet, so nothing to link to');

        $deposit = $this->fresh(DepositRequest::class, $deposit->getId());
        self::getContainer()->get(DepositRequestService::class)->applyPaymentUpdate($deposit, PaymentStatus::CONFIRMING, '25', 3, 'tx-admin');
        $payment = $deposit->getLeadPayment();

        $crawler = $client->request('GET', $this->url(DepositRequestCrudController::class, 'detail', (string) $deposit->getId()));
        $paymentLink = $crawler->filter('a[href*="/admin/payment/"]');
        self::assertGreaterThanOrEqual(1, $paymentLink->count(), 'request detail links to its payment');
        self::assertStringContainsString((string) $payment->getId(), $paymentLink->first()->attr('href'));

        $crawler = $client->request('GET', $this->url(PaymentCrudController::class, 'index'));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($deposit->getUuid(), $crawler->text(), 'the list shows the linked request');
        self::assertStringContainsString('Подтверждается', $crawler->text());
        self::assertGreaterThanOrEqual(1, $crawler->filter('a[href*="/admin/deposit-request/"]')->count());

        $crawler = $client->request('GET', $this->url(PaymentCrudController::class, 'detail', (string) $payment->getId()));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('tx-admin', $crawler->text());
        $requestLink = $crawler->filter('a[href*="/admin/deposit-request/"]');
        self::assertGreaterThanOrEqual(1, $requestLink->count(), 'payment detail links back to its deposit request');
        self::assertStringContainsString((string) $deposit->getId(), $requestLink->first()->attr('href'));
    }

    public function testWithdrawalPaymentPageAndPausedActions(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->login($client, $em);

        $service = self::getContainer()->get(WithdrawalRequestService::class);
        $withdrawal = $service->createOrGetExisting(Uuid::v4()->toRfc4122(), 'fake', 'USDT', 'TRC20', '7', 'TDest', null)['request'];
        $service->createPayment($withdrawal);
        $payment = $withdrawal->getLeadPayment();

        $crawler = $client->request('GET', $this->url(PaymentCrudController::class, 'detail', (string) $payment->getId()));
        self::assertResponseIsSuccessful();
        self::assertGreaterThanOrEqual(1, $crawler->filter('a[href*="/admin/withdrawal-request/"]')->count());

        $crawler = $client->request('GET', $this->url(WithdrawalRequestCrudController::class, 'detail', (string) $withdrawal->getId()));
        self::assertGreaterThanOrEqual(1, $crawler->filter('a[href*="/admin/payment/"]')->count());
        self::assertCount(0, $crawler->filter('a:contains("Закрыть как ошибку")'));

        $withdrawal = $this->fresh(WithdrawalRequest::class, $withdrawal->getId());
        self::getContainer()->get(WithdrawalRequestService::class)->applyPaymentUpdate($withdrawal, PaymentStatus::CANCELLED, null, 'cancelled');

        $crawler = $client->request('GET', $this->url(WithdrawalRequestCrudController::class, 'index'));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Приостановлена', $crawler->text());

        $crawler = $client->request('GET', $this->url(WithdrawalRequestCrudController::class, 'detail', (string) $withdrawal->getId()));
        self::assertCount(1, $crawler->filter('a:contains("Закрыть как ошибку")'));
    }

    public function testMenuAndPaymentListAreReachableAndReadOnly(): void
    {
        $client = static::createClient();
        $this->login($client, self::getContainer()->get(EntityManagerInterface::class));

        $crawler = $client->request('GET', $this->url(PaymentCrudController::class, 'index'));

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Платежи', $crawler->filter('.sidebar, #main-menu, nav')->text());
        self::assertCount(0, $crawler->filter('a.action-new'));

        $client->request('GET', $this->url(PaymentCrudController::class, 'new'));
        self::assertResponseStatusCodeSame(403);
    }
}
