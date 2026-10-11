<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Permanently redirects page URLs from before the navigation restructuring (2026-10) to their new location.
 */
class LegacyRedirectController extends AbstractController
{
    /** Old path => new route name */
    private const REDIRECTS = [
        '/personal/profile' => 'app_profile',
        '/personal/profile/characters' => 'app_profile_characters',
        '/personal/profile/skill-farm' => 'app_profile_skill_farm',
        '/personal/wanderer' => 'app_personal_wanderer_index',
        '/personal/industry' => 'app_dashboard_industry_overview',
        '/personal/pi' => 'app_dashboard_pi_overview',
        '/personal/mining' => 'app_dashboard_mining_ledger',
        '/personal/market' => 'app_dashboard_market_overview',
        '/personal/performance' => 'app_dashboard_performance_ledger',
        '/personal/assets' => 'app_dashboard_assets_overview',
        '/personal/value-history' => 'app_dashboard_assets_overview',
        '/corp/blueprints' => 'app_corp_blueprints',
        '/corp/appraisal' => 'app_order_list',
        '/general/appraisal' => 'app_order_list',
        '/general/reactions' => 'app_general_reactions',
        '/general/suggestions' => 'app_suggestions',
        '/general/link/collection' => 'link_collection',
    ];

    /** Old character page suffix => new route name */
    private const CHARACTER_REDIRECTS = [
        'assets' => 'app_dashboard_eve_character_assets',
        'details' => 'app_dashboard_eve_character_details',
    ];

    #[Route(
        '/{legacyPath}',
        name: 'app_legacy_redirect',
        requirements: ['legacyPath' => 'personal/(profile(/characters|/skill-farm)?|wanderer|industry|pi|mining|market|performance|assets|value-history)|corp/(blueprints|appraisal)|general/(reactions|suggestions|link/collection|appraisal)'],
        methods: ['GET'],
        priority: -10
    )]
    public function redirectPage(Request $request, string $legacyPath): Response
    {
        $route = self::REDIRECTS['/' . $legacyPath] ?? null;
        if ($route === null) {
            throw $this->createNotFoundException();
        }

        return $this->_redirectPermanently($route, [], $request);
    }

    #[Route(
        '/personal/eve-character/{id}/{page}',
        name: 'app_legacy_character_redirect',
        requirements: ['id' => '\d+', 'page' => 'assets|details'],
        methods: ['GET']
    )]
    public function redirectCharacterPage(Request $request, int $id, string $page): Response
    {
        return $this->_redirectPermanently(self::CHARACTER_REDIRECTS[$page], ['id' => $id], $request);
    }

    // Keeps the query string, e.g. filters in bookmarked links
    private function _redirectPermanently(string $route, array $parameters, Request $request): RedirectResponse
    {
        $url = $this->generateUrl($route, $parameters);
        $queryString = $request->getQueryString();
        if ($queryString !== null) {
            $url .= '?' . $queryString;
        }

        return $this->redirect($url, Response::HTTP_MOVED_PERMANENTLY);
    }
}
