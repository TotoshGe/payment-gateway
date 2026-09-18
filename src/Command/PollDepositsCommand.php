<?php

declare(strict_types=1);

namespace App\Command;

use App\Panel\Exception\PanelException;
use App\Panel\PanelRegistry;
use App\Repository\DepositRequestRepository;
use App\Repository\PanelRepository;
use App\Service\DepositRequestService;
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
 * One supervised long-running process per panel (systemd, like rates-
 * service's app:ingest:exchange), not a cron/timer: the polling interval is
 * measured in seconds, so re-bootstrapping the whole Symfony kernel/DB
 * connection on every tick would dominate the actual work. See
 * ARCHITECTURE.md section 4 for the cost-bound reasoning (one provider API
 * call per currency/network per tick, not per request).
 */
#[AsCommand(
    name: 'app:payment-gateway:poll-deposits',
    description: 'Polls a panel for incoming deposits against AWAITING_PAYMENT requests and expires stale ones.',
)]
class PollDepositsCommand extends Command
{
    public function __construct(
        private readonly PanelRepository $panelRepository,
        private readonly PanelRegistry $panelRegistry,
        private readonly DepositRequestRepository $depositRequestRepository,
        private readonly DepositRequestService $depositRequestService,
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
            ->addOption('interval', null, InputOption::VALUE_REQUIRED, 'Seconds between polling cycles', '15')
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

        $lock = $this->lockFactory->createLock('payment-gateway.poll-deposits.'.$panelCode);
        if (!$lock->acquire()) {
            $io->warning('Another poll-deposits worker is already running for this panel, exiting.');

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
        $activeRequests = $this->depositRequestRepository->findAwaitingPaymentForPanel($panel);
        $now = new \DateTimeImmutable();

        if ([] === $activeRequests) {
            return;
        }

        $byId = [];
        foreach ($activeRequests as $request) {
            $byId[(string) $request->getId()] = $request;
            $request->markPolled($now);
        }
        $this->entityManager->flush();

        try {
            $driver = $this->panelRegistry->getDriverFor($panel);
            foreach ($driver->checkDeposits($panel, $activeRequests) as $update) {
                $request = $byId[(string) $update->depositRequestId] ?? null;
                if (null === $request) {
                    continue;
                }

                $this->depositRequestService->applyStatusUpdate(
                    $request,
                    $update->status,
                    $update->observedAmount,
                    $update->confirmations,
                    $update->panelDepositReference,
                );
            }
        } catch (PanelException $exception) {
            $this->logger->error('Deposit polling cycle failed', [
                'panel' => $panel->getCode(),
                'error' => $exception->getMessage(),
            ]);
            $io->error(sprintf('Panel call failed: %s', $exception->getMessage()));
        }

        foreach ($activeRequests as $request) {
            if ($request->getExpiresAt() < $now) {
                $this->depositRequestService->expire($request);
            }
        }
    }
}
