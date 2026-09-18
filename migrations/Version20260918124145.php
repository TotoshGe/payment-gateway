<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260918124145 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Initial schema: Panel, PanelWalletAddress, DepositRequest, WithdrawalRequest, CallbackDelivery, AdminUser.';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE admin_user (id BINARY(16) NOT NULL, email VARCHAR(180) NOT NULL, password VARCHAR(255) NOT NULL, roles JSON NOT NULL, UNIQUE INDEX UNIQ_AD8A54A9E7927C74 (email), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE callback_delivery (id BINARY(16) NOT NULL, event_id BINARY(16) NOT NULL, request_type VARCHAR(16) NOT NULL, request_id BINARY(16) NOT NULL, event_type VARCHAR(64) NOT NULL, payload JSON NOT NULL, attempt INT NOT NULL, status VARCHAR(16) NOT NULL, next_attempt_at DATETIME DEFAULT NULL, last_response_code INT DEFAULT NULL, last_error LONGTEXT DEFAULT NULL, attempt_log JSON NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_1D7BEE3071F7E88B (event_id), INDEX idx_callback_status (status), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE deposit_request (id BINARY(16) NOT NULL, external_reference VARCHAR(190) NOT NULL, currency VARCHAR(32) NOT NULL, network VARCHAR(32) DEFAULT NULL, expected_amount VARCHAR(64) NOT NULL, address VARCHAR(255) DEFAULT NULL, address_tag VARCHAR(128) DEFAULT NULL, wallet_address_id BINARY(16) DEFAULT NULL, panel_deposit_reference VARCHAR(128) DEFAULT NULL, status VARCHAR(16) NOT NULL, received_amount VARCHAR(64) DEFAULT NULL, confirmations INT DEFAULT NULL, expires_at DATETIME NOT NULL, last_polled_at DATETIME DEFAULT NULL, callback_status VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, panel_id BINARY(16) NOT NULL, UNIQUE INDEX UNIQ_3A5D75C48AF8E607 (external_reference), INDEX idx_deposit_status (status), INDEX IDX_3A5D75C46F6FCB26 (panel_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE panel (id BINARY(16) NOT NULL, code VARCHAR(64) NOT NULL, label VARCHAR(255) NOT NULL, active TINYINT NOT NULL, encrypted_credentials LONGTEXT DEFAULT NULL, config JSON NOT NULL, supported_currencies JSON NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_A2ADD30F77153098 (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE panel_wallet_address (id BINARY(16) NOT NULL, currency VARCHAR(32) NOT NULL, network VARCHAR(32) DEFAULT NULL, slot_index INT NOT NULL, address VARCHAR(255) NOT NULL, address_tag VARCHAR(128) DEFAULT NULL, status VARCHAR(16) NOT NULL, held_by_deposit_request_id BINARY(16) DEFAULT NULL, held_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, panel_id BINARY(16) NOT NULL, INDEX idx_pool_lookup (panel_id, currency, network, status), UNIQUE INDEX uniq_panel_currency_network_slot (panel_id, currency, network, slot_index), INDEX IDX_EC52F13A6F6FCB26 (panel_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE withdrawal_request (id BINARY(16) NOT NULL, external_reference VARCHAR(190) NOT NULL, currency VARCHAR(32) NOT NULL, network VARCHAR(32) DEFAULT NULL, amount VARCHAR(64) NOT NULL, destination_address VARCHAR(255) NOT NULL, destination_tag VARCHAR(128) DEFAULT NULL, client_withdrawal_id VARCHAR(64) NOT NULL, panel_withdrawal_reference VARCHAR(128) DEFAULT NULL, tx_hash VARCHAR(128) DEFAULT NULL, status VARCHAR(16) NOT NULL, failure_reason LONGTEXT DEFAULT NULL, last_polled_at DATETIME DEFAULT NULL, callback_status VARCHAR(16) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, panel_id BINARY(16) NOT NULL, UNIQUE INDEX UNIQ_5E56F6D78AF8E607 (external_reference), UNIQUE INDEX UNIQ_5E56F6D71ECB030F (client_withdrawal_id), INDEX idx_withdrawal_status (status), INDEX IDX_5E56F6D76F6FCB26 (panel_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE deposit_request ADD CONSTRAINT FK_3A5D75C46F6FCB26 FOREIGN KEY (panel_id) REFERENCES panel (id)');
        $this->addSql('ALTER TABLE panel_wallet_address ADD CONSTRAINT FK_EC52F13A6F6FCB26 FOREIGN KEY (panel_id) REFERENCES panel (id)');
        $this->addSql('ALTER TABLE withdrawal_request ADD CONSTRAINT FK_5E56F6D76F6FCB26 FOREIGN KEY (panel_id) REFERENCES panel (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE deposit_request DROP FOREIGN KEY FK_3A5D75C46F6FCB26');
        $this->addSql('ALTER TABLE panel_wallet_address DROP FOREIGN KEY FK_EC52F13A6F6FCB26');
        $this->addSql('ALTER TABLE withdrawal_request DROP FOREIGN KEY FK_5E56F6D76F6FCB26');
        $this->addSql('DROP TABLE admin_user');
        $this->addSql('DROP TABLE callback_delivery');
        $this->addSql('DROP TABLE deposit_request');
        $this->addSql('DROP TABLE panel');
        $this->addSql('DROP TABLE panel_wallet_address');
        $this->addSql('DROP TABLE withdrawal_request');
    }
}
