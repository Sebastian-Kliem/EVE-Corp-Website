<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260529142146 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE sell_order ADD seller_id INT NOT NULL, ADD buyer_id INT DEFAULT NULL, DROP seller_charname, DROP buyer_charname');
        $this->addSql('ALTER TABLE sell_order ADD CONSTRAINT FK_ED81DFC48DE820D9 FOREIGN KEY (seller_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE sell_order ADD CONSTRAINT FK_ED81DFC46C755722 FOREIGN KEY (buyer_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_ED81DFC48DE820D9 ON sell_order (seller_id)');
        $this->addSql('CREATE INDEX IDX_ED81DFC46C755722 ON sell_order (buyer_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE sell_order DROP FOREIGN KEY FK_ED81DFC48DE820D9');
        $this->addSql('ALTER TABLE sell_order DROP FOREIGN KEY FK_ED81DFC46C755722');
        $this->addSql('DROP INDEX IDX_ED81DFC48DE820D9 ON sell_order');
        $this->addSql('DROP INDEX IDX_ED81DFC46C755722 ON sell_order');
        $this->addSql('ALTER TABLE sell_order ADD seller_charname VARCHAR(255) NOT NULL, ADD buyer_charname VARCHAR(255) DEFAULT NULL, DROP seller_id, DROP buyer_id');
    }
}
