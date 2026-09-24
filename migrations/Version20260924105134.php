<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260924105134 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE corp_order_items (id INT AUTO_INCREMENT NOT NULL, order_id INT NOT NULL, fulfiller_id INT DEFAULT NULL, type_id INT NOT NULL, name VARCHAR(255) NOT NULL, amount INT NOT NULL, unit_price NUMERIC(20, 2) NOT NULL, total_price NUMERIC(20, 2) NOT NULL, unit_volume NUMERIC(14, 2) NOT NULL, total_volume NUMERIC(14, 2) NOT NULL, slot VARCHAR(50) DEFAULT \'cargo\' NOT NULL, sort_order INT DEFAULT 100 NOT NULL, is_fulfilled TINYINT(1) DEFAULT 0 NOT NULL, fulfilled_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_1C9916138D9F6D38 (order_id), INDEX IDX_1C991613220D33CE (fulfiller_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE corp_orders (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, type VARCHAR(10) NOT NULL, status VARCHAR(20) DEFAULT \'OPEN\' NOT NULL, title VARCHAR(255) NOT NULL, is_fitting TINYINT(1) DEFAULT 0 NOT NULL, ship_type_id INT DEFAULT NULL, percent_to_jita INT DEFAULT 100 NOT NULL, total_price NUMERIC(20, 2) NOT NULL, total_volume NUMERIC(14, 2) NOT NULL, note LONGTEXT DEFAULT NULL, contract_id BIGINT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', fulfilled_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_7128A1F8A76ED395 (user_id), INDEX IDX_7128A1F88CDE5729 (type), INDEX IDX_7128A1F87B00651C (status), INDEX IDX_7128A1F88B8E8428 (created_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE corp_order_items ADD CONSTRAINT FK_1C9916138D9F6D38 FOREIGN KEY (order_id) REFERENCES corp_orders (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE corp_order_items ADD CONSTRAINT FK_1C991613220D33CE FOREIGN KEY (fulfiller_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE corp_orders ADD CONSTRAINT FK_7128A1F8A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE buy_order DROP FOREIGN KEY FK_C70F69276C755722');
        $this->addSql('ALTER TABLE buy_order DROP FOREIGN KEY FK_C70F6927220D33CE');
        $this->addSql('ALTER TABLE sell_order DROP FOREIGN KEY FK_ED81DFC48DE820D9');
        $this->addSql('ALTER TABLE sell_order DROP FOREIGN KEY FK_ED81DFC46C755722');
        $this->addSql('DROP TABLE buy_order');
        $this->addSql('DROP TABLE sell_order');
        $this->addSql('ALTER TABLE defense_doctrine_fit RENAME INDEX idx_defense_doctrine_fit_created_by TO IDX_AD534CA3B03A8386');
        $this->addSql('DROP INDEX IDX_EVE_CHARACTER_IS_ONLINE ON eve_character');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE buy_order (id INT AUTO_INCREMENT NOT NULL, buyer_id INT NOT NULL, fulfiller_id INT DEFAULT NULL, item VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, amount INT NOT NULL, fullfilled TINYINT(1) DEFAULT NULL, percent_to_jita_sell INT DEFAULT 100, note VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, INDEX IDX_C70F6927220D33CE (fulfiller_id), INDEX IDX_C70F69276C755722 (buyer_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('CREATE TABLE sell_order (id INT AUTO_INCREMENT NOT NULL, seller_id INT NOT NULL, buyer_id INT DEFAULT NULL, item VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, amount INT NOT NULL, fullfilled TINYINT(1) DEFAULT NULL, percent_to_jita_buy INT DEFAULT 100, note VARCHAR(255) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_unicode_ci`, INDEX IDX_ED81DFC48DE820D9 (seller_id), INDEX IDX_ED81DFC46C755722 (buyer_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('ALTER TABLE buy_order ADD CONSTRAINT FK_C70F69276C755722 FOREIGN KEY (buyer_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE buy_order ADD CONSTRAINT FK_C70F6927220D33CE FOREIGN KEY (fulfiller_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE sell_order ADD CONSTRAINT FK_ED81DFC48DE820D9 FOREIGN KEY (seller_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sell_order ADD CONSTRAINT FK_ED81DFC46C755722 FOREIGN KEY (buyer_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE corp_order_items DROP FOREIGN KEY FK_1C9916138D9F6D38');
        $this->addSql('ALTER TABLE corp_order_items DROP FOREIGN KEY FK_1C991613220D33CE');
        $this->addSql('ALTER TABLE corp_orders DROP FOREIGN KEY FK_7128A1F8A76ED395');
        $this->addSql('DROP TABLE corp_order_items');
        $this->addSql('DROP TABLE corp_orders');
        $this->addSql('CREATE INDEX IDX_EVE_CHARACTER_IS_ONLINE ON eve_character (is_online)');
        $this->addSql('ALTER TABLE defense_doctrine_fit RENAME INDEX idx_ad534ca3b03a8386 TO IDX_DEFENSE_DOCTRINE_FIT_CREATED_BY');
    }
}
