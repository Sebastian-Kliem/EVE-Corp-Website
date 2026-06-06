<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260606181154 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE eve_character ADD user_id INT DEFAULT NULL');
        $this->addSql('UPDATE eve_character SET user_id = (SELECT id FROM `user` LIMIT 1) WHERE user_id IS NULL');
        $this->addSql('ALTER TABLE eve_character MODIFY user_id INT NOT NULL');
        $this->addSql('ALTER TABLE eve_character ADD CONSTRAINT FK_CB3048FEA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_CB3048FEA76ED395 ON eve_character (user_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE eve_character DROP FOREIGN KEY FK_CB3048FEA76ED395');
        $this->addSql('DROP INDEX IDX_CB3048FEA76ED395 ON eve_character');
        $this->addSql('ALTER TABLE eve_character DROP user_id');
    }
}
