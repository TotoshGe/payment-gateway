<?php

declare(strict_types=1);

namespace App\Command;

use App\Panel\Exception\PanelException;
use App\Panel\PanelRegistry;
use App\Repository\PanelRepository;
use App\Repository\WithdrawalRequestRepository;
use App\Service\WithdrawalRequestService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Lock\LockFactory;

/**
 * Separate process/command from poll-deposits on purpose (see
 * ARCHITECTURE.md section 4): withdrawals are far lower volume and less
 * time-sensitive than deposits, and isolating the two means a stuck/failed
 * withdrawal poller can't stop deposit detection (or vice versa).
 */
#[AsCommand(
    name: 'app:payment-gateway:poll-withdrawals',
    description: 'Polls a panel for withdrawal confirmation against SUBMITTED/PROCESSING requests.',
)]
class PollWithdrawalsCommand extends Command
{
    public function __construct(
        private readonly PanelRepository $panelRepository,
        private readonly PanelRegistry $panelRegistry,
        private readonly WithdrawalRequestRepository $withdrawalRequestRepository,
        private readonly WithdrawalRequestService $withdrawalRequestService,
        private readonly EntityManagerInterface $entityManager,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('panel-code', InputArgument::REQUIRED, 'Panel code to poll, e.g. "binance"')
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Seconds between polling cycles', '60')
            ->addOption('once', null, InputOption::VALUE_NONE, 'Run a single cycle and exit (used by tests/manual runs)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $panelCode = (string) $input->getArgument('panel-code');
        $interval = max(1, (int) $input->getOption('interval'));
        $once = (bool) $input->getOption('once');

        $panel = $this->panelRepository->findOneByCode($panelCode);
        if (null === $panel) {
            $io->error(sprintf('No panel with code "%s".', $panelCode));

            return Command::FAILURE;
        }

        $lock = $this->lockFactory->createLock('payment-gateway.poll-withdrawals.'.$panelCode);
        if (!$lock->acquire()) {
            $io->warning('Another poll-withdrawals worker is already running for this panel, exiting.');

            return Command::SUCCESS;
        }

        try {
            do {
                $this->runCycle($panel, $io);

                if (!$once) {
                    sleep($interval);
                }
            } while (!$once);
        } finally {
            $lock->release();
        }

        return Command::SUCCESS;
    }

    private function runCycle(\App\Entity\Panel $panel, SymfonyStyle $io): void
    {
        $activeRequests = $this->withdrawalRequestRepository->findInFlightForPanel($panel);
        if ([] === $activeRequests) {
            return;
        }

        $now = new \DateTimeImmutable();
        $byClientId = [];
        foreach ($activeRequests as $request) {
            $byClientId[$request->getClientWithdrawalId()] = $request;
            $request->markPolled($now);
        }
        $this->entityManager->flush();

        try {
            $driver = $this->panelRegistry->getDriverFor($panel);
            foreach ($driver->checkWithdrawals($panel, $activeRequests) as $update) {
                foreach ($byClientId as $request) {
                    if ($request->getPanelWithdrawalReference() === $update->panelWithdrawalReference) {
                        $this->withdrawalRequestService->applyStatusUpdate($request, $update->status, $update->txHash, $update->failureReason);
                        break;
                    }
                }
            }
        } catch (PanelException $exception) {
            $this->logger->error('Withdrawal polling cycle failed', [
                'panel' => $panel->getCode(),
                'error' => $exception->getMessage(),
            ]);
            $io->error(sprintf('Panel call failed: %s', $exception->getMessage()));
        }
    }
}
