<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261127120743 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add fitting library, in-game copies and fleet compositions';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE fitting (id INT AUTO_INCREMENT NOT NULL, created_by_id INT DEFAULT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, ship_type_id INT NOT NULL, items JSON NOT NULL COMMENT \'(DC2Type:json)\', content_hash VARCHAR(64) NOT NULL, tags JSON NOT NULL COMMENT \'(DC2Type:json)\', corp_saved TINYINT(1) DEFAULT 0 NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_fitting_content_hash (content_hash), INDEX IDX_DD50CB8AB03A8386 (created_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE fitting_ingame_copy (id INT AUTO_INCREMENT NOT NULL, fitting_id INT NOT NULL, character_id BIGINT NOT NULL, esi_fitting_id INT NOT NULL, ingame_name VARCHAR(255) NOT NULL, content_hash VARCHAR(64) NOT NULL, synced_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX uniq_fitting_copy_character_esi (character_id, esi_fitting_id), INDEX IDX_C50F50FFC98604CA (fitting_id), INDEX IDX_C50F50FF1136BE75 (character_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE fleet_composition (id INT AUTO_INCREMENT NOT NULL, created_by_id INT DEFAULT NULL, name VARCHAR(255) NOT NULL, description LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_A9C0741CB03A8386 (created_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE fleet_composition_entry (id INT AUTO_INCREMENT NOT NULL, fleet_id INT NOT NULL, fitting_id INT NOT NULL, role VARCHAR(100) DEFAULT NULL, quantity INT DEFAULT 1 NOT NULL, notes LONGTEXT DEFAULT NULL, sort_order INT DEFAULT 0 NOT NULL, INDEX IDX_79BB7DEA4B061DF9 (fleet_id), INDEX IDX_79BB7DEAC98604CA (fitting_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE fitting ADD CONSTRAINT FK_DD50CB8AB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE fitting_ingame_copy ADD CONSTRAINT FK_C50F50FFC98604CA FOREIGN KEY (fitting_id) REFERENCES fitting (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE fitting_ingame_copy ADD CONSTRAINT FK_C50F50FF1136BE75 FOREIGN KEY (character_id) REFERENCES eve_character (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE fleet_composition ADD CONSTRAINT FK_A9C0741CB03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE fleet_composition_entry ADD CONSTRAINT FK_79BB7DEA4B061DF9 FOREIGN KEY (fleet_id) REFERENCES fleet_composition (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE fleet_composition_entry ADD CONSTRAINT FK_79BB7DEAC98604CA FOREIGN KEY (fitting_id) REFERENCES fitting (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE fitting DROP FOREIGN KEY FK_DD50CB8AB03A8386');
        $this->addSql('ALTER TABLE fitting_ingame_copy DROP FOREIGN KEY FK_C50F50FFC98604CA');
        $this->addSql('ALTER TABLE fitting_ingame_copy DROP FOREIGN KEY FK_C50F50FF1136BE75');
        $this->addSql('ALTER TABLE fleet_composition DROP FOREIGN KEY FK_A9C0741CB03A8386');
        $this->addSql('ALTER TABLE fleet_composition_entry DROP FOREIGN KEY FK_79BB7DEA4B061DF9');
        $this->addSql('ALTER TABLE fleet_composition_entry DROP FOREIGN KEY FK_79BB7DEAC98604CA');
        $this->addSql('DROP TABLE fitting');
        $this->addSql('DROP TABLE fitting_ingame_copy');
        $this->addSql('DROP TABLE fleet_composition');
        $this->addSql('DROP TABLE fleet_composition_entry');
    }
}
