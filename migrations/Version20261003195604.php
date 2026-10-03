<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use App\Doctrine\Type\EncryptedTextType;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261003195604 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Encrypt existing ESI access and refresh tokens at rest';
    }

    public function up(Schema $schema): void
    {
        $this->_convertTokens(true);
    }

    public function down(Schema $schema): void
    {
        $this->_convertTokens(false);
    }

    private function _convertTokens(bool $encrypt): void
    {
        $cipher = EncryptedTextType::getCipher();
        $this->abortIf(!$cipher->isConfigured(), 'ESI_TOKEN_KEY is missing or invalid; set it in .env.local before migrating.');

        $rows = $this->connection->fetchAllAssociative('SELECT id, access_token, refresh_token FROM eve_character');
        foreach ($rows as $row) {
            $accessToken = $encrypt ? $cipher->encrypt($row['access_token']) : $cipher->decrypt($row['access_token']);
            $refreshToken = $encrypt ? $cipher->encrypt($row['refresh_token']) : $cipher->decrypt($row['refresh_token']);

            $this->addSql(
                'UPDATE eve_character SET access_token = :accessToken, refresh_token = :refreshToken WHERE id = :id',
                ['accessToken' => $accessToken, 'refreshToken' => $refreshToken, 'id' => $row['id']]
            );
        }
    }
}
