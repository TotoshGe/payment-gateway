<?php

declare(strict_types=1);

namespace App\Tests\Functional\Routing;

use App\Entity\AdminUser;
use App\Entity\Panel;
use App\Entity\PanelRoute;
use App\Repository\DepositRequestRepository;
use App\Repository\WithdrawalRequestRepository;
use App\Service\Exception\PanelNotFoundException;
use App\Service\PanelRouter;
use App\Tests\Functional\FunctionalTestCase;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use Symfony\Component\Uid\Uuid;

final class PanelRoutingTest extends FunctionalTestCase
{
    private function panel(string $code, bool $active = true): Panel
    {
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $panel = $em->getRepository(Panel::class)->findOneBy(['code' => $code]) ?? new Panel($code, $code);
        $panel->setActive($active);
        $em->persist($panel);
        $em->flush();

        return $panel;
    }

    private function router(): PanelRouter
    {
        return self::getContainer()->get(PanelRouter::class);
    }

    public function testExactCurrencyNetworkRouteWinsOverCurrencyRouteAndDefault(): void
    {
        self::bootKernel();
        $this->panel('binance_test');
        $this->panel('route-exact');
        $this->panel('route-currency');
        $this->route('USDT', 'TRC20', 'route-exact');
        $this->route('USDT', null, 'route-currency');

        self::assertSame('route-exact', $this->router()->resolve('USDT', 'TRC20')->getCode());
    }

    public function testFallsBackToCurrencyRouteForOtherNetworksAndNoNetwork(): void
    {
        self::bootKernel();
        $this->panel('binance_test');
        $this->panel('route-exact');
        $this->panel('route-currency');
        $this->route('USDT', 'TRC20', 'route-exact');
        $this->route('USDT', null, 'route-currency');

        self::assertSame('route-currency', $this->router()->resolve('USDT', 'ERC20')->getCode());
        self::assertSame('route-currency', $this->router()->resolve('USDT', null)->getCode());
    }

    public function testFallsBackToDefaultPanelWithoutRoute(): void
    {
        self::bootKernel();
        $this->panel('binance_test');
        $this->panel('route-currency');
        $this->route('USDT', null, 'route-currency');

        self::assertSame('binance_test', $this->router()->getDefaultPanelCode());
        self::assertSame('binance_test', $this->router()->resolve('BTC', 'BTC')->getCode());
    }

    public function testDisabledRouteIsIgnored(): void
    {
        self::bootKernel();
        $this->panel('binance_test');
        $this->panel('route-exact');
        $this->route('USDT', 'TRC20', 'route-exact', enabled: false);

        self::assertSame('binance_test', $this->router()->resolve('USDT', 'TRC20')->getCode());
    }

    public function testInactiveRoutedPanelIsAnErrorNotAFallthrough(): void
    {
        self::bootKernel();
        $this->panel('binance_test');
        $this->panel('route-off', active: false);
        $this->route('USDT', null, 'route-off');

        $this->expectException(PanelNotFoundException::class);
        $this->router()->resolve('USDT', 'TRC20');
    }

    public function testInactiveDefaultPanelIsAnError(): void
    {
        self::bootKernel();
        $this->panel('binance_test', active: false);

        try {
            $this->router()->resolve('USDT', null);
            self::fail('expected PanelNotFoundException');
        } catch (PanelNotFoundException $e) {
            self::assertStringContainsString('binance_test', $e->getMessage());
        } finally {
            $this->panel('binance_test');
        }
    }

    public function testApiReturns422AndCreatesNothingWhenNoUsablePanel(): void
    {
        $client = static::createClient();
        $this->panel('binance_test');
        $this->panel('route-off', active: false);
        $this->route('USDT', null, 'route-off');

        foreach (['/api/v1/deposits' => ['expected_amount' => '5'], '/api/v1/withdrawals' => ['amount' => '5', 'destination_address' => 'TDest']] as $path => $extra) {
            $client->request('POST', $path, server: ['HTTP_X_API_KEY' => 'test_api_key', 'CONTENT_TYPE' => 'application/json'], content: json_encode([
                'uuid' => Uuid::v4()->toRfc4122(), 'currency' => 'USDT', 'network' => 'TRC20',
            ] + $extra));
            self::assertResponseStatusCodeSame(422);
            self::assertStringContainsString('No active panel', json_decode($client->getResponse()->getContent(), true)['error']);
        }

        self::assertSame([], self::getContainer()->get(DepositRequestRepository::class)->findAll());
        self::assertSame([], self::getContainer()->get(WithdrawalRequestRepository::class)->findAll());
    }

    public function testPanelSentByTheCallerIsIgnoredAndRoutingDecides(): void
    {
        $client = static::createClient();
        $this->panel('binance_test');
        $this->panel('fake');
        $this->route('USDT', 'TRC20', 'fake');

        $client->request('POST', '/api/v1/deposits', server: ['HTTP_X_API_KEY' => 'test_api_key', 'CONTENT_TYPE' => 'application/json'], content: json_encode([
            'uuid' => Uuid::v4()->toRfc4122(), 'panel' => 'binance_test', 'currency' => 'USDT', 'network' => 'TRC20', 'expected_amount' => '5',
        ]));
        self::assertResponseStatusCodeSame(201);
        self::assertSame('fake', json_decode($client->getResponse()->getContent(), true)['panel'], 'routing chose the panel, not the request');

        $client->request('POST', '/api/v1/deposits', server: ['HTTP_X_API_KEY' => 'test_api_key', 'CONTENT_TYPE' => 'application/json'], content: json_encode([
            'uuid' => Uuid::v4()->toRfc4122(), 'panel' => 'does-not-exist', 'currency' => 'BTC', 'expected_amount' => '5',
        ]));
        self::assertResponseStatusCodeSame(201, 'an unknown sent panel is not an error either');
    }

    public function testDefaultPanelServesCoinsWithoutRouteEndToEnd(): void
    {
        $client = static::createClient();
        $this->panel('binance_test');

        $client->request('POST', '/api/v1/deposits', server: ['HTTP_X_API_KEY' => 'test_api_key', 'CONTENT_TYPE' => 'application/json'], content: json_encode([
            'uuid' => Uuid::v4()->toRfc4122(), 'currency' => 'BTC', 'expected_amount' => '0.1',
        ]));

        self::assertResponseStatusCodeSame(201);
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame('binance_test', $data['panel']);
        self::assertSame('awaiting_payment', $data['status']);
    }

    public function testAdminRoutingPageShowsDefaultPanelAndCreatesRoutes(): void
    {
        $client = static::createClient();
        $em = self::getContainer()->get(EntityManagerInterface::class);
        $this->panel('binance_test');
        $admin = new AdminUser('routing-admin-'.uniqid().'@example.com');
        $admin->setPassword('unused');
        $em->persist($admin);
        $em->flush();
        $client->loginUser($admin, 'admin');
        $this->route('eth', 'erc20', 'binance_test');

        $generator = fn (string $action) => self::getContainer()->get(AdminUrlGenerator::class)->setController(\App\Controller\Admin\PanelRouteCrudController::class)->setAction($action)->generateUrl();

        $crawler = $client->request('GET', $generator('index'));
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Маршрутизация', $crawler->text());
        self::assertStringContainsString('Панель по умолчанию', $crawler->text());
        self::assertStringContainsString('binance_test', $crawler->text());
        self::assertStringContainsString('ETH', $crawler->text(), 'currency is normalised to upper case');

        $crawler = $client->request('GET', $generator('new'));
        self::assertResponseIsSuccessful();
        $form = $crawler->filter('form[name="PanelRoute"]')->form();
        $panelId = (string) $em->getRepository(Panel::class)->findOneBy(['code' => 'binance_test'])->getId();
        $form['PanelRoute[currency]'] = 'btc';
        $form['PanelRoute[panel]'] = $panelId;
        $client->submit($form);

        $em->clear();
        $created = $em->getRepository(PanelRoute::class)->findOneBy(['currency' => 'BTC']);
        self::assertNotNull($created);
        self::assertNull($created->getNetwork());
        self::assertTrue($created->isEnabled());
    }
}
