<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260930100000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Statuses awaiting_payout / awaiting_confirmations (wider status columns), payment.required_confirmations.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE deposit_request MODIFY status VARCHAR(32) NOT NULL');
        $this->addSql('ALTER TABLE withdrawal_request MODIFY status VARCHAR(32) NOT NULL');
        $this->addSql('ALTER TABLE payment ADD required_confirmations INT DEFAULT NULL');
        // Withdrawals no longer start "new"/"submitted" without an operator: only never-sent ones (no payment) move to the new waiting status.
        $this->addSql("UPDATE withdrawal_request w SET w.status = 'awaiting_payout' WHERE w.status = 'new' AND NOT EXISTS (SELECT 1 FROM payment p WHERE p.withdrawal_request_id = w.id)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE withdrawal_request SET status = 'new' WHERE status = 'awaiting_payout'");
        $this->addSql("UPDATE deposit_request SET status = 'received' WHERE status = 'awaiting_confirmations'");
        $this->addSql("UPDATE withdrawal_request SET status = 'processing' WHERE status = 'awaiting_confirmations'");
        $this->addSql('ALTER TABLE payment DROP required_confirmations');
        $this->addSql('ALTER TABLE withdrawal_request MODIFY status VARCHAR(16) NOT NULL');
        $this->addSql('ALTER TABLE deposit_request MODIFY status VARCHAR(16) NOT NULL');
    }
}
