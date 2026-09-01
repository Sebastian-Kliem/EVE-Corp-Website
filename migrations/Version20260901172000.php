<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260901172000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add discord_notification_log table and structure/starbase fuel alert tracking fields';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE discord_notification_log (id INT AUTO_INCREMENT NOT NULL, notification_id BIGINT DEFAULT NULL, channel VARCHAR(50) NOT NULL, type VARCHAR(100) NOT NULL, entity_type VARCHAR(50) DEFAULT NULL, entity_id BIGINT DEFAULT NULL, alert_level VARCHAR(50) DEFAULT NULL, metadata JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX idx_discord_notif_id (notification_id), INDEX idx_discord_entity (entity_type, entity_id), INDEX idx_discord_created_at (created_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE eve_corporation_structure ADD last_fuel_alert_days INT DEFAULT NULL, ADD previous_fuel_expires DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD previous_state VARCHAR(50) DEFAULT NULL');
        $this->addSql('ALTER TABLE eve_corporation_starbase ADD last_fuel_alert_days INT DEFAULT NULL, ADD previous_fuel_expires DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD previous_state VARCHAR(50) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE discord_notification_log');
        $this->addSql('ALTER TABLE eve_corporation_structure DROP last_fuel_alert_days, DROP previous_fuel_expires, DROP previous_state');
        $this->addSql('ALTER TABLE eve_corporation_starbase DROP last_fuel_alert_days, DROP previous_fuel_expires, DROP previous_state');
    }
}
