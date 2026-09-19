<?php

declare(strict_types=1);

namespace App\Tests\Functional\TestPanel;

use App\Controller\Admin\DepositRequestCrudController;
use App\Controller\Admin\WithdrawalRequestCrudController;
use App\Entity\AdminUser;
use App\Entity\Panel;
use App\Enum\PaymentRequestStatus;
use App\Repository\DepositRequestRepository;
use App\Service\DepositRequestService;
use App\Service\WithdrawalRequestService;
use App\Tests\Functional\FunctionalTestCase;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Renders the real admin pages through a logged-in session and clicks the
 * Test Panel buttons, rather than trusting the action configuration by eye.
 */
final class TestPanelAdminTest extends FunctionalTestCase
{
    private function loginAsAdmin(KernelBrowser $client, EntityManagerInterface $em): void
    {
        $admin = $em->getRepository(AdminUser::class)->findOneBy(['email' => 'test-panel-admin@example.com']);
        if (null === $admin) {
            $admin = new AdminUser('test-panel-admin@example.com');
            $admin->setPassword('unused');
            $em->persist($admin);
            $em->flush();
        }

        $client->loginUser($admin, 'admin');
    }

    private function panel(EntityManagerInterface $em, string $code, string $label): void
    {
        if (null === $em->getRepository(Panel::class)->findOneBy(['code' => $code])) {
            $em->persist(new Panel($code, $label));
        }
        $em->flush();
    }

    private function indexUrl(string $controller): string
    {
        return self::getContainer()->get(AdminUrlGenerator::class)->setController($controller)->setAction('index')->generateUrl();
    }

    public function testButtonsAppearOnlyForTestPanelRequestsAndConfirmWorks(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->loginAsAdmin($client, $em);
        $this->panel($em, 'binance_test', 'Test Panel');
        $this->panel($em, 'fake', 'Fake panel');

        $service = self::getContainer()->get(DepositRequestService::class);
        $testDeposit = $service->createOrGetExisting('admin-test-'.uniqid(), 'binance_test', 'USDT', 'TRC20', '10')['request'];
        $service->createOrGetExisting('admin-real-'.uniqid(), 'fake', 'USDT', 'TRC20', '10');

        $crawler = $client->request('GET', $this->indexUrl(DepositRequestCrudController::class));
        self::assertResponseIsSuccessful();

        self::assertStringContainsString('Test Panel', $crawler->text());
        self::assertStringNotContainsString('[TEST]', $crawler->text());
        $confirmForms = $crawler->filter('form[action*="test-confirm"]');
        self::assertCount(1, $confirmForms, 'only the test panel row gets the confirm button, not the other panel');
        self::assertStringContainsString('Test Panel: confirm payment', $crawler->filter('a[data-ea-action-form-id="'.$confirmForms->attr('id').'"]')->text());
        self::assertCount(1, $crawler->filter('form[action*="test-received"]'));
        self::assertCount(0, $crawler->filter('form[action*="test-fail"]'), 'deposits cannot fail on Binance, so neither here');

        $client->submit($confirmForms->form());
        $client->followRedirect();
        self::assertSelectorTextContains('body', '[TEST PANEL] Deposit moved to "completed"');

        $em->clear();
        $reloaded = self::getContainer()->get(DepositRequestRepository::class)->find($testDeposit->getId());
        self::assertSame(PaymentRequestStatus::COMPLETED, $reloaded->getStatus());

        $crawler = $client->request('GET', $this->indexUrl(DepositRequestCrudController::class));
        self::assertCount(0, $crawler->filter('form[action*="test-confirm"]'), 'no button once the request is terminal');
    }

    public function testWithdrawalConfirmButton(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->loginAsAdmin($client, $em);
        $this->panel($em, 'binance_test', 'Test Panel');

        $withdrawal = self::getContainer()->get(WithdrawalRequestService::class)
            ->createOrGetExisting('admin-wd-'.uniqid(), 'binance_test', 'USDT', 'TRC20', '5', 'TSomeDestination', null)['request'];

        $crawler = $client->request('GET', $this->indexUrl(WithdrawalRequestCrudController::class));
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[action*="test-confirm"]');
        self::assertCount(1, $form);

        $client->submit($form->form());
        $client->followRedirect();
        self::assertSelectorTextContains('body', '[TEST PANEL] Withdrawal moved to "completed"');

        $em->clear();
        $reloaded = $em->getRepository($withdrawal::class)->find($withdrawal->getId());
        self::assertSame(PaymentRequestStatus::COMPLETED, $reloaded->getStatus());
    }

    public function testDashboardHasNoTestBanner(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client, self::getContainer()->get(EntityManagerInterface::class));

        $crawler = $client->request('GET', $this->indexUrl(DepositRequestCrudController::class));

        self::assertStringNotContainsString('TEST PANEL ON', $crawler->html());
        self::assertStringNotContainsString('FAKE ADDRESSES', $crawler->html());
    }
}
