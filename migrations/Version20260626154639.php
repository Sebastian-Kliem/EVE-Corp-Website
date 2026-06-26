<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260626154639 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE eve_character ADD skills JSON DEFAULT \'[]\' NOT NULL COMMENT \'(DC2Type:json)\', ADD skill_queue JSON DEFAULT \'[]\' NOT NULL COMMENT \'(DC2Type:json)\', ADD attributes JSON DEFAULT \'[]\' NOT NULL COMMENT \'(DC2Type:json)\', ADD implants JSON DEFAULT \'[]\' NOT NULL COMMENT \'(DC2Type:json)\'');
        $this->addSql('UPDATE eve_character SET skills = \'[]\' WHERE skills = \'\' OR skills IS NULL');
        $this->addSql('UPDATE eve_character SET skill_queue = \'[]\' WHERE skill_queue = \'\' OR skill_queue IS NULL');
        $this->addSql('UPDATE eve_character SET attributes = \'[]\' WHERE attributes = \'\' OR attributes IS NULL');
        $this->addSql('UPDATE eve_character SET implants = \'[]\' WHERE implants = \'\' OR implants IS NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE eve_character DROP skills, DROP skill_queue, DROP attributes, DROP implants');
    }
}
