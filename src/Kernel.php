<?php

namespace App;

use App\Doctrine\Type\EncryptedTextType;
use App\Security\TokenCipher;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function __construct(string $environment, bool $debug)
    {
        date_default_timezone_set('UTC');
        parent::__construct($environment, $debug);
    }

    public function boot(): void
    {
        parent::boot();

        EncryptedTextType::setCipher($this->getContainer()->get(TokenCipher::class));
    }
}
