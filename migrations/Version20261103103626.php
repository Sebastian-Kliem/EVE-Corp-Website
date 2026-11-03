<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261103103626 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Track corporations for the structure sync and per structure alert switches';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE tracked_corporation (id BIGINT NOT NULL, name VARCHAR(255) DEFAULT NULL, ticker VARCHAR(10) DEFAULT NULL, status VARCHAR(20) NOT NULL, use_global_channels TINYINT(1) NOT NULL, webhook_url LONGTEXT DEFAULT NULL, ping_role VARCHAR(100) DEFAULT NULL, discovered_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', decided_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE eve_corporation_starbase ADD alerts_enabled TINYINT(1) DEFAULT 1 NOT NULL');
        $this->addSql('ALTER TABLE eve_corporation_structure ADD alerts_enabled TINYINT(1) DEFAULT 1 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE tracked_corporation');
        $this->addSql('ALTER TABLE eve_corporation_starbase DROP alerts_enabled');
        $this->addSql('ALTER TABLE eve_corporation_structure DROP alerts_enabled');
    }
}
