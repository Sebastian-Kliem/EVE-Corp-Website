<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260930192519 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE corp_orders ADD fulfiller_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE corp_orders ADD CONSTRAINT FK_7128A1F8220D33CE FOREIGN KEY (fulfiller_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_7128A1F8220D33CE ON corp_orders (fulfiller_id)');
        $this->addSql('UPDATE corp_orders o JOIN corp_order_items i ON i.order_id = o.id SET o.fulfiller_id = i.fulfiller_id WHERE o.fulfiller_id IS NULL AND i.fulfiller_id IS NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE corp_orders DROP FOREIGN KEY FK_7128A1F8220D33CE');
        $this->addSql('DROP INDEX IDX_7128A1F8220D33CE ON corp_orders');
        $this->addSql('ALTER TABLE corp_orders DROP fulfiller_id');
    }
}
