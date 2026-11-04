<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261104103610 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Delivery status per corp order item, drop the unused contract link of corp orders';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE corp_order_items ADD delivery_status VARCHAR(20) DEFAULT NULL, ADD delivered_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('ALTER TABLE corp_orders DROP contract_id');

        // Items without own supplier belong to the order's supplier
        $this->addSql('UPDATE corp_order_items i JOIN corp_orders o ON o.id = i.order_id SET i.fulfiller_id = o.fulfiller_id WHERE i.is_fulfilled = 1 AND i.fulfiller_id IS NULL');
        $this->addSql("UPDATE corp_order_items SET delivery_status = 'ACCEPTED' WHERE is_fulfilled = 1");
        // Archived orders were received completely
        $this->addSql("UPDATE corp_order_items i JOIN corp_orders o ON o.id = i.order_id SET i.delivery_status = 'RECEIVED', i.delivered_at = COALESCE(o.fulfilled_at, i.fulfilled_at) WHERE i.is_fulfilled = 1 AND o.status = 'FULFILLED'");
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE corp_order_items DROP delivery_status, DROP delivered_at');
        $this->addSql('ALTER TABLE corp_orders ADD contract_id BIGINT DEFAULT NULL');
    }
}
