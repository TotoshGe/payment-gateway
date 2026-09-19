<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\Panel;
use App\Panel\TestPanel\TestPanel;
use App\Repository\PanelRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Idempotent: creates the active "Test Panel" row (code binance_test), or
 * brings an existing row (e.g. the old inactive "Binance Test") to the same
 * state. Currencies/networks are copied from the "binance" panel when it
 * exists so both panels serve the same routes.
 */
#[AsCommand(
    name: 'app:binance-test:install|app:test-panel:install',
    description: '[TEST PANEL] Creates/updates the active "Test Panel" row (code binance_test), currencies copied from the "binance" panel.',
)]
class TestPanelInstallCommand extends Command
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
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $real = $this->panelRepository->findOneByCode('binance');
        $copied = null !== $real && [] !== $real->getSupportedCurrencies();
        $supported = $copied ? $real->getSupportedCurrencies() : self::FALLBACK_SUPPORTED_CURRENCIES;

        $panel = $this->panelRepository->findOneByCode(TestPanel::CODE);
        $created = null === $panel;
        if ($created) {
            $panel = new Panel(TestPanel::CODE, TestPanel::LABEL);
            $panel->setSupportedCurrencies($supported);
            $this->entityManager->persist($panel);
        } elseif ([] === $panel->getSupportedCurrencies()) {
            $panel->setSupportedCurrencies($supported);
        }

        $panel->setLabel(TestPanel::LABEL);
        $panel->setActive(true);
        $panel->touch();
        $this->entityManager->flush();

        $io->success(sprintf(
            '%s panel "%s" (%s), active, %d supported currency pair(s)%s.',
            $created ? 'Created' : 'Updated',
            TestPanel::LABEL,
            TestPanel::CODE,
            \count($panel->getSupportedCurrencies()),
            $created ? sprintf(' copied from %s', $copied ? 'the "binance" panel' : 'the built-in fallback list') : '',
        ));

        return Command::SUCCESS;
    }
}
