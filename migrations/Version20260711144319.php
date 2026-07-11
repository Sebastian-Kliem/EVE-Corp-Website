<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260711144319 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE performance_exclusion (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, date DATE NOT NULL COMMENT \'(DC2Type:date_immutable)\', category VARCHAR(100) NOT NULL, type_name VARCHAR(255) NOT NULL, character_name VARCHAR(255) NOT NULL, amount NUMERIC(20, 2) NOT NULL, INDEX IDX_E3741424A76ED395 (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE performance_exclusion ADD CONSTRAINT FK_E3741424A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE performance_exclusion DROP FOREIGN KEY FK_E3741424A76ED395');
        $this->addSql('DROP TABLE performance_exclusion');
    }
}
