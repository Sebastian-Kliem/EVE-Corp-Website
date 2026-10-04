<?php

namespace App\Security;

/**
 * Creates one-time passwords that admins pass on to users, e.g. "Xk7m-Qp3R-a9Tz-Hn4w".
 */
class TemporaryPasswordGenerator
{
    // Without look-alike characters (0/O, 1/l/I) so the password can be read out and typed reliably
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
    private const GROUP_COUNT = 4;
    private const GROUP_LENGTH = 4;

    public function generate(): string
    {
        $groups = [];
        for ($groupIndex = 0; $groupIndex < self::GROUP_COUNT; $groupIndex++) {
            $groups[] = $this->_generateGroup();
        }

        return implode('-', $groups);
    }

    private function _generateGroup(): string
    {
        $group = '';
        $maxIndex = strlen(self::ALPHABET) - 1;
        for ($charIndex = 0; $charIndex < self::GROUP_LENGTH; $charIndex++) {
            $group .= self::ALPHABET[random_int(0, $maxIndex)];
        }

        return $group;
    }
}
