<?php

declare(strict_types=1);

namespace App\Command;

use App\Entity\AdminUser;
use App\Repository\AdminUserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(name: 'app:admin-user:create', description: 'Creates (or updates the password of) an EasyAdmin back-office user.')]
class CreateAdminUserCommand extends Command
{
    public function __construct(
        private readonly AdminUserRepository $adminUserRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED)
            ->addArgument('password', InputArgument::REQUIRED);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = (string) $input->getArgument('email');
        $password = (string) $input->getArgument('password');

        $user = $this->adminUserRepository->findOneBy(['email' => $email]);
        $isNew = null === $user;
        $user ??= new AdminUser($email);

        $user->setPassword($this->passwordHasher->hashPassword($user, $password));

        if ($isNew) {
            $this->entityManager->persist($user);
        }
        $this->entityManager->flush();

        $io->success(sprintf('%s admin user "%s".', $isNew ? 'Created' : 'Updated', $email));

        return Command::SUCCESS;
    }
}
