<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260617200600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE eve_character_asset_change (id INT AUTO_INCREMENT NOT NULL, character_id BIGINT NOT NULL, type_id INT NOT NULL, quantity BIGINT NOT NULL, logged_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_8C0339F31136BE75 (character_id), INDEX IDX_8C0339F3C54C8C93 (type_id), INDEX IDX_8C0339F3A78D87A7 (logged_at), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE tracking_list (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, description VARCHAR(255) DEFAULT NULL, is_global TINYINT(1) NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE tracking_list_item (id INT AUTO_INCREMENT NOT NULL, tracking_list_id INT NOT NULL, type_id INT NOT NULL, INDEX IDX_D7F58A78426C97B3 (tracking_list_id), UNIQUE INDEX uniq_list_item (tracking_list_id, type_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE eve_character_asset_change ADD CONSTRAINT FK_8C0339F31136BE75 FOREIGN KEY (character_id) REFERENCES eve_character (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE tracking_list_item ADD CONSTRAINT FK_D7F58A78426C97B3 FOREIGN KEY (tracking_list_id) REFERENCES tracking_list (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE eve_character_asset_change DROP FOREIGN KEY FK_8C0339F31136BE75');
        $this->addSql('ALTER TABLE tracking_list_item DROP FOREIGN KEY FK_D7F58A78426C97B3');
        $this->addSql('DROP TABLE eve_character_asset_change');
        $this->addSql('DROP TABLE tracking_list');
        $this->addSql('DROP TABLE tracking_list_item');
    }
}
