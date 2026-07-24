<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260607082150 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE corp_asset_visibility (id INT AUTO_INCREMENT NOT NULL, location_id BIGINT NOT NULL, location_flag VARCHAR(100) NOT NULL, is_visible TINYINT(1) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE eve_corporation_asset (id INT AUTO_INCREMENT NOT NULL, corporation_id BIGINT NOT NULL, item_id BIGINT NOT NULL, type_id INT NOT NULL, quantity BIGINT NOT NULL, location_id BIGINT NOT NULL, location_type VARCHAR(100) NOT NULL, location_flag VARCHAR(100) NOT NULL, is_singleton TINYINT(1) NOT NULL, is_blueprint_copy TINYINT(1) DEFAULT NULL, custom_name VARCHAR(255) DEFAULT NULL, INDEX IDX_9404949AB2685369 (corporation_id), INDEX IDX_9404949AC54C8C93 (type_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE eve_character ADD last_corp_assets_update DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE eve_character_asset ADD custom_name VARCHAR(255) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('DROP TABLE corp_asset_visibility');
        $this->addSql('DROP TABLE eve_corporation_asset');
        $this->addSql('ALTER TABLE eve_character_asset DROP custom_name');
        $this->addSql('ALTER TABLE eve_character DROP last_corp_assets_update');
    }
}
