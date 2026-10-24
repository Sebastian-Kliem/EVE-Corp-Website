<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261024173317 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add market type stats and trading order checks';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE market_type_stats (region_id INT NOT NULL, type_id INT NOT NULL, avg_volume7d DOUBLE PRECISION NOT NULL, avg_volume30d DOUBLE PRECISION NOT NULL, avg_price7d NUMERIC(20, 2) DEFAULT NULL, avg_price30d NUMERIC(20, 2) DEFAULT NULL, avg_lowest7d NUMERIC(20, 2) DEFAULT NULL, avg_highest7d NUMERIC(20, 2) DEFAULT NULL, traded_days30d SMALLINT NOT NULL, price_volatility30d DOUBLE PRECISION DEFAULT NULL, computed_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', PRIMARY KEY(region_id, type_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE trading_order_check (order_id BIGINT NOT NULL, character_id BIGINT NOT NULL, location_id BIGINT NOT NULL, type_id INT NOT NULL, is_buy TINYINT(1) NOT NULL, in_book TINYINT(1) NOT NULL, current_price NUMERIC(20, 2) DEFAULT NULL, current_volume_remain BIGINT DEFAULT NULL, best_competitor_price NUMERIC(20, 2) DEFAULT NULL, competitors_ahead INT NOT NULL, competitor_volume_ahead BIGINT NOT NULL, checked_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_4D33F611136BE75 (character_id), INDEX IDX_4D33F6164D218E (location_id), PRIMARY KEY(order_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE market_type_stats');
        $this->addSql('DROP TABLE trading_order_check');
    }
}
