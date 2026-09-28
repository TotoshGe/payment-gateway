<?php

declare(strict_types=1);

namespace App\Tests\Functional\Admin;

use App\Entity\AdminUser;
use App\Entity\Panel;
use App\Service\DepositRequestService;
use App\Tests\Functional\FunctionalTestCase;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Renders the real admin pages through a logged-in session and asserts on
 * the redesigned shell's own CSS hooks (pg-*), so a future template/asset
 * change that silently breaks a block override is caught here rather than
 * only by eye.
 */
final class AdminThemeTest extends FunctionalTestCase
{
    private function loginAsAdmin(KernelBrowser $client, EntityManagerInterface $em): AdminUser
    {
        $admin = $em->getRepository(AdminUser::class)->findOneBy(['email' => 'admin-theme-test@example.com']);
        if (null === $admin) {
            $admin = new AdminUser('admin-theme-test@example.com');
            $admin->setPassword('unused');
            $em->persist($admin);
            $em->flush();
        }
        $client->loginUser($admin, 'admin');

        return $admin;
    }

    private function panel(EntityManagerInterface $em, string $code, string $label): void
    {
        if (null === $em->getRepository(Panel::class)->findOneBy(['code' => $code])) {
            $em->persist(new Panel($code, $label));
            $em->flush();
        }
    }

    public function testLoginPageRendersThemedShell(): void
    {
        $client = static::createClient();
        $client->request('GET', '/admin/login');

        self::assertResponseIsSuccessful();
        $html = $client->getResponse()->getContent();
        self::assertStringContainsString('admin.css', $html);
        self::assertStringContainsString('pg-login-logo', $html);
    }

    public function testDashboardRendersKpiCards(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->loginAsAdmin($client, $em);
        $this->panel($em, 'admin_theme_test_panel', 'Admin theme test panel');

        self::getContainer()->get(DepositRequestService::class)
            ->createOrGetExisting('admin-theme-'.uniqid(), 'admin_theme_test_panel', 'USDT', 'TRC20', '10');

        $client->request('GET', '/admin');

        self::assertResponseIsSuccessful();
        $html = $client->getResponse()->getContent();
        self::assertStringContainsString('pg-kpi', $html);
        self::assertStringContainsString('Deposit requests', $html);
    }

    public function testDepositListAndDetailRenderCopyableAndBreadcrumbs(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->loginAsAdmin($client, $em);
        $this->panel($em, 'admin_theme_test_panel2', 'Admin theme test panel 2');

        $created = self::getContainer()->get(DepositRequestService::class)
            ->createOrGetExisting('admin-theme2-'.uniqid(), 'admin_theme_test_panel2', 'USDT', 'TRC20', '10')['request'];

        $urlGenerator = self::getContainer()->get(AdminUrlGenerator::class);
        $indexUrl = $urlGenerator->setController(\App\Controller\Admin\DepositRequestCrudController::class)->setAction('index')->generateUrl();
        $client->request('GET', $indexUrl);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('pg-copy', $client->getResponse()->getContent());

        $detailUrl = self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(\App\Controller\Admin\DepositRequestCrudController::class)
            ->setAction('detail')->setEntityId($created->getId())->generateUrl();
        $client->request('GET', $detailUrl);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('pg-crumbs', $client->getResponse()->getContent());
    }

    public function testPanelFormAndStatusBadgeRender(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->loginAsAdmin($client, $em);
        $this->panel($em, 'admin_theme_test_panel3', 'Admin theme test panel 3');

        $newUrl = self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(\App\Controller\Admin\PanelCrudController::class)
            ->setAction('new')->generateUrl();
        $client->request('GET', $newUrl);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('form-control', $client->getResponse()->getContent());

        $indexUrl = self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(\App\Controller\Admin\DepositRequestCrudController::class)
            ->setAction('index')->generateUrl();
        self::getContainer()->get(DepositRequestService::class)
            ->createOrGetExisting('admin-theme3-'.uniqid(), 'admin_theme_test_panel3', 'USDT', 'TRC20', '10');
        $client->request('GET', $indexUrl);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('badge-danger', $client->getResponse()->getContent());
    }

    public function testEmptyListShowsCustomEmptyState(): void
    {
        $client = static::createClient();
        $this->loginAsAdmin($client, self::getContainer()->get(EntityManagerInterface::class));

        $url = self::getContainer()->get(AdminUrlGenerator::class)
            ->setController(\App\Controller\Admin\PanelWalletAddressCrudController::class)
            ->setAction('index')->generateUrl();
        $client->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('pg-empty-state', $client->getResponse()->getContent());
    }
}
