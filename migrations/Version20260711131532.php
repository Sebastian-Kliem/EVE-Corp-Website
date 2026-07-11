<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260711131532 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE eve_character_market_order (id INT AUTO_INCREMENT NOT NULL, character_id BIGINT NOT NULL, order_id BIGINT NOT NULL, type_id INT NOT NULL, location_id BIGINT NOT NULL, volume_total INT NOT NULL, volume_remain INT NOT NULL, price NUMERIC(20, 2) NOT NULL, escrow NUMERIC(20, 2) DEFAULT NULL, is_buy TINYINT(1) NOT NULL, issued DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', duration INT NOT NULL, `range` VARCHAR(50) NOT NULL, min_volume INT DEFAULT NULL, INDEX IDX_1A7E64231136BE75 (character_id), INDEX IDX_1A7E6423C54C8C93 (type_id), UNIQUE INDEX uniq_char_market_order_id (character_id, order_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE eve_character_market_order ADD CONSTRAINT FK_1A7E64231136BE75 FOREIGN KEY (character_id) REFERENCES eve_character (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE eve_character_market_order DROP FOREIGN KEY FK_1A7E64231136BE75');
        $this->addSql('DROP TABLE eve_character_market_order');
    }
}
