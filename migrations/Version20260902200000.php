<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260902200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create defense_doctrine_fit table for corporation defense doctrines';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE defense_doctrine_fit (
            id INT AUTO_INCREMENT NOT NULL,
            title VARCHAR(255) NOT NULL,
            ship_name VARCHAR(255) NOT NULL,
            ship_type_id INT DEFAULT NULL,
            role VARCHAR(100) DEFAULT NULL,
            eft LONGTEXT NOT NULL,
            notes LONGTEXT DEFAULT NULL,
            sort_order INT DEFAULT 0 NOT NULL,
            created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            updated_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\',
            created_by_id INT DEFAULT NULL,
            INDEX IDX_DEFENSE_DOCTRINE_FIT_CREATED_BY (created_by_id),
            PRIMARY KEY(id)
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');

        $this->addSql('ALTER TABLE defense_doctrine_fit ADD CONSTRAINT FK_DEFENSE_DOCTRINE_FIT_CREATED_BY FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE defense_doctrine_fit DROP FOREIGN KEY FK_DEFENSE_DOCTRINE_FIT_CREATED_BY');
        $this->addSql('DROP TABLE defense_doctrine_fit');
    }
}
