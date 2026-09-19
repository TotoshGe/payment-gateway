<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Panel;
use App\Panel\BinanceTest\BinanceTestPanel;
use App\Repository\PanelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates the panel ROW only, and always INACTIVE. Turning the panel on is a
 * separate, deliberate step (env flag + "active" in the admin), so running
 * this on production cannot by itself make the fake panel usable.
 */
#[AsCommand(
    name: 'app:binance-test:install',
    description: '[BINANCE TEST] Creates the inactive "Binance Test" panel row (mirrors the real Binance panel\'s supported currencies).',
)]
class BinanceTestInstallCommand extends Command
{
    private const FALLBACK_SUPPORTED_CURRENCIES = [
        ['currency' => 'USDT', 'network' => 'TRC20'],
        ['currency' => 'USDT', 'network' => 'ERC20'],
        ['currency' => 'USDT', 'network' => 'BEP20'],
        ['currency' => 'BTC', 'network' => 'BTC'],
        ['currency' => 'ETH', 'network' => 'ERC20'],
    ];

    public function __construct(
        private readonly PanelRepository $panelRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly BinanceTestPanel $binanceTestPanel,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $existing = $this->panelRepository->findOneByCode(BinanceTestPanel::CODE);
        if (null !== $existing) {
            $io->note(sprintf('Panel "%s" already exists (active=%s); left untouched.', BinanceTestPanel::CODE, $existing->isActive() ? 'yes' : 'no'));
        } else {
            $real = $this->panelRepository->findOneByCode('binance');
            $supported = null !== $real && [] !== $real->getSupportedCurrencies()
                ? $real->getSupportedCurrencies()
                : self::FALLBACK_SUPPORTED_CURRENCIES;

            $panel = new Panel(BinanceTestPanel::CODE, BinanceTestPanel::LABEL);
            $panel->setActive(false);
            $panel->setSupportedCurrencies($supported);
            $this->entityManager->persist($panel);
            $this->entityManager->flush();

            $io->success(sprintf(
                'Created panel "%s" (INACTIVE) with %d supported currency pair(s) copied from %s.',
                BinanceTestPanel::CODE,
                \count($supported),
                null !== $real ? 'the real "binance" panel' : 'the built-in fallback list',
            ));
        }

        $io->writeln(sprintf('Env flag BINANCE_TEST_PANEL_ENABLED is currently: <info>%s</info>', $this->binanceTestPanel->isEnabled() ? 'ON' : 'off'));
        $io->writeln('The panel is only usable when the env flag is ON and "active" is switched on in the admin (Panels).');

        return Command::SUCCESS;
    }
}
