<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260923084000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Swap Jita calculation reference: buy_order uses Jita-Sell, sell_order uses Jita-Buy';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE buy_order CHANGE percent_to_jita_buy percent_to_jita_sell INT DEFAULT 100');
        $this->addSql('ALTER TABLE sell_order CHANGE percent_to_jita_sell percent_to_jita_buy INT DEFAULT 100');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE buy_order CHANGE percent_to_jita_sell percent_to_jita_buy INT DEFAULT 100');
        $this->addSql('ALTER TABLE sell_order CHANGE percent_to_jita_buy percent_to_jita_sell INT DEFAULT 100');
    }
}
