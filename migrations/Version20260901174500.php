<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260901174500 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create app_setting table for dynamic system configuration';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE app_setting (setting_key VARCHAR(100) NOT NULL, setting_value LONGTEXT DEFAULT NULL, updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(setting_key)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_setting');
    }
}
