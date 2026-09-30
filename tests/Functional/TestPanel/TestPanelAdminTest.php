<?php

declare(strict_types=1);

namespace App\Tests\Functional\TestPanel;

use App\Controller\Admin\DepositRequestCrudController;
use App\Controller\Admin\WithdrawalRequestCrudController;
use App\Entity\AdminUser;
use App\Entity\Panel;
use App\Service\DepositRequestService;
use App\Service\WithdrawalRequestService;
use App\Tests\Functional\FunctionalTestCase;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Renders the real admin pages through a logged-in session to prove the
 * Test Panel simulation buttons are gone (the simulator stays console-only).
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

    public function testNoTestPanelButtonsAreOfferedOnAnyRequest(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->loginAsAdmin($client, $em);
        $this->panel($em, 'binance_test', 'Test Panel');

        $deposits = self::getContainer()->get(DepositRequestService::class);
        $deposits->createOrGetExisting('admin-test-'.uniqid(), 'binance_test', 'USDT', 'TRC20', '10');
        $withdrawal = self::getContainer()->get(WithdrawalRequestService::class)
            ->createOrGetExisting('admin-wd-'.uniqid(), 'binance_test', 'USDT', 'TRC20', '5', 'TSomeDestination', null)['request'];
        self::getContainer()->get(WithdrawalRequestService::class)->createPayment($withdrawal);

        foreach ([DepositRequestCrudController::class, WithdrawalRequestCrudController::class] as $controller) {
            $crawler = $client->request('GET', $this->indexUrl($controller));
            self::assertResponseIsSuccessful();
            self::assertStringContainsString('Test Panel', $crawler->text(), 'the requests are listed');
            self::assertStringNotContainsString('Тестовая панель', $crawler->text());
            self::assertCount(0, $crawler->filter('form[action*="test-"]'));
        }
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
