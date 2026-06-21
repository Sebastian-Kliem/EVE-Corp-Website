<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260621144027 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE eve_character_wallet_journal_entry (id INT AUTO_INCREMENT NOT NULL, character_id BIGINT NOT NULL, ref_id BIGINT NOT NULL, date DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', ref_type VARCHAR(100) NOT NULL, amount NUMERIC(20, 2) NOT NULL, balance NUMERIC(20, 2) NOT NULL, description LONGTEXT DEFAULT NULL, first_party_id INT DEFAULT NULL, second_party_id INT DEFAULT NULL, context_id BIGINT DEFAULT NULL, context_id_type VARCHAR(50) DEFAULT NULL, reason LONGTEXT DEFAULT NULL, tax NUMERIC(20, 2) DEFAULT NULL, tax_receiver_id INT DEFAULT NULL, INDEX IDX_F97F317C1136BE75 (character_id), INDEX IDX_F97F317CAA9E377A (date), UNIQUE INDEX uniq_char_wallet_ref (character_id, ref_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE eve_character_wallet_journal_entry ADD CONSTRAINT FK_F97F317C1136BE75 FOREIGN KEY (character_id) REFERENCES eve_character (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE eve_character_wallet_journal_entry DROP FOREIGN KEY FK_F97F317C1136BE75');
        $this->addSql('DROP TABLE eve_character_wallet_journal_entry');
    }
}
