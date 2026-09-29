<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260929120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'deposit_request.paused_at for the paused-deposit timeout.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deposit_request ADD paused_at DATETIME DEFAULT NULL');
        $this->addSql("UPDATE deposit_request SET paused_at = updated_at WHERE status = 'paused'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deposit_request DROP paused_at');
    }
}
