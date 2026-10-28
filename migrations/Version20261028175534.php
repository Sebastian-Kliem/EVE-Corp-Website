<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261028175534 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add wormhole sites';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE wormhole_site (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(128) NOT NULL, name_de VARCHAR(128) DEFAULT NULL, category VARCHAR(16) NOT NULL, classes JSON NOT NULL COMMENT \'(DC2Type:json)\', occurrence VARCHAR(128) DEFAULT NULL, blue_loot_isk BIGINT DEFAULT NULL, waves JSON NOT NULL COMMENT \'(DC2Type:json)\', resources JSON NOT NULL COMMENT \'(DC2Type:json)\', notes JSON NOT NULL COMMENT \'(DC2Type:json)\', source VARCHAR(64) NOT NULL, sort_order INT NOT NULL, UNIQUE INDEX UNIQ_18790CE45E237E06 (name), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE wormhole_site');
    }
}
