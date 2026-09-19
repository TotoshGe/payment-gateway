<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Panel\BinanceTest\BinanceTestPanel;
use EasyCorp\Bundle\EasyAdminBundle\Attribute\AdminDashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\Dashboard;
use EasyCorp\Bundle\EasyAdminBundle\Config\MenuItem;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractDashboardController;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\HttpFoundation\Response;

#[AdminDashboard(routePath: '/admin', routeName: 'admin')]
final class DashboardController extends AbstractDashboardController
{
    public function __construct(
        private readonly AdminUrlGenerator $adminUrlGenerator,
        private readonly BinanceTestPanel $binanceTestPanel,
    ) {
    }

    public function index(): Response
    {
        return $this->redirect($this->adminUrlGenerator->setController(DepositRequestCrudController::class)->generateUrl());
    }

    public function configureDashboard(): Dashboard
    {
        return Dashboard::new()
            ->setTitle($this->binanceTestPanel->isEnabled() ? 'payment-gateway <small style="color:#c00">[BINANCE TEST PANEL ON - FAKE ADDRESSES]</small>' : 'payment-gateway');
    }

    public function configureMenuItems(): iterable
    {
        yield MenuItem::linkTo(DepositRequestCrudController::class, 'Deposit requests', 'fa fa-arrow-down');
        yield MenuItem::linkTo(WithdrawalRequestCrudController::class, 'Withdrawal requests', 'fa fa-arrow-up');
        yield MenuItem::linkTo(CallbackDeliveryCrudController::class, 'Callback deliveries', 'fa fa-bell');
        yield MenuItem::linkTo(PanelWalletAddressCrudController::class, 'Wallet address pool', 'fa fa-wallet');
        yield MenuItem::linkTo(PanelCrudController::class, 'Panels', 'fa fa-plug');
    }
}
