<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\PanelWalletAddress;
use App\Panel\Exception\PanelWalletProvisioningException;
use App\Panel\PanelRegistry;
use App\Repository\PanelRepository;
use App\Repository\PanelWalletAddressRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Admin/ops tool to seed the deposit-address pool (ARCHITECTURE.md R1
 * resolution): slot 0 always works (the panel's own account address);
 * slot >= 1 requires a sub-account configured in Panel.config.subAccounts,
 * see BinancePanel::resolveSubAccountEmail(). Run this once per desired
 * pool size, e.g. `--count=5` for "5 wallets" per the product owner's
 * example -- the pool size isn't a separate config value, it's simply how
 * many PanelWalletAddress rows exist (see PanelWalletAddressCrudController).
 */
#[AsCommand(
    name: 'app:panel-wallets:provision',
    description: 'Provisions additional deposit-address pool slots for a (panel, currency, network).',
)]
class ProvisionWalletPoolCommand extends Command
{
    public function __construct(
        private readonly PanelRepository $panelRepository,
        private readonly PanelRegistry $panelRegistry,
        private readonly PanelWalletAddressRepository $walletAddressRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('panel-code', InputArgument::REQUIRED)
            ->addArgument('currency', InputArgument::REQUIRED)
            ->addArgument('network', InputArgument::OPTIONAL, 'Omit for currencies without a network (e.g. fiat)')
            ->addOption('count', null, InputOption::VALUE_REQUIRED, 'Total number of pool slots desired', '5');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $panelCode = (string) $input->getArgument('panel-code');
        $currency = strtoupper((string) $input->getArgument('currency'));
        $network = $input->getArgument('network');
        $network = null !== $network ? strtoupper((string) $network) : null;
        $targetCount = max(1, (int) $input->getOption('count'));

        $panel = $this->panelRepository->findOneByCode($panelCode);
        if (null === $panel) {
            $io->error(sprintf('No panel with code "%s".', $panelCode));

            return Command::FAILURE;
        }

        $driver = $this->panelRegistry->getDriverFor($panel);
        $existing = $this->walletAddressRepository->countByPool($panel, $currency, $network);

        $io->note(sprintf('Currently %d slot(s) provisioned, target %d.', $existing, $targetCount));

        $provisioned = 0;
        while ($existing + $provisioned < $targetCount) {
            $slotIndex = $this->walletAddressRepository->nextSlotIndex($panel, $currency, $network);

            try {
                $result = $driver->fetchDepositAddress($panel, $currency, $network, $slotIndex);
            } catch (PanelWalletProvisioningException $exception) {
                $io->warning(sprintf('Stopped at slot %d: %s', $slotIndex, $exception->getMessage()));
                break;
            }

            $address = new PanelWalletAddress($panel, $currency, $network, $slotIndex, $result->address, $result->addressTag);
            $this->entityManager->persist($address);
            $this->entityManager->flush();

            $io->writeln(sprintf('Slot %d: %s', $slotIndex, $result->address));
            $provisioned++;
        }

        $io->success(sprintf('Provisioned %d new slot(s).', $provisioned));

        return Command::SUCCESS;
    }
}
