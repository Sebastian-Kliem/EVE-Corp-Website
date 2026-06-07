<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260607120059 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE corp_asset_visibility_user (corp_asset_visibility_id INT NOT NULL, user_id INT NOT NULL, INDEX IDX_EB3956CBB888FD7B (corp_asset_visibility_id), INDEX IDX_EB3956CBA76ED395 (user_id), PRIMARY KEY(corp_asset_visibility_id, user_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE corp_asset_visibility_user ADD CONSTRAINT FK_EB3956CBB888FD7B FOREIGN KEY (corp_asset_visibility_id) REFERENCES corp_asset_visibility (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE corp_asset_visibility_user ADD CONSTRAINT FK_EB3956CBA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE corp_asset_visibility_user DROP FOREIGN KEY FK_EB3956CBB888FD7B');
        $this->addSql('ALTER TABLE corp_asset_visibility_user DROP FOREIGN KEY FK_EB3956CBA76ED395');
        $this->addSql('DROP TABLE corp_asset_visibility_user');
    }
}
