<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20261028190603 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add wormhole site aliases for the signature calculator';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE wormhole_site_alias (id INT AUTO_INCREMENT NOT NULL, site_id INT NOT NULL, created_by_id INT DEFAULT NULL, alias VARCHAR(128) NOT NULL, created_at DATETIME NOT NULL COMMENT \'(DC2Type:datetime_immutable)\', UNIQUE INDEX UNIQ_27732E43E16C6B94 (alias), INDEX IDX_27732E43F6BD1646 (site_id), INDEX IDX_27732E43B03A8386 (created_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE wormhole_site_alias ADD CONSTRAINT FK_27732E43F6BD1646 FOREIGN KEY (site_id) REFERENCES wormhole_site (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE wormhole_site_alias ADD CONSTRAINT FK_27732E43B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE wormhole_site_alias DROP FOREIGN KEY FK_27732E43F6BD1646');
        $this->addSql('ALTER TABLE wormhole_site_alias DROP FOREIGN KEY FK_27732E43B03A8386');
        $this->addSql('DROP TABLE wormhole_site_alias');
    }
}
