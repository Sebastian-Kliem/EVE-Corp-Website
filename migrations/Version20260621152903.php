<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260621152903 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE eve_character_industry_job (job_id BIGINT NOT NULL, character_id BIGINT NOT NULL, installer_id INT NOT NULL, blueprint_id BIGINT NOT NULL, blueprint_type_id INT NOT NULL, blueprint_location_id BIGINT NOT NULL, output_location_id BIGINT NOT NULL, product_type_id INT DEFAULT NULL, activity_id INT NOT NULL, runs INT NOT NULL, successful_runs INT DEFAULT NULL, duration INT NOT NULL, start_date DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', end_date DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', pause_date DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', completed_date DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', completed_character_id INT DEFAULT NULL, status VARCHAR(50) NOT NULL, cost NUMERIC(20, 2) DEFAULT NULL, probability DOUBLE PRECISION DEFAULT NULL, licence_limit INT DEFAULT NULL, INDEX IDX_95EA2DB71136BE75 (character_id), INDEX IDX_95EA2DB77B00651C (status), INDEX IDX_95EA2DB7845CBB3E (end_date), PRIMARY KEY(job_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE eve_character_industry_job ADD CONSTRAINT FK_95EA2DB71136BE75 FOREIGN KEY (character_id) REFERENCES eve_character (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE eve_character ADD last_industry_jobs_update DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE eve_character_industry_job DROP FOREIGN KEY FK_95EA2DB71136BE75');
        $this->addSql('DROP TABLE eve_character_industry_job');
        $this->addSql('ALTER TABLE eve_character DROP last_industry_jobs_update');
    }
}
