<?php

namespace App\Service\Navigation;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Single source of truth for the main menu, the profile menu and the breadcrumbs.
 *
 * A new page only needs an entry here: category, label, route and, if needed, the required role.
 */
class NavigationMenu
{
    public const PLACEMENT_MAIN = 'main';
    public const PLACEMENT_PROFILE = 'profile';

    public function __construct(
        private readonly AuthorizationCheckerInterface $authorizationChecker,
        private readonly RequestStack $requestStack
    ) {}

    /**
     * @return list<array{label: string, active: bool, items: list<array{label: string, route: string, active: bool}>}>
     */
    public function getCategories(string $placement): array
    {
        $currentRoute = $this->_getCurrentRoute();
        $categories = [];

        foreach ($this->_getDefinition() as $category) {
            if ($category['placement'] !== $placement || !$this->_isGranted($category['role'])) {
                continue;
            }

            $items = [];
            $isCategoryActive = false;
            foreach ($category['items'] as $item) {
                if (!$this->_isGranted($item['role'] ?? null)) {
                    continue;
                }

                $isItemActive = $this->_isItemActive($item, $currentRoute);
                $isCategoryActive = $isCategoryActive || $isItemActive;
                $items[] = [
                    'label' => $item['label'],
                    'route' => $item['route'],
                    'active' => $isItemActive,
                ];
            }

            if ($items === []) {
                continue;
            }

            $categories[] = [
                'label' => $category['label'],
                'active' => $isCategoryActive,
                'items' => $items,
            ];
        }

        return $categories;
    }

    /**
     * @return list<array{label: string, route: ?string}> Empty when the current page is not part of the menu
     */
    public function getBreadcrumbs(): array
    {
        $currentRoute = $this->_getCurrentRoute();
        if ($currentRoute === null) {
            return [];
        }

        foreach ($this->_getDefinition() as $category) {
            foreach ($category['items'] as $item) {
                if ($item['route'] === $currentRoute) {
                    return [
                        ['label' => $category['label'], 'route' => null],
                        ['label' => $item['label'], 'route' => $item['route']],
                    ];
                }

                $subpages = $item['subpages'] ?? [];
                if (isset($subpages[$currentRoute])) {
                    return [
                        ['label' => $category['label'], 'route' => null],
                        ['label' => $item['label'], 'route' => $item['route']],
                        ['label' => $subpages[$currentRoute], 'route' => null],
                    ];
                }
            }
        }

        return [];
    }

    /**
     * Subpages (route => breadcrumb label) are not listed in the menu but highlight their parent item.
     *
     * @return list<array{label: string, placement: string, role: ?string, items: list<array{label: string, route: string, role?: string, subpages?: array<string, string>}>}>
     */
    private function _getDefinition(): array
    {
        return [
            [
                'label' => 'Allgemein',
                'placement' => self::PLACEMENT_MAIN,
                'role' => 'ROLE_MEMBER',
                'items' => [
                    ['label' => 'Linksammlung', 'route' => 'link_collection'],
                    ['label' => 'Wurmloch-Sites', 'route' => 'app_wormhole_sites'],
                ],
            ],
            [
                'label' => 'Charaktere',
                'placement' => self::PLACEMENT_MAIN,
                'role' => 'ROLE_MEMBER',
                'items' => [
                    [
                        'label' => 'Charakterübersicht',
                        'route' => 'app_profile_characters',
                        'subpages' => [
                            'app_dashboard_eve_character_assets' => 'Charakter-Assets',
                            'app_dashboard_eve_character_details' => 'Charakter-Details',
                        ],
                    ],
                    ['label' => 'Skill-Farmen', 'route' => 'app_profile_skill_farm'],
                ],
            ],
            [
                'label' => 'Industrie',
                'placement' => self::PLACEMENT_MAIN,
                'role' => 'ROLE_MEMBER',
                'items' => [
                    ['label' => 'Industrie-Aufträge', 'route' => 'app_dashboard_industry_overview'],
                    ['label' => 'Blueprint-Bibliothek', 'route' => 'app_corp_blueprints'],
                    ['label' => 'PI-Planung', 'route' => 'app_dashboard_pi_overview'],
                    ['label' => 'Bergbautagebuch', 'route' => 'app_dashboard_mining_ledger'],
                    ['label' => 'Wurmloch-Reaktionen', 'route' => 'app_general_reactions'],
                ],
            ],
            [
                'label' => 'Wallet & Assets',
                'placement' => self::PLACEMENT_MAIN,
                'role' => 'ROLE_MEMBER',
                'items' => [
                    [
                        'label' => 'Inventar & Wallet',
                        'route' => 'app_dashboard_assets_overview',
                        'subpages' => [
                            'app_dashboard_performance_ledger' => 'Performance',
                        ],
                    ],
                    ['label' => 'Marktaufträge', 'route' => 'app_dashboard_market_overview'],
                ],
            ],
            [
                // Admin only while the trading tool is being built
                'label' => 'Handel',
                'placement' => self::PLACEMENT_MAIN,
                'role' => 'ROLE_ADMIN',
                'items' => [
                    ['label' => 'Handels-Tool', 'route' => 'app_trading'],
                ],
            ],
            [
                'label' => 'Corp',
                'placement' => self::PLACEMENT_MAIN,
                'role' => 'ROLE_MEMBER',
                'items' => [
                    ['label' => 'Orders & Aufträge', 'route' => 'app_order_list'],
                    ['label' => 'Corp-Inventar', 'route' => 'app_dashboard_corp_assets_overview'],
                    ['label' => 'Stationen', 'route' => 'app_corp_structures'],
                    ['label' => 'Verteidigungsdoktrin', 'route' => 'app_corp_defense_doctrine'],
                    ['label' => 'Vorschläge', 'route' => 'app_suggestions'],
                ],
            ],
            [
                'label' => 'Admin-Panel',
                'placement' => self::PLACEMENT_MAIN,
                'role' => 'ROLE_OFFICER',
                'items' => [
                    ['label' => 'Benutzerverwaltung', 'route' => 'app_admin_users'],
                    ['label' => 'Hangar-Sichtbarkeit', 'route' => 'app_admin_corp_assets_visibility', 'role' => 'ROLE_CEO'],
                    ['label' => 'Cron-Jobs', 'route' => 'app_admin_cron_index', 'role' => 'ROLE_CEO'],
                    ['label' => 'Discord-Webhooks', 'route' => 'app_admin_discord_index', 'role' => 'ROLE_CEO'],
                    ['label' => 'Wanderer-Integration', 'route' => 'app_admin_wanderer_index', 'role' => 'ROLE_CEO'],
                ],
            ],
            [
                'label' => 'Profil',
                'placement' => self::PLACEMENT_PROFILE,
                'role' => 'ROLE_MEMBER',
                'items' => [
                    ['label' => 'Mein Profil', 'route' => 'app_profile'],
                    ['label' => 'Charaktere verwalten', 'route' => 'app_profile_character_management'],
                ],
            ],
        ];
    }

    private function _isItemActive(array $item, ?string $currentRoute): bool
    {
        if ($currentRoute === null) {
            return false;
        }

        return $item['route'] === $currentRoute || isset($item['subpages'][$currentRoute]);
    }

    private function _isGranted(?string $role): bool
    {
        return $role === null || $this->authorizationChecker->isGranted($role);
    }

    private function _getCurrentRoute(): ?string
    {
        $route = $this->requestStack->getMainRequest()?->attributes->get('_route');

        return is_string($route) ? $route : null;
    }
}
