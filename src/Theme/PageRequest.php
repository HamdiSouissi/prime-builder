<?php

declare(strict_types=1);

namespace PrimeBuilder\Theme;

use Prime\Http\Middleware\MiddlewareInterface;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Site\PageContext;

/** Scope public pagination parameters to the currently dispatched request. */
final class PageRequest implements MiddlewareInterface
{
    private ?Request $request = null;

    /** @var array<mixed> */
    private array $resolvedQuery = [];

    private ?PageContext $resolvedPage = null;

    /** @param callable(Request): Response $next */
    public function process(Request $request, callable $next): Response
    {
        $previousRequest = $this->request;
        $previousPage = $this->resolvedPage;
        $previousQuery = $this->resolvedQuery;
        $this->request = $request;
        $this->resolvedPage = null;
        $this->resolvedQuery = [];
        try {
            return $next($request);
        } finally {
            $this->request = $previousRequest;
            $this->resolvedPage = $previousRequest !== null ? $previousPage : null;
            $this->resolvedQuery = $previousRequest !== null ? $previousQuery : [];
        }
    }

    public function resolved(mixed $page, mixed $request): void
    {
        $this->resolvedPage = $page instanceof PageContext ? $page : null;
        $query = $request instanceof Request ? $request->query() : null;
        $this->resolvedQuery = is_array($query) ? $query : [];
    }

    /** @return array<mixed> */
    public function query(PageContext $page): array
    {
        // A plugin's renderPage() and authenticated previews bypass
        // site.context. The scoped request also covers those paths and is
        // restored for nested dispatches; do not attach it to another URL.
        if ($this->request !== null) {
            $path = '/' . trim($this->request->path(), '/');
            $pagePath = '/' . trim($page->path, '/');
            $adminPreview = $page->isPreview && $page->entry !== null
                && preg_match('~^/admin/content/([a-z0-9_-]+)/([1-9][0-9]*)/preview$~D', $path, $matches) === 1
                && $matches[1] === $page->entry->type && $matches[2] === (string) $page->entry->id;
            if ($path !== $pagePath && !$adminPreview) {
                return [];
            }
            $query = $this->request->query();
            return is_array($query) ? $query : [];
        }
        // Preserve the resolver's explicitly paired context for integrations
        // that use UrlResolver and SiteRenderer without the HTTP kernel.
        return $page === $this->resolvedPage ? $this->resolvedQuery : [];
    }
}
