<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260623164722 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE eve_character_contract (id INT AUTO_INCREMENT NOT NULL, character_id BIGINT NOT NULL, contract_id BIGINT NOT NULL, type VARCHAR(50) NOT NULL, status VARCHAR(50) NOT NULL, start_location_id BIGINT DEFAULT NULL, end_location_id BIGINT DEFAULT NULL, price NUMERIC(20, 2) DEFAULT NULL, reward NUMERIC(20, 2) DEFAULT NULL, collateral NUMERIC(20, 2) DEFAULT NULL, buyout NUMERIC(20, 2) DEFAULT NULL, date_issued DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', date_expired DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', date_completed DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', items JSON NOT NULL COMMENT \'(DC2Type:json)\', title LONGTEXT DEFAULT NULL, issuer_id INT DEFAULT NULL, acceptor_id INT DEFAULT NULL, INDEX IDX_B04FE74C1136BE75 (character_id), INDEX IDX_B04FE74C20A8EAB6 (date_expired), INDEX IDX_B04FE74C58E6FDA (date_completed), UNIQUE INDEX uniq_char_contract_id (character_id, contract_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE eve_character_contract ADD CONSTRAINT FK_B04FE74C1136BE75 FOREIGN KEY (character_id) REFERENCES eve_character (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE eve_character_contract DROP FOREIGN KEY FK_B04FE74C1136BE75');
        $this->addSql('DROP TABLE eve_character_contract');
    }
}
