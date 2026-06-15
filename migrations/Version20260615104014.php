<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260615104014 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE eve_character_asset ADD material_efficiency INT DEFAULT NULL, ADD time_efficiency INT DEFAULT NULL, ADD runs INT DEFAULT NULL');
        $this->addSql('ALTER TABLE eve_corporation_asset ADD material_efficiency INT DEFAULT NULL, ADD time_efficiency INT DEFAULT NULL, ADD runs INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE eve_character_asset DROP material_efficiency, DROP time_efficiency, DROP runs');
        $this->addSql('ALTER TABLE eve_corporation_asset DROP material_efficiency, DROP time_efficiency, DROP runs');
    }
}
