<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261005113313 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop the Wanderer rule cooldown and the settings of the removed webhook integration';
    }

    // DDL commits implicitly on MariaDB
    public function isTransactional(): bool
    {
        return false;
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wanderer_route_rule DROP cooldown_minutes');
        $this->addSql("DELETE FROM app_setting WHERE setting_key IN ('wanderer_webhook_secret', 'wanderer_seen_connection_ids')");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE wanderer_route_rule ADD cooldown_minutes INT NOT NULL DEFAULT 180');
    }
}
