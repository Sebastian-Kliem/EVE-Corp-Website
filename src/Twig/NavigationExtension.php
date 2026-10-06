<?php

namespace App\Twig;

use App\Service\Navigation\NavigationMenu;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

class NavigationExtension extends AbstractExtension
{
    public function __construct(
        private readonly NavigationMenu $navigationMenu
    ) {}

    public function getFunctions(): array
    {
        return [
            new TwigFunction('get_navigation', [$this->navigationMenu, 'getCategories']),
            new TwigFunction('get_breadcrumbs', [$this->navigationMenu, 'getBreadcrumbs']),
        ];
    }
}
