<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260618185549 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE eve_character_value_snapshot (id INT AUTO_INCREMENT NOT NULL, character_id BIGINT NOT NULL, snapshot_date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', wallet_balance NUMERIC(20, 2) NOT NULL, assets_value NUMERIC(20, 2) NOT NULL, INDEX IDX_4C6AC1031136BE75 (character_id), INDEX IDX_4C6AC103B8CEA207 (snapshot_date), UNIQUE INDEX uniq_char_val_snapshot_date (character_id, snapshot_date), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE eve_character_value_snapshot ADD CONSTRAINT FK_4C6AC1031136BE75 FOREIGN KEY (character_id) REFERENCES eve_character (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE eve_character_value_snapshot DROP FOREIGN KEY FK_4C6AC1031136BE75');
        $this->addSql('DROP TABLE eve_character_value_snapshot');
    }
}
