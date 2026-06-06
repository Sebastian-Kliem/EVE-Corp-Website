<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260606185550 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE cron_job (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, command VARCHAR(255) NOT NULL, cron_expression VARCHAR(255) NOT NULL, is_active TINYINT(1) NOT NULL, last_run_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', next_run_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_execution_time DOUBLE PRECISION DEFAULT NULL, last_status VARCHAR(50) DEFAULT NULL, last_error LONGTEXT DEFAULT NULL, UNIQUE INDEX UNIQ_8E6EB8E8ECAEAD4 (command), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE eve_character_asset (id INT AUTO_INCREMENT NOT NULL, character_id BIGINT NOT NULL, item_id BIGINT NOT NULL, type_id INT NOT NULL, quantity BIGINT NOT NULL, location_id BIGINT NOT NULL, location_type VARCHAR(100) NOT NULL, location_flag VARCHAR(100) NOT NULL, is_singleton TINYINT(1) NOT NULL, is_blueprint_copy TINYINT(1) DEFAULT NULL, INDEX IDX_A25D5C211136BE75 (character_id), INDEX IDX_A25D5C21C54C8C93 (type_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE eve_character_asset ADD CONSTRAINT FK_A25D5C211136BE75 FOREIGN KEY (character_id) REFERENCES eve_character (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE eve_character ADD wallet_balance NUMERIC(20, 2) DEFAULT NULL, ADD last_wallet_update DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', ADD last_assets_update DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE eve_character_asset DROP FOREIGN KEY FK_A25D5C211136BE75');
        $this->addSql('DROP TABLE cron_job');
        $this->addSql('DROP TABLE eve_character_asset');
        $this->addSql('ALTER TABLE eve_character DROP wallet_balance, DROP last_wallet_update, DROP last_assets_update');
    }
}
