<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260529141650 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE buy_order ADD buyer_id INT NOT NULL, ADD fulfiller_id INT DEFAULT NULL, DROP charname, DROP fullfill_by_charname');
        $this->addSql('ALTER TABLE buy_order ADD CONSTRAINT FK_C70F69276C755722 FOREIGN KEY (buyer_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE buy_order ADD CONSTRAINT FK_C70F6927220D33CE FOREIGN KEY (fulfiller_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_C70F69276C755722 ON buy_order (buyer_id)');
        $this->addSql('CREATE INDEX IDX_C70F6927220D33CE ON buy_order (fulfiller_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE buy_order DROP FOREIGN KEY FK_C70F69276C755722');
        $this->addSql('ALTER TABLE buy_order DROP FOREIGN KEY FK_C70F6927220D33CE');
        $this->addSql('DROP INDEX IDX_C70F69276C755722 ON buy_order');
        $this->addSql('DROP INDEX IDX_C70F6927220D33CE ON buy_order');
        $this->addSql('ALTER TABLE buy_order ADD charname VARCHAR(255) NOT NULL, ADD fullfill_by_charname VARCHAR(255) DEFAULT NULL, DROP buyer_id, DROP fulfiller_id');
    }
}
