<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260606150843 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE eve_account (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, name VARCHAR(255) NOT NULL, is_omega TINYINT(1) NOT NULL, group_name VARCHAR(255) DEFAULT NULL, INDEX IDX_CBFBD212A76ED395 (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE eve_character (id BIGINT NOT NULL, account_id INT DEFAULT NULL, name VARCHAR(255) NOT NULL, access_token LONGTEXT DEFAULT NULL, refresh_token LONGTEXT DEFAULT NULL, token_expires_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', owner_hash VARCHAR(255) DEFAULT NULL, corporation_id INT DEFAULT NULL, alliance_id INT DEFAULT NULL, INDEX IDX_CB3048FE9B6B5FBA (account_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE eve_account ADD CONSTRAINT FK_CBFBD212A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id)');
        $this->addSql('ALTER TABLE eve_character ADD CONSTRAINT FK_CB3048FE9B6B5FBA FOREIGN KEY (account_id) REFERENCES eve_account (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE eve_account DROP FOREIGN KEY FK_CBFBD212A76ED395');
        $this->addSql('ALTER TABLE eve_character DROP FOREIGN KEY FK_CB3048FE9B6B5FBA');
        $this->addSql('DROP TABLE eve_account');
        $this->addSql('DROP TABLE eve_character');
    }
}
