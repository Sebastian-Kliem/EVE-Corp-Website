<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261024115158 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add market prices, market history and public contracts for the trading tool';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE market_history (region_id INT NOT NULL, type_id INT NOT NULL, date DATE NOT NULL COMMENT \'(DC2Type:date_key)\', average NUMERIC(20, 2) NOT NULL, highest NUMERIC(20, 2) NOT NULL, lowest NUMERIC(20, 2) NOT NULL, volume BIGINT NOT NULL, order_count BIGINT NOT NULL, INDEX IDX_2E2C98CBC54C8C93AA9E377A (type_id, date), PRIMARY KEY(region_id, type_id, date)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE market_price (id INT AUTO_INCREMENT NOT NULL, scope VARCHAR(16) NOT NULL, location_id BIGINT NOT NULL, region_id INT NOT NULL, type_id INT NOT NULL, buy_best NUMERIC(20, 2) DEFAULT NULL, buy_percentile NUMERIC(20, 2) DEFAULT NULL, buy_volume BIGINT DEFAULT 0 NOT NULL, buy_order_count INT DEFAULT 0 NOT NULL, buy_depth_volume BIGINT DEFAULT 0 NOT NULL, buy_depth_order_count INT DEFAULT 0 NOT NULL, buy_updated1h INT DEFAULT 0 NOT NULL, buy_updated24h INT DEFAULT 0 NOT NULL, buy_best_issued_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', sell_best NUMERIC(20, 2) DEFAULT NULL, sell_percentile NUMERIC(20, 2) DEFAULT NULL, sell_volume BIGINT DEFAULT 0 NOT NULL, sell_order_count INT DEFAULT 0 NOT NULL, sell_depth_volume BIGINT DEFAULT 0 NOT NULL, sell_depth_order_count INT DEFAULT 0 NOT NULL, sell_updated1h INT DEFAULT 0 NOT NULL, sell_updated24h INT DEFAULT 0 NOT NULL, sell_best_issued_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', fetched_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_1E69DAC98260155C54C8C93 (region_id, type_id), INDEX IDX_1E69DACC54C8C93 (type_id), UNIQUE INDEX uniq_market_price_location_type (scope, location_id, type_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE public_contract (contract_id BIGINT NOT NULL, region_id INT NOT NULL, start_location_id BIGINT NOT NULL, issuer_id BIGINT NOT NULL, issuer_corporation_id BIGINT NOT NULL, for_corporation TINYINT(1) NOT NULL, price NUMERIC(20, 2) NOT NULL, volume NUMERIC(20, 2) NOT NULL, title VARCHAR(255) DEFAULT NULL, date_issued DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', date_expired DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', first_seen_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', last_seen_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', gone_since DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', items_fetched_at DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', INDEX IDX_CCBB24A6982601557B413BEC (region_id, gone_since), INDEX IDX_CCBB24A620A8EAB6 (date_expired), PRIMARY KEY(contract_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE public_contract_item (id INT AUTO_INCREMENT NOT NULL, contract_id BIGINT NOT NULL, record_id BIGINT NOT NULL, type_id INT NOT NULL, quantity BIGINT NOT NULL, is_included TINYINT(1) NOT NULL, is_singleton TINYINT(1) NOT NULL, is_blueprint_copy TINYINT(1) DEFAULT NULL, material_efficiency INT DEFAULT NULL, time_efficiency INT DEFAULT NULL, runs INT DEFAULT NULL, item_id BIGINT DEFAULT NULL, INDEX IDX_3C4D8EE9C54C8C93 (type_id), UNIQUE INDEX uniq_public_contract_item_record (contract_id, record_id), INDEX IDX_3C4D8EE92576E0FD (contract_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE public_contract_item ADD CONSTRAINT FK_3C4D8EE92576E0FD FOREIGN KEY (contract_id) REFERENCES public_contract (contract_id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE public_contract_item DROP FOREIGN KEY FK_3C4D8EE92576E0FD');
        $this->addSql('DROP TABLE market_history');
        $this->addSql('DROP TABLE market_price');
        $this->addSql('DROP TABLE public_contract');
        $this->addSql('DROP TABLE public_contract_item');
    }
}
