<?php

namespace App\Tests\Security;

use App\Security\TemporaryPasswordGenerator;
use PHPUnit\Framework\TestCase;

class TemporaryPasswordGeneratorTest extends TestCase
{
    public function testPasswordHasFourGroupsWithoutLookAlikeCharacters(): void
    {
        $generator = new TemporaryPasswordGenerator();

        $passwords = [];
        for ($run = 0; $run < 50; $run++) {
            $password = $generator->generate();
            $this->assertMatchesRegularExpression('/^[ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz2-9]{4}(-[ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz2-9]{4}){3}$/', $password);
            $passwords[] = $password;
        }

        $this->assertCount(50, array_unique($passwords));
    }
}
