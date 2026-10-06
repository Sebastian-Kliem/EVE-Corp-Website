<?php

namespace App\Tests\Controller;

use App\Controller\LegacyRedirectController;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

class LegacyRedirectControllerTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testEveryLegacyPathHitsTheRedirectAndHasAnExistingTarget(): void
    {
        self::bootKernel();
        /** @var RouterInterface $router */
        $router = static::getContainer()->get('router');
        $redirects = (new \ReflectionClassConstant(LegacyRedirectController::class, 'REDIRECTS'))->getValue();

        foreach ($redirects as $legacyPath => $targetRoute) {
            $match = $router->match($legacyPath);
            $this->assertSame('app_legacy_redirect', $match['_route'], sprintf('"%s" is not redirected', $legacyPath));
            $this->assertNotNull($router->getRouteCollection()->get($targetRoute), sprintf('Target route "%s" does not exist', $targetRoute));
        }
    }

    public function testLegacyCharacterPagesAreRedirected(): void
    {
        self::bootKernel();
        /** @var RouterInterface $router */
        $router = static::getContainer()->get('router');

        $this->assertSame('app_legacy_character_redirect', $router->match('/personal/eve-character/123/details')['_route']);
        $this->assertSame('app_legacy_character_redirect', $router->match('/personal/eve-character/123/assets')['_route']);
    }
}
