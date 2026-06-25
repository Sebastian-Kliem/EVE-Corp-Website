<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260625185136 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE performance_manual_entry (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, character_id BIGINT DEFAULT NULL, category VARCHAR(50) NOT NULL, description VARCHAR(255) NOT NULL, amount NUMERIC(20, 2) NOT NULL, date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_9359B101136BE75 (character_id), INDEX IDX_9359B10A76ED395 (user_id), INDEX IDX_9359B10AA9E377A (date), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE performance_manual_entry ADD CONSTRAINT FK_9359B10A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE performance_manual_entry ADD CONSTRAINT FK_9359B101136BE75 FOREIGN KEY (character_id) REFERENCES eve_character (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE performance_manual_entry DROP FOREIGN KEY FK_9359B10A76ED395');
        $this->addSql('ALTER TABLE performance_manual_entry DROP FOREIGN KEY FK_9359B101136BE75');
        $this->addSql('DROP TABLE performance_manual_entry');
    }
}
