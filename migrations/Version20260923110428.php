<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260923110428 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add settings JSON column to user table for user preferences';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD settings JSON DEFAULT \'{}\' NOT NULL COMMENT \'(DC2Type:json)\'');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `user` DROP settings');
    }
}
