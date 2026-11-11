<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261111193739 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Flag corporation market orders and contracts, store market and contract value per value snapshot';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE eve_character_contract ADD for_corporation TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE eve_character_market_order ADD is_corporation TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE eve_character_value_snapshot ADD market_value NUMERIC(20, 2) DEFAULT NULL, ADD contracts_value NUMERIC(20, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE eve_character_contract DROP for_corporation');
        $this->addSql('ALTER TABLE eve_character_value_snapshot DROP market_value, DROP contracts_value');
        $this->addSql('ALTER TABLE eve_character_market_order DROP is_corporation');
    }
}
