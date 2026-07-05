<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260705163958 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE eve_killmail (id INT AUTO_INCREMENT NOT NULL, character_id BIGINT NOT NULL, killmail_id BIGINT NOT NULL, killmail_hash VARCHAR(100) NOT NULL, killmail_time DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', solar_system_id INT NOT NULL, victim_character_id INT DEFAULT NULL, victim_corporation_id INT DEFAULT NULL, victim_alliance_id INT DEFAULT NULL, victim_ship_type_id INT DEFAULT NULL, is_loss TINYINT(1) DEFAULT 0 NOT NULL, is_kill TINYINT(1) DEFAULT 0 NOT NULL, data JSON NOT NULL COMMENT \'(DC2Type:json)\', INDEX IDX_87AF97B61136BE75 (character_id), INDEX IDX_87AF97B6E6468A65 (killmail_time), UNIQUE INDEX uniq_char_killmail_id (character_id, killmail_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE eve_killmail ADD CONSTRAINT FK_87AF97B61136BE75 FOREIGN KEY (character_id) REFERENCES eve_character (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE eve_character ADD last_killmails_update DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE eve_killmail DROP FOREIGN KEY FK_87AF97B61136BE75');
        $this->addSql('DROP TABLE eve_killmail');
        $this->addSql('ALTER TABLE eve_character DROP last_killmails_update');
    }
}
