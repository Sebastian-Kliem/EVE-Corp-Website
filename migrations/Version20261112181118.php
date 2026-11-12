<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261112181118 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add complete-only flag and fixed price to corp orders';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE corp_orders ADD complete_only TINYINT(1) DEFAULT 0 NOT NULL, ADD custom_price NUMERIC(20, 2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE corp_orders DROP complete_only, DROP custom_price');
    }
}
