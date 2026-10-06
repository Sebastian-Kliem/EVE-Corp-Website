<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\Type\EncryptedTextType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261006110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Encrypt existing app settings (webhook URLs, API keys) at rest';
    }

    public function up(Schema $schema): void
    {
        $this->_convertSettings(true);
    }

    public function down(Schema $schema): void
    {
        $this->_convertSettings(false);
    }

    private function _convertSettings(bool $encrypt): void
    {
        $cipher = EncryptedTextType::getCipher();
        $this->abortIf(!$cipher->isConfigured(), 'ESI_TOKEN_KEY is missing or invalid; set it in .env.local before migrating.');

        $rows = $this->connection->fetchAllAssociative('SELECT setting_key, setting_value FROM app_setting WHERE setting_value IS NOT NULL');
        foreach ($rows as $row) {
            $value = $encrypt ? $cipher->encrypt($row['setting_value']) : $cipher->decrypt($row['setting_value']);

            $this->addSql(
                'UPDATE app_setting SET setting_value = :value WHERE setting_key = :settingKey',
                ['value' => $value, 'settingKey' => $row['setting_key']]
            );
        }
    }
}
