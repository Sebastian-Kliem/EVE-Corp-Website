<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * CSRF protection for cookie-authenticated requests: rejects state-changing requests a browser marks as cross-origin.
 */
class SameOriginRequestSubscriber implements EventSubscriberInterface
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS', 'TRACE'];

    public static function getSubscribedEvents(): array
    {
        // Run before the security firewall (priority 8) authenticates via session or remember-me cookie
        return [KernelEvents::REQUEST => ['onKernelRequest', 16]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (!$this->isAllowed($event->getRequest())) {
            $event->setResponse(new JsonResponse(['error' => 'Cross-origin request rejected.'], Response::HTTP_FORBIDDEN));
        }
    }

    public function isAllowed(Request $request): bool
    {
        if (in_array($request->getMethod(), self::SAFE_METHODS, true)) {
            return true;
        }

        // Bearer tokens are not sent automatically by browsers, so they are not CSRF-prone
        if (str_starts_with((string) $request->headers->get('Authorization'), 'Bearer ')) {
            return true;
        }

        $fetchSite = $request->headers->get('Sec-Fetch-Site');
        if ($fetchSite !== null) {
            return $fetchSite === 'same-origin' || $fetchSite === 'none';
        }

        $origin = $request->headers->get('Origin');
        if ($origin !== null) {
            return $this->_isSameHost($origin, $request);
        }

        // No browser headers: non-browser client (e.g. server-to-server webhook)
        return true;
    }

    // Compares hosts only, as the scheme may differ behind the TLS-terminating reverse proxy
    private function _isSameHost(string $origin, Request $request): bool
    {
        $originHost = parse_url($origin, PHP_URL_HOST);

        return is_string($originHost) && strcasecmp($originHost, $request->getHost()) === 0;
    }
}
