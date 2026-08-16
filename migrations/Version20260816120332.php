<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260816120332 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE eve_corporation_starbase (id BIGINT NOT NULL, corporation_id BIGINT NOT NULL, type_id INT NOT NULL, type_name VARCHAR(255) DEFAULT NULL, solar_system_id INT NOT NULL, solar_system_name VARCHAR(100) DEFAULT NULL, state VARCHAR(50) NOT NULL, onlined_since DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', reinforced_until DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', fuels JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', modules JSON DEFAULT NULL COMMENT \'(DC2Type:json)\', last_updated DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE eve_corporation_structure (id BIGINT NOT NULL, corporation_id BIGINT NOT NULL, name VARCHAR(255) DEFAULT NULL, type_id INT NOT NULL, type_name VARCHAR(255) DEFAULT NULL, solar_system_id INT NOT NULL, solar_system_name VARCHAR(100) DEFAULT NULL, state VARCHAR(50) NOT NULL, fuel_expires DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', services JSON NOT NULL COMMENT \'(DC2Type:json)\', reinforce_hour INT DEFAULT NULL, last_updated DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE eve_corporation_starbase');
        $this->addSql('DROP TABLE eve_corporation_structure');
    }
}
