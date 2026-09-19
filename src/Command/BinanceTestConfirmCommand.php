<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\DepositRequest;
use App\Entity\WithdrawalRequest;
use App\Enum\PaymentRequestStatus;
use App\Panel\BinanceTest\BinanceTestSimulationException;
use App\Panel\BinanceTest\BinanceTestSimulator;
use App\Repository\DepositRequestRepository;
use App\Repository\WithdrawalRequestRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Uid\Uuid;

#[AsCommand(
    name: 'app:binance-test:confirm',
    description: '[BINANCE TEST] Moves a deposit/withdrawal on the fake binance_test panel to a chosen status and notifies Okean.',
)]
class BinanceTestConfirmCommand extends Command
{
    private const STATUS_OPTIONS = ['received', 'processing', 'completed', 'failed', 'expired'];

    public function __construct(
        private readonly DepositRequestRepository $depositRequestRepository,
        private readonly WithdrawalRequestRepository $withdrawalRequestRepository,
        private readonly BinanceTestSimulator $simulator,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Request UUID (or its external_reference) of a deposit or withdrawal on the binance_test panel')
            ->addOption('status', 's', InputOption::VALUE_REQUIRED, 'Target status: '.implode('|', self::STATUS_OPTIONS), 'completed')
            ->addOption('amount', 'a', InputOption::VALUE_REQUIRED, 'Deposits only: confirmed amount (default: the expected amount)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->warning('BINANCE TEST PANEL - simulated status change, no real funds involved.');

        $status = PaymentRequestStatus::tryFrom((string) $input->getOption('status'));
        if (null === $status || !\in_array($status->value, self::STATUS_OPTIONS, true)) {
            $io->error('--status must be one of: '.implode(', ', self::STATUS_OPTIONS));

            return Command::INVALID;
        }

        $reference = (string) $input->getArgument('id');
        $deposit = Uuid::isValid($reference) ? $this->depositRequestRepository->find(Uuid::fromString($reference)) : null;
        $withdrawal = Uuid::isValid($reference) ? $this->withdrawalRequestRepository->find(Uuid::fromString($reference)) : null;

        if (null === $deposit && null === $withdrawal) {
            $deposit = $this->depositRequestRepository->findOneByExternalReference($reference);
            $withdrawal = $this->withdrawalRequestRepository->findOneByExternalReference($reference);
        }

        if (null !== $deposit && null !== $withdrawal) {
            $io->error('External reference matches both a deposit and a withdrawal; pass the request UUID instead.');

            return Command::FAILURE;
        }

        if (null === $deposit && null === $withdrawal) {
            $io->error(sprintf('No deposit or withdrawal found for "%s".', $reference));

            return Command::FAILURE;
        }

        try {
            if ($deposit instanceof DepositRequest) {
                $this->simulator->transitionDeposit($deposit, $status, $input->getOption('amount'));
                $io->success(sprintf('Deposit %s is now "%s".', $deposit->getId(), $deposit->getStatus()->value));
            } elseif ($withdrawal instanceof WithdrawalRequest) {
                $this->simulator->transitionWithdrawal($withdrawal, $status);
                $io->success(sprintf('Withdrawal %s is now "%s".', $withdrawal->getId(), $withdrawal->getStatus()->value));
            }
        } catch (BinanceTestSimulationException $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        }

        $io->note('Terminal statuses queue a callback to Okean; it is delivered by the "async" messenger worker (bin/console messenger:consume async).');

        return Command::SUCCESS;
    }
}
