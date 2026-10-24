<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261024134233 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index market_history by date for retention cleanup';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX IDX_2E2C98CBAA9E377A ON market_history (date)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_2E2C98CBAA9E377A ON market_history');
    }
}
