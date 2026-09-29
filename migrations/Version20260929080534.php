<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Payments as first-class children of requests, and external_reference -> uuid
 * (the bare Okean UUID, without the okean-payin-/okean-payout- prefix).
 *
 * up() also backfills payments for existing rows: every withdrawal gets one,
 * a deposit gets one only if funds were already seen (received/completed).
 * down() drops the payments table (that data is derived from the request
 * columns, which are kept in sync, so nothing unrecoverable is lost) and
 * restores the prefixes.
 */
final class Version20260929080534 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add payment table (with backfill) and rename external_reference to uuid, dropping the okean-payin-/okean-payout- prefix.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE payment (id BINARY(16) NOT NULL, type VARCHAR(16) NOT NULL, amount VARCHAR(64) NOT NULL, currency VARCHAR(32) NOT NULL, network VARCHAR(32) DEFAULT NULL, status VARCHAR(16) NOT NULL, confirmations INT DEFAULT NULL, panel_reference VARCHAR(128) DEFAULT NULL, tx_hash VARCHAR(128) DEFAULT NULL, reason LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, deposit_request_id BINARY(16) DEFAULT NULL, withdrawal_request_id BINARY(16) DEFAULT NULL, panel_id BINARY(16) NOT NULL, INDEX idx_payment_status (status), UNIQUE INDEX uniq_payment_panel_type_reference (panel_id, type, panel_reference), INDEX IDX_6D28840DB7755B6C (deposit_request_id), INDEX IDX_6D28840D2E695421 (withdrawal_request_id), INDEX IDX_6D28840D6F6FCB26 (panel_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840DB7755B6C FOREIGN KEY (deposit_request_id) REFERENCES deposit_request (id)');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840D2E695421 FOREIGN KEY (withdrawal_request_id) REFERENCES withdrawal_request (id)');
        $this->addSql('ALTER TABLE payment ADD CONSTRAINT FK_6D28840D6F6FCB26 FOREIGN KEY (panel_id) REFERENCES panel (id)');
        $this->addSql('DROP INDEX UNIQ_3A5D75C48AF8E607 ON deposit_request');
        $this->addSql('ALTER TABLE deposit_request CHANGE external_reference uuid VARCHAR(190) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3A5D75C4D17F50A6 ON deposit_request (uuid)');
        $this->addSql('DROP INDEX UNIQ_5E56F6D78AF8E607 ON withdrawal_request');
        $this->addSql('ALTER TABLE withdrawal_request CHANGE external_reference uuid VARCHAR(190) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5E56F6D7D17F50A6 ON withdrawal_request (uuid)');

        $this->addSql("UPDATE deposit_request SET uuid = SUBSTRING(uuid, 13) WHERE uuid LIKE 'okean-payin-%'");
        $this->addSql("UPDATE withdrawal_request SET uuid = SUBSTRING(uuid, 14) WHERE uuid LIKE 'okean-payout-%'");

        $this->addSql(<<<'SQL'
            INSERT INTO payment (id, type, amount, currency, network, status, confirmations, panel_reference, tx_hash, reason, created_at, updated_at, deposit_request_id, withdrawal_request_id, panel_id)
            SELECT UNHEX(REPLACE(UUID(), '-', '')), 'withdrawal', amount, currency, network,
                CASE status
                    WHEN 'processing' THEN 'confirming'
                    WHEN 'completed' THEN 'completed'
                    WHEN 'failed' THEN 'failed'
                    WHEN 'submit_failed' THEN 'failed'
                    ELSE 'pending'
                END,
                NULL, panel_withdrawal_reference, tx_hash, failure_reason, created_at, updated_at, NULL, id, panel_id
            FROM withdrawal_request
            SQL);
        $this->addSql(<<<'SQL'
            INSERT INTO payment (id, type, amount, currency, network, status, confirmations, panel_reference, tx_hash, reason, created_at, updated_at, deposit_request_id, withdrawal_request_id, panel_id)
            SELECT UNHEX(REPLACE(UUID(), '-', '')), 'deposit', COALESCE(received_amount, expected_amount), currency, network,
                CASE status WHEN 'completed' THEN 'completed' ELSE 'confirming' END,
                confirmations, panel_deposit_reference, panel_deposit_reference, NULL, created_at, updated_at, id, NULL, panel_id
            FROM deposit_request
            WHERE status IN ('received', 'completed')
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql("UPDATE deposit_request SET uuid = CONCAT('okean-payin-', uuid) WHERE uuid NOT LIKE 'okean-payin-%'");
        $this->addSql("UPDATE withdrawal_request SET uuid = CONCAT('okean-payout-', uuid) WHERE uuid NOT LIKE 'okean-payout-%'");
        $this->addSql('ALTER TABLE payment DROP FOREIGN KEY FK_6D28840DB7755B6C');
        $this->addSql('ALTER TABLE payment DROP FOREIGN KEY FK_6D28840D2E695421');
        $this->addSql('ALTER TABLE payment DROP FOREIGN KEY FK_6D28840D6F6FCB26');
        $this->addSql('DROP TABLE payment');
        $this->addSql('DROP INDEX UNIQ_3A5D75C4D17F50A6 ON deposit_request');
        $this->addSql('ALTER TABLE deposit_request CHANGE uuid external_reference VARCHAR(190) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_3A5D75C48AF8E607 ON deposit_request (external_reference)');
        $this->addSql('DROP INDEX UNIQ_5E56F6D7D17F50A6 ON withdrawal_request');
        $this->addSql('ALTER TABLE withdrawal_request CHANGE uuid external_reference VARCHAR(190) NOT NULL');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_5E56F6D78AF8E607 ON withdrawal_request (external_reference)');
    }
}
