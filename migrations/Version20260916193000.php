<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260916193000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add is_online, last_online_check, last_login, and last_logout to eve_character';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE eve_character 
            ADD is_online TINYINT(1) DEFAULT 0 NOT NULL, 
            ADD last_online_check DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', 
            ADD last_login DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\', 
            ADD last_logout DATETIME DEFAULT NULL COMMENT \'(DC2Type:datetime_immutable)\'');
        $this->addSql('CREATE INDEX IDX_EVE_CHARACTER_IS_ONLINE ON eve_character (is_online)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_EVE_CHARACTER_IS_ONLINE ON eve_character');
        $this->addSql('ALTER TABLE eve_character 
            DROP is_online, 
            DROP last_online_check, 
            DROP last_login, 
            DROP last_logout');
    }
}
