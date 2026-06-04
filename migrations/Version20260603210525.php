<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260603210525 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql("UPDATE buy_order SET amount = '1' WHERE amount NOT REGEXP '^[0-9]+$' OR amount IS NULL");
        $this->addSql("UPDATE sell_order SET amount = '1' WHERE amount NOT REGEXP '^[0-9]+$' OR amount IS NULL");
        $this->addSql('ALTER TABLE buy_order CHANGE amount amount INT NOT NULL');
        $this->addSql('ALTER TABLE sell_order CHANGE amount amount INT NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE buy_order CHANGE amount amount VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE sell_order CHANGE amount amount VARCHAR(255) NOT NULL');
    }
}
