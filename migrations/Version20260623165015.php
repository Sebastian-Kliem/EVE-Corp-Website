<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260623165015 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE eve_character_market_transaction (id INT AUTO_INCREMENT NOT NULL, character_id BIGINT NOT NULL, transaction_id BIGINT NOT NULL, date DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', type_id INT NOT NULL, quantity BIGINT NOT NULL, unit_price NUMERIC(20, 2) NOT NULL, is_buy TINYINT(1) NOT NULL, client_id INT NOT NULL, location_id BIGINT NOT NULL, journal_ref_id BIGINT NOT NULL, INDEX IDX_C4ACAAC1136BE75 (character_id), INDEX IDX_C4ACAACAA9E377A (date), INDEX IDX_C4ACAACC54C8C93 (type_id), UNIQUE INDEX uniq_char_market_trans_id (character_id, transaction_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE eve_character_market_transaction ADD CONSTRAINT FK_C4ACAAC1136BE75 FOREIGN KEY (character_id) REFERENCES eve_character (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE eve_character_market_transaction DROP FOREIGN KEY FK_C4ACAAC1136BE75');
        $this->addSql('DROP TABLE eve_character_market_transaction');
    }
}
