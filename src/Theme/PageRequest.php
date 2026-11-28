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

    private ?Request $resolvedRequest = null;

    /** @param callable(Request): Response $next */
    public function process(Request $request, callable $next): Response
    {
        $previousRequest = $this->request;
        $previousPage = $this->resolvedPage;
        $previousResolvedRequest = $this->resolvedRequest;
        $previousQuery = $this->resolvedQuery;
        $this->request = $request;
        $this->resolvedPage = null;
        $this->resolvedRequest = null;
        $this->resolvedQuery = [];
        try {
            return $next($request);
        } finally {
            $this->request = $previousRequest;
            $this->resolvedPage = $previousRequest !== null ? $previousPage : null;
            $this->resolvedRequest = $previousRequest !== null ? $previousResolvedRequest : null;
            $this->resolvedQuery = $previousRequest !== null ? $previousQuery : [];
        }
    }

    public function resolved(mixed $page, mixed $request): void
    {
        $this->resolvedPage = $page instanceof PageContext ? $page : null;
        $this->resolvedRequest = $request instanceof Request ? $request : null;
        $query = $request instanceof Request ? $request->query() : null;
        $this->resolvedQuery = is_array($query) ? $query : [];
    }

    public function request(PageContext $page): ?Request
    {
        // A plugin's renderPage() and authenticated previews can bypass
        // site.context. Never attach a scoped request to a different URL.
        if ($this->request !== null) {
            $path = '/' . trim($this->request->path(), '/');
            $pagePath = '/' . trim($page->path, '/');
            $adminPreview = $page->isPreview && $page->entry !== null
                && preg_match('~^/admin/content/([a-z0-9_-]+)/([1-9][0-9]*)/preview$~D', $path, $matches) === 1
                && $matches[1] === $page->entry->type && $matches[2] === (string) $page->entry->id;
            return $path === $pagePath || $adminPreview ? $this->request : null;
        }
        return $page === $this->resolvedPage ? $this->resolvedRequest : null;
    }

    /** Current site context only while an HTTP route is being rendered. */
    public function currentPage(): ?PageContext
    {
        return $this->request !== null && $this->resolvedPage !== null && $this->request($this->resolvedPage) !== null
            ? $this->resolvedPage : null;
    }

    /** @return array<mixed> */
    public function query(PageContext $page): array
    {
        $request = $this->request($page);
        if ($request !== null) {
            $query = $request->query();
            return is_array($query) ? $query : [];
        }
        return $page === $this->resolvedPage ? $this->resolvedQuery : [];
    }
}
