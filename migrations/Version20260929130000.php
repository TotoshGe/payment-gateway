<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260929130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'panel_route: coin -> panel routing.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE panel_route (id BINARY(16) NOT NULL, currency VARCHAR(32) NOT NULL, network VARCHAR(32) DEFAULT NULL, enabled TINYINT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, panel_id BINARY(16) NOT NULL, UNIQUE INDEX uniq_panel_route_currency_network (currency, network), INDEX IDX_D202336F6F6FCB26 (panel_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE panel_route ADD CONSTRAINT FK_D202336F6F6FCB26 FOREIGN KEY (panel_id) REFERENCES panel (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE panel_route DROP FOREIGN KEY FK_D202336F6F6FCB26');
        $this->addSql('DROP TABLE panel_route');
    }
}
