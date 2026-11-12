<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261112095256 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Log observed personal market order states to attribute broker fees per item';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE eve_character_market_order_log (id INT AUTO_INCREMENT NOT NULL, character_id BIGINT NOT NULL, order_id BIGINT NOT NULL, type_id INT NOT NULL, issued DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', price NUMERIC(20, 2) NOT NULL, volume_total INT NOT NULL, volume_remain INT NOT NULL, is_buy TINYINT(1) NOT NULL, duration INT NOT NULL, INDEX IDX_73D3BF111136BE7559AEFA44 (character_id, issued), UNIQUE INDEX uniq_market_order_log_state (character_id, order_id, issued), INDEX IDX_73D3BF111136BE75 (character_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE eve_character_market_order_log ADD CONSTRAINT FK_73D3BF111136BE75 FOREIGN KEY (character_id) REFERENCES eve_character (id) ON DELETE CASCADE');
        // Seed with the currently stored open orders
        $this->addSql('INSERT IGNORE INTO eve_character_market_order_log (character_id, order_id, type_id, issued, price, volume_total, volume_remain, is_buy, duration) SELECT character_id, order_id, type_id, issued, price, volume_total, volume_remain, is_buy, duration FROM eve_character_market_order WHERE is_corporation = 0');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE eve_character_market_order_log DROP FOREIGN KEY FK_73D3BF111136BE75');
        $this->addSql('DROP TABLE eve_character_market_order_log');
    }
}
