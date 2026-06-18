<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260617191531 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE eve_character_asset_snapshot (id INT AUTO_INCREMENT NOT NULL, character_id BIGINT NOT NULL, snapshot_date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', assets_data JSON NOT NULL COMMENT \'(DC2Type:json)\', INDEX IDX_835818AE1136BE75 (character_id), INDEX IDX_835818AEB8CEA207 (snapshot_date), UNIQUE INDEX uniq_char_snapshot_date (character_id, snapshot_date), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE eve_character_mining_record (id INT AUTO_INCREMENT NOT NULL, character_id BIGINT NOT NULL, date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', solar_system_id INT NOT NULL, type_id INT NOT NULL, quantity BIGINT NOT NULL, INDEX IDX_111CFAB81136BE75 (character_id), INDEX IDX_111CFAB8AA9E377A (date), UNIQUE INDEX uniq_char_mining_record (character_id, date, solar_system_id, type_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE eve_character_asset_snapshot ADD CONSTRAINT FK_835818AE1136BE75 FOREIGN KEY (character_id) REFERENCES eve_character (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE eve_character_mining_record ADD CONSTRAINT FK_111CFAB81136BE75 FOREIGN KEY (character_id) REFERENCES eve_character (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE eve_character ADD last_mining_update DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE eve_character_asset_snapshot DROP FOREIGN KEY FK_835818AE1136BE75');
        $this->addSql('ALTER TABLE eve_character_mining_record DROP FOREIGN KEY FK_111CFAB81136BE75');
        $this->addSql('DROP TABLE eve_character_asset_snapshot');
        $this->addSql('DROP TABLE eve_character_mining_record');
        $this->addSql('ALTER TABLE eve_character DROP last_mining_update');
    }
}
