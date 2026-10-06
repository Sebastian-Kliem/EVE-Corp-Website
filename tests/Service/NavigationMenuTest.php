<?php

namespace App\Tests\Service;

use App\Kernel;
use App\Service\Navigation\NavigationMenu;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

class NavigationMenuTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testMemberSeesNoAdminCategoryAndNoCeoItems(): void
    {
        $menu = $this->_createMenu(['ROLE_MEMBER'], 'home');

        $labels = $this->_getCategoryLabels($menu->getCategories(NavigationMenu::PLACEMENT_MAIN));

        $this->assertSame(['Charaktere', 'Industrie', 'Wallet & Assets', 'Corp', 'Allgemein'], $labels);
    }

    public function testOfficerSeesAdminCategoryWithoutCeoItems(): void
    {
        $menu = $this->_createMenu(['ROLE_MEMBER', 'ROLE_OFFICER'], 'home');

        $adminCategory = $this->_findCategory($menu->getCategories(NavigationMenu::PLACEMENT_MAIN), 'Admin-Panel');

        $this->assertCount(1, $adminCategory['items']);
        $this->assertSame('app_admin_users', $adminCategory['items'][0]['route']);
    }

    public function testSubpageHighlightsParentItemAndBuildsThreeLevelBreadcrumb(): void
    {
        $menu = $this->_createMenu(['ROLE_MEMBER'], 'app_dashboard_eve_character_details');

        $category = $this->_findCategory($menu->getCategories(NavigationMenu::PLACEMENT_MAIN), 'Charaktere');
        $this->assertTrue($category['active']);
        $this->assertTrue($category['items'][0]['active']);

        $this->assertSame([
            ['label' => 'Charaktere', 'route' => null],
            ['label' => 'Charakterübersicht', 'route' => 'app_profile_characters'],
            ['label' => 'Charakter-Details', 'route' => null],
        ], $menu->getBreadcrumbs());
    }

    public function testPageOutsideTheMenuHasNoBreadcrumbs(): void
    {
        $menu = $this->_createMenu(['ROLE_MEMBER'], 'home');

        $this->assertSame([], $menu->getBreadcrumbs());
    }

    public function testEveryMenuRouteExists(): void
    {
        self::bootKernel();
        /** @var RouterInterface $router */
        $router = static::getContainer()->get('router');
        $routes = $router->getRouteCollection();

        $menu = $this->_createMenu(['ROLE_ADMIN', 'ROLE_CEO', 'ROLE_OFFICER', 'ROLE_MEMBER'], 'home');
        $placements = [NavigationMenu::PLACEMENT_MAIN, NavigationMenu::PLACEMENT_PROFILE];
        foreach ($placements as $placement) {
            foreach ($menu->getCategories($placement) as $category) {
                foreach ($category['items'] as $item) {
                    $this->assertNotNull($routes->get($item['route']), sprintf('Route "%s" does not exist', $item['route']));
                }
            }
        }
    }

    /**
     * @param list<string> $grantedRoles
     */
    private function _createMenu(array $grantedRoles, string $currentRoute): NavigationMenu
    {
        $authorizationChecker = $this->createStub(AuthorizationCheckerInterface::class);
        $authorizationChecker->method('isGranted')->willReturnCallback(
            static fn (mixed $role): bool => in_array($role, $grantedRoles, true)
        );

        $request = new Request();
        $request->attributes->set('_route', $currentRoute);
        $requestStack = new RequestStack();
        $requestStack->push($request);

        return new NavigationMenu($authorizationChecker, $requestStack);
    }

    private function _getCategoryLabels(array $categories): array
    {
        $labels = [];
        foreach ($categories as $category) {
            $labels[] = $category['label'];
        }

        return $labels;
    }

    private function _findCategory(array $categories, string $label): array
    {
        foreach ($categories as $category) {
            if ($category['label'] === $label) {
                return $category;
            }
        }

        $this->fail(sprintf('Category "%s" not found', $label));
    }
}
