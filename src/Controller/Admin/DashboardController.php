<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Admin\AdminDashboardStats;
use App\Admin\AdminTheme;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Assets;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Config\Option\ColorScheme;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use Symfony\Component\HttpFoundation\Response;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
final class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly AdminDashboardStats $dashboardStats,
    ) {
    }

    public function index(): Response
    {
        return $this->render('admin/dashboard.html.twig', [
            'stats' => $this->dashboardStats->build(),
        ]);
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle('<i class="fa fa-right-left pg-logo-icon"></i> <span class="pg-logo-text">payment-gateway</span>')
            ->setDefaultColorScheme(ColorScheme::AUTO);
    }

    public function configureAssets(): Assets
    {
        return Assets::new()
            ->addCssFile(AdminTheme::cssUrl())
            ->addJsFile(AdminTheme::jsUrl());
    }

    /**
     * Applies to every CRUD page: the layout adds the sidebar search and breadcrumbs on top of stock EasyAdmin.
     */
    public function configureCrud(): Crud
    {
        return Crud::new()->overrideTemplate('layout', 'admin/layout.html.twig');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkToDashboard('Dashboard', 'fa fa-gauge-high');

        yield MenuItem::subMenu('Requests', 'fa fa-right-left')->setSubItems([
            MenuItem::linkTo(DepositRequestCrudController::class, 'Deposit requests', 'fa fa-arrow-down'),
            MenuItem::linkTo(WithdrawalRequestCrudController::class, 'Withdrawal requests', 'fa fa-arrow-up'),
            MenuItem::linkTo(CallbackDeliveryCrudController::class, 'Callback deliveries', 'fa fa-bell'),
        ]);

        yield MenuItem::subMenu('Panels', 'fa fa-plug')->setSubItems([
            MenuItem::linkTo(PanelCrudController::class, 'Panels', 'fa fa-plug'),
            MenuItem::linkTo(PanelWalletAddressCrudController::class, 'Wallet address pool', 'fa fa-wallet'),
        ]);
    }
}
