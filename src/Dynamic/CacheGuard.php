<?php

declare(strict_types=1);

namespace PrimeBuilder\Dynamic;

use Prime\Auth\Auth;
use Prime\Cache\Http\PageCacheMiddleware;
use Prime\Cache\PageCache;
use Prime\Http\Middleware\MiddlewareInterface;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Http\Session;
use Prime\Plugins\PluginContext;
use PrimeBuilder\Styles\ZoneLayouts;
use Throwable;

/** Preserve the zone exception and delegate the actual cache to the CMS. */
final class CacheGuard implements MiddlewareInterface
{
    // No lookup is permitted until the zone state has been checked.
    private bool $savedZone = true;

    public function __construct(
        private readonly PluginContext $plugin,
        private readonly ZoneLayouts $zones,
        private readonly RenderState $state,
    ) {
    }

    /** Read the active theme's declared zones outside the cache filter. */
    public function refreshZones(): void
    {
        try {
            $this->savedZone = $this->zones->hasSavedDeclaredZone();
        } catch (Throwable) {
            // Hooks isolate exceptions: a failed zone check must not serve stale HTML.
            $this->savedZone = true;
        }
    }

    /** The core calls this both before lookup and before storing the response. */
    public function cacheable(mixed $value, mixed $request = null, mixed $response = null): mixed
    {
        if ($this->savedZone || ($response instanceof Response && $this->state->isTimeDependent())) {
            // The CMS only interprets literal false as a refusal, including
            // when an earlier filter returned a non-boolean value.
            return false;
        }
        return $value;
    }

    /** @param callable(Request): Response $next */
    public function process(Request $request, callable $next): Response
    {
        $previousZone = $this->savedZone;
        $this->state->begin();
        try {
            try {
                $app = $this->plugin->app();
                $cache = $app->get(PageCache::class);
                $session = $app->get(Session::class)->name();
                $remember = $app->get(Auth::class)->rememberCookieName();
                // The core knows only fixed cookie names. A renamed session or
                // remember-me cookie must also bypass before lookup.
                $private = $request->header('authorization') !== null;
                foreach ([$session, $remember, Session::DEFAULT_NAME, 'prime_remember', 'prime_auth', 'PHPSESSID'] as $cookie) {
                    $private = $private || ($request->cookie($cookie) ?? '') !== '';
                }
            } catch (Throwable) {
                // Do not risk sharing personalized HTML when dependencies fail.
                $cache = null;
                $private = true;
            }

            $this->savedZone = false;
            if ($cache instanceof PageCache && $cache->isEnabled() && !$private && $this->couldUseCache($request)) {
                $this->refreshZones();
            }
            $response = $cache instanceof PageCache && !$private
                ? (new PageCacheMiddleware($cache))->process($request, $next)
                : $next($request);

            // The internal store has already been decided. External caches must
            // not retain a page whose evaluated content changes with the clock.
            if ($this->state->isTimeDependent()
                && str_contains(strtolower((string) $response->header('Content-Type')), 'text/html')
                && !str_contains(strtolower((string) $response->header('Cache-Control')), 'no-store')) {
                $response = $response->withHeader('Cache-Control', 'private, no-store');
            }
            return $response;
        } finally {
            $this->savedZone = $previousZone;
            $this->state->end();
        }
    }

    /** Conservatively preflight page values; the CMS decides whether they are valid. */
    private function couldUseCache(Request $request): bool
    {
        if (!in_array($request->method(), ['GET', 'HEAD'], true)) {
            return false;
        }
        foreach (array_keys($request->query()) as $name) {
            if ((string) $name !== 'page') {
                return false;
            }
        }
        return true;
    }
}
