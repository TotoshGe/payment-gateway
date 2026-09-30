<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Controller\Admin\DepositRequestCrudController;
use App\Controller\Admin\PaymentCrudController;
use App\Controller\Admin\WithdrawalRequestCrudController;
use App\Entity\AdminUser;
use App\Entity\Panel;
use App\Entity\WithdrawalRequest;
use App\Enum\PaymentRequestStatus;
use App\Enum\PaymentStatus;
use App\Service\DepositRequestService;
use App\Service\WithdrawalRequestService;
use App\Tests\Fixture\FakePanel;
use App\Tests\Functional\FunctionalTestCase;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\Uid\Uuid;

final class WithdrawalCreatePaymentAdminTest extends FunctionalTestCase
{
    private function login(KernelBrowser $client, EntityManagerInterface $em): void
    {
        $admin = $em->getRepository(AdminUser::class)->findOneBy(['email' => 'create-payment-admin@example.com']);
        if (null === $admin) {
            $admin = new AdminUser('create-payment-admin@example.com');
            $admin->setPassword('unused');
            $em->persist($admin);
        }
        foreach (['fake' => 'Fake panel', 'binance_test' => 'Test Panel'] as $code => $label) {
            if (null === $em->getRepository(Panel::class)->findOneBy(['code' => $code])) {
                $em->persist(new Panel($code, $label));
            }
        }
        $em->flush();
        $client->loginUser($admin, 'admin');
    }

    private function url(string $controller, string $action, ?Uuid $id = null): string
    {
        $generator = self::getContainer()->get(AdminUrlGenerator::class)->setController($controller)->setAction($action);
        if (null !== $id) {
            $generator->setEntityId((string) $id);
        }

        return $generator->generateUrl();
    }

    private function newWithdrawal(string $panel = 'fake'): WithdrawalRequest
    {
        return self::getContainer()->get(WithdrawalRequestService::class)
            ->createOrGetExisting(Uuid::v4()->toRfc4122(), $panel, 'USDT', 'TRC20', '7', 'TDest', null)['request'];
    }

    public function testCreatePaymentButtonPostsWithCsrfAndCreatesTheRealPayment(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->login($client, self::getContainer()->get(EntityManagerInterface::class));
        $withdrawal = $this->newWithdrawal();
        $id = $withdrawal->getId();

        $crawler = $client->request('GET', $this->url(WithdrawalRequestCrudController::class, 'detail', $id));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Ожидание выплаты', $crawler->text());
        self::assertStringContainsString('Создать платёж', $crawler->text());
        $form = $crawler->filter('form[action*="create-payment"]');
        self::assertCount(1, $form, 'rendered as a POST form, not a GET link');
        self::assertSame('post', strtolower((string) $form->attr('method')));
        self::assertStringContainsString('_token=', (string) $form->attr('action'));

        $client->submit($form->form());
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Платёж создан, вывод отправлен на панель.');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $reloaded = $em->find(WithdrawalRequest::class, $id);
        self::assertSame(PaymentRequestStatus::SUBMITTED, $reloaded->getStatus());
        self::assertCount(1, $reloaded->getPayments());
        self::assertSame(1, self::getContainer()->get(FakePanel::class)->withdrawalCalls);

        $crawler = $client->request('GET', $this->url(WithdrawalRequestCrudController::class, 'detail', $id));
        self::assertCount(0, $crawler->filter('form[action*="create-payment"]'), 'no second button once a payment exists');
    }

    public function testRequestWithoutValidCsrfTokenOrWithGetCreatesNothing(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->login($client, self::getContainer()->get(EntityManagerInterface::class));
        $withdrawal = $this->newWithdrawal();
        $id = $withdrawal->getId();

        $crawler = $client->request('GET', $this->url(WithdrawalRequestCrudController::class, 'detail', $id));
        $action = (string) $crawler->filter('form[action*="create-payment"]')->attr('action');

        $client->request('GET', $action);
        self::assertResponseStatusCodeSame(405);

        $client->request('POST', preg_replace('/_token=[^&]+/', '_token=bogus', $action));
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Недействительный CSRF-токен');

        $client->request('POST', $this->url(WithdrawalRequestCrudController::class, 'createPayment', $id));
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'Недействительный CSRF-токен');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        self::assertSame(PaymentRequestStatus::AWAITING_PAYOUT, $em->find(WithdrawalRequest::class, $id)->getStatus());
        self::assertSame(0, self::getContainer()->get(FakePanel::class)->withdrawalCalls);
    }

    public function testPanelErrorShowsWarningAndPausesSilently(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->login($client, self::getContainer()->get(EntityManagerInterface::class));
        $withdrawal = $this->newWithdrawal();
        self::getContainer()->get(FakePanel::class)->setNextWithdrawalException(new \App\Panel\Exception\PanelException('insufficient balance'));

        $crawler = $client->request('GET', $this->url(WithdrawalRequestCrudController::class, 'detail', $withdrawal->getId()));
        $client->submit($crawler->filter('form[action*="create-payment"]')->form());
        $client->followRedirect();
        self::assertSelectorTextContains('body', 'insufficient balance');

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $reloaded = $em->find(WithdrawalRequest::class, $withdrawal->getId());
        self::assertSame(PaymentRequestStatus::PAUSED, $reloaded->getStatus());
        self::assertSame(PaymentStatus::FAILED, $reloaded->getLeadPayment()->getStatus());
    }

    public function testTestPanelIsSimulatedNothingIsSent(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->login($client, self::getContainer()->get(EntityManagerInterface::class));
        $withdrawal = $this->newWithdrawal('binance_test');

        $crawler = $client->request('GET', $this->url(WithdrawalRequestCrudController::class, 'detail', $withdrawal->getId()));
        $client->submit($crawler->filter('form[action*="create-payment"]')->form());
        $client->followRedirect();

        $em = self::getContainer()->get(EntityManagerInterface::class);
        $em->clear();
        $reloaded = $em->find(WithdrawalRequest::class, $withdrawal->getId());
        self::assertSame(PaymentRequestStatus::SUBMITTED, $reloaded->getStatus());
        self::assertStringStartsWith('TEST-WD-', (string) $reloaded->getPanelWithdrawalReference());
        self::assertSame(0, self::getContainer()->get(FakePanel::class)->withdrawalCalls, 'the real-panel double is untouched');
    }

    public function testRequestAndPaymentListsLinkToEachOtherWithIds(): void
    {
        $client = static::createClient();
        $client->disableReboot();
        $this->login($client, self::getContainer()->get(EntityManagerInterface::class));
        $withdrawal = $this->newWithdrawal();
        $payment = self::getContainer()->get(WithdrawalRequestService::class)->createPayment($withdrawal);
        $deposit = self::getContainer()->get(DepositRequestService::class)->createOrGetExisting(Uuid::v4()->toRfc4122(), 'fake', 'USDT', 'TRC20', '3')['request'];
        self::getContainer()->get(DepositRequestService::class)->applyPaymentUpdate($deposit, PaymentStatus::CONFIRMING, '3', 1, 'tx-link');

        $crawler = $client->request('GET', $this->url(WithdrawalRequestCrudController::class, 'index'));
        self::assertResponseIsSuccessful();
        $link = $crawler->filter('a[href*="/admin/payment/"]');
        self::assertGreaterThanOrEqual(1, $link->count(), 'withdrawal list links to its payments');
        self::assertStringContainsString((string) $payment->getId(), $link->first()->attr('href'));

        $crawler = $client->request('GET', $this->url(DepositRequestCrudController::class, 'index'));
        self::assertGreaterThanOrEqual(1, $crawler->filter('a[href*="/admin/payment/"]')->count(), 'deposit list links to its payments');

        $crawler = $client->request('GET', $this->url(PaymentCrudController::class, 'index'));
        self::assertStringContainsString($withdrawal->getUuid(), $crawler->text());
        self::assertStringContainsString($deposit->getUuid(), $crawler->text());
        $withdrawalLink = $crawler->filter('a[href*="/admin/withdrawal-request/"]');
        self::assertStringContainsString((string) $withdrawal->getId(), $withdrawalLink->first()->attr('href'));
        self::assertGreaterThanOrEqual(1, $crawler->filter('a[href*="/admin/deposit-request/"]')->count());
    }
}
