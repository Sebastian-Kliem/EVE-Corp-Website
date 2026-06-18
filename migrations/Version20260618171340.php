<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260618171340 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tracking_list ADD user_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE tracking_list ADD CONSTRAINT FK_16E76ADDA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('CREATE INDEX IDX_16E76ADDA76ED395 ON tracking_list (user_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tracking_list DROP FOREIGN KEY FK_16E76ADDA76ED395');
        $this->addSql('DROP INDEX IDX_16E76ADDA76ED395 ON tracking_list');
        $this->addSql('ALTER TABLE tracking_list DROP user_id');
    }
}
