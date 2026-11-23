<?php

declare(strict_types=1);

namespace PrimeBuilder\Widgets;

use Prime\Auth\UserRepository;
use Prime\Content\ContentTypeRegistry;
use Prime\Content\Entry;
use Prime\Content\EntryQuery;
use Prime\Content\EntryRepository;
use Prime\Content\TaxonomyRegistry;
use Prime\Security\HtmlSanitizer;
use Prime\Site\Menu\MenuRenderer;
use Prime\Site\PageContext;
use Prime\Site\PermalinkSettings;
use Prime\Site\Seo\SeoManager;
use Prime\Site\UrlGenerator;
use Prime\Site\UrlResolver;
use Prime\Site\View\EntryView;
use Prime\Site\View\EntryViewFactory;
use Prime\Site\Zone\DynamicBlockRegistry;
use Prime\Site\Zone\ZoneRepository;
use Prime\Themes\ThemeManager;

/** Read-only, public content provider. Identical bounded queries are shared per request. */
final class Content
{
    /** @var array<string, array{items: list<Entry>, page: int, last_page: int}> */
    private array $postQueries = [];

    private ?Entry $viewEntry = null;

    private ?EntryView $entryView = null;

    /** @var array<string, EntryView|null> */
    private array $adjacentEntries = [];

    /** @var array<int, true> */
    private array $renderingEntryContent = [];

    public function __construct(
        private readonly EntryQuery $query,
        private readonly ContentTypeRegistry $types,
        private readonly UrlGenerator $urls,
        private readonly ?ThemeManager $themes = null,
        private readonly ?ZoneRepository $zones = null,
        private readonly ?DynamicBlockRegistry $dynamicBlocks = null,
        private readonly ?TaxonomyRegistry $taxonomies = null,
        private readonly ?EntryRepository $entries = null,
        private readonly ?MenuRenderer $menus = null,
        private readonly ?SeoManager $seo = null,
        private readonly ?PermalinkSettings $permalinks = null,
        private readonly ?SiteIdentity $siteIdentity = null,
        private readonly ?EntryViewFactory $entryViews = null,
        private readonly ?UserRepository $users = null,
    ) {
    }

    public function invalidate(): void
    {
        $this->postQueries = [];
        $this->viewEntry = null;
        $this->entryView = null;
        $this->adjacentEntries = [];
    }

    /** @param array<string, mixed> $settings */
    public function posts(array $settings, bool $live, string $id = '', int $page = 1): string
    {
        $typeName = is_string($settings['content_type'] ?? null) && $settings['content_type'] !== '' ? $settings['content_type'] : 'post';
        $type = $this->types->get($typeName);
        if ($type === null || !$type->public) {
            return '';
        }
        if (!$live) {
            // Never persist other entries' titles: they can later become private.
            // Core pages have no archive; the core search is their safe fallback.
            $url = $typeName === 'page' ? $this->urls->searchUrl() : $this->urls->archiveUrl($typeName);
            $label = $typeName === 'post' ? 'posts_fallback' : ($typeName === 'page' ? 'posts_page_fallback' : 'posts_other_fallback');
            return '<p><a href="' . Renderer::escape($url) . '">'
                . Renderer::escape(t('plugin::prime-builder.ui.' . $label)) . '</a></p>';
        }

        $rawTerms = is_string($settings['term_ids'] ?? null) ? $settings['term_ids'] : '';
        $termIds = TermIds::parse($rawTerms);
        $taxonomy = is_string($settings['taxonomy'] ?? null) ? $settings['taxonomy'] : '';
        if ($termIds === null) {
            return '';
        }
        sort($termIds, SORT_NUMERIC);
        if ($termIds !== []) {
            $allowed = $this->taxonomies?->forContentType($typeName, $type) ?? [];
            if (!isset($allowed[$taxonomy]) || !$allowed[$taxonomy]->public) {
                return '';
            }
        } else {
            $taxonomy = '';
        }
        $author = is_int($settings['author_id'] ?? null) && $settings['author_id'] > 0 ? $settings['author_id'] : null;
        $order = is_string($settings['order_by'] ?? null) && in_array($settings['order_by'], ['published_at', 'created_at', 'updated_at', 'title', 'menu_order'], true)
            ? $settings['order_by'] : 'published_at';
        $direction = ($settings['order_direction'] ?? null) === 'asc' ? 'ASC' : 'DESC';
        $limit = max(1, min(12, (int) ($settings['limit'] ?? 3)));
        $pagination = ($settings['pagination'] ?? null) === 'show' && preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $id) === 1;
        $page = max(1, min(1000, $page));
        // Non-paginated widgets with different display limits share the same bounded query.
        $key = hash('sha256', serialize([$typeName, $taxonomy, $termIds, $author, $order, $direction, $pagination, $pagination ? $limit : 12, $pagination ? $page : 1]));
        if (!isset($this->postQueries[$key])) {
            $query = $this->query->newQuery()->type($typeName)->withRelations(false, false, false);
            if ($termIds !== []) {
                $query = $query->whereTerm($taxonomy, $termIds);
            }
            if ($author !== null) {
                $query = $query->author($author);
            }
            $query = $query->orderBy($order, $direction)->orderBy('id', 'DESC');
            if ($pagination) {
                $result = $query->paginate($page, $limit);
                $this->postQueries[$key] = ['items' => $this->resolvePageAncestors($result['items']), 'page' => $result['page'], 'last_page' => $result['last_page']];
            } else {
                $this->postQueries[$key] = ['items' => $this->resolvePageAncestors($query->limit(12)->get()), 'page' => 1, 'last_page' => 1];
            }
        }
        $result = $this->postQueries[$key];
        $items = $pagination ? $result['items'] : array_slice($result['items'], 0, $limit);
        $cards = ($settings['layout'] ?? null) === 'cards';
        $columns = max(1, min(4, (int) ($settings['columns'] ?? 3)));
        if ($items === []) {
            $html = '<p>' . Renderer::escape(t('plugin::prime-builder.ui.posts_empty')) . '</p>';
        } else {
            $html = '<ul class="pb-post-list' . ($cards ? ' pb-post-list--cards pb-post-columns-' . $columns : '') . '">';
            foreach ($items as $post) {
                $title = trim($post->title) !== '' ? $post->title : t('plugin::prime-builder.ui.post_untitled');
                $html .= '<li><article><h3><a href="' . Renderer::escape($this->urls->entryUrl($post)) . '">' . Renderer::escape($title) . '</a></h3>';
                if (($settings['show_excerpt'] ?? 'hide') === 'show') {
                    $excerpt = trim(preg_replace('/\s+/u', ' ', strip_tags($post->excerpt ?? $post->contentText)) ?? '');
                    if (mb_strlen($excerpt, 'UTF-8') > 180) {
                        $excerpt = rtrim(mb_substr($excerpt, 0, 179, 'UTF-8')) . '…';
                    }
                    if ($excerpt !== '') {
                        $html .= '<p>' . Renderer::escape($excerpt) . '</p>';
                    }
                }
                $html .= '</article></li>';
            }
            $html .= '</ul>';
        }
        if ($pagination && $result['last_page'] > 1) {
            $html .= $this->postPagination($id, $result['page'], $result['last_page']);
        }
        return $html;
    }

    /**
     * Resolve page ancestors in batches, at most one query per hierarchy level,
     * before asking the CMS URL generator for the canonical URL. The core resolver
     * only accepts paths of at most 12 published pages; unreachable paths are omitted.
     *
     * @param list<Entry> $items
     * @return list<Entry>
     */
    private function resolvePageAncestors(array $items): array
    {
        /** @var array<int, Entry|null> $known */
        $known = [];
        foreach ($items as $entry) {
            if ($entry->type === 'page') {
                $known[$entry->id] = $entry;
            }
        }
        for ($depth = 0; $depth < 11 && $this->entries !== null; $depth++) {
            $needed = [];
            foreach ($known as $entry) {
                if ($entry instanceof Entry && $entry->type === 'page' && $entry->parentId > 0 && !array_key_exists($entry->parentId, $known)) {
                    $needed[$entry->parentId] = true;
                }
            }
            if ($needed === []) {
                break;
            }
            $found = $this->entries->findManyById(array_keys($needed));
            foreach (array_keys($needed) as $parent) {
                $known[$parent] = $found[$parent] ?? null;
            }
        }
        $primed = [];
        foreach ($known as $entry) {
            if ($entry instanceof Entry && $entry->type === 'page') {
                $primed[] = $entry;
            }
        }
        $this->urls->primePageCache($primed);
        $safe = [];
        foreach ($items as $entry) {
            if ($entry->type !== 'page') {
                $safe[] = $entry;
                continue;
            }
            $seen = [$entry->id => true];
            $parent = $entry->parentId;
            $depth = 0;
            $valid = true;
            while ($parent > 0 && $depth < 11 && !isset($seen[$parent])) {
                $ancestor = $known[$parent] ?? null;
                if (!$ancestor instanceof Entry || $ancestor->type !== 'page' || !UrlResolver::isPubliclyVisible($ancestor)) {
                    $valid = false;
                    break;
                }
                $seen[$parent] = true;
                $parent = $ancestor->parentId;
                $depth++;
            }
            if ($valid && $parent === 0) {
                $safe[] = $entry;
            }
        }
        return $safe;
    }

    private function postPagination(string $id, int $page, int $lastPage): string
    {
        $key = rawurlencode('pb_page_' . $id);
        $link = static fn (int $target, string $rel, string $label): string => '<a href="?' . $key . '=' . $target . '" data-pb-post-page="' . $target . '" rel="' . $rel . '">' . Renderer::escape($label) . '</a>';
        $html = '<nav class="pb-post-pagination" aria-label="' . Renderer::escape(t('plugin::prime-builder.ui.post_pagination_label')) . '">';
        if ($page > 1) {
            $html .= $link($page - 1, 'prev', t('plugin::prime-builder.ui.post_previous'));
        }
        $html .= '<span aria-current="page">' . Renderer::escape(t('plugin::prime-builder.ui.post_page', ['page' => $page, 'total' => $lastPage])) . '</span>';
        if ($page < min(1000, $lastPage)) {
            $html .= $link($page + 1, 'next', t('plugin::prime-builder.ui.post_next'));
        }
        return $html . '</nav>';
    }

    /** @param array<string, mixed> $settings */
    public function searchForm(array $settings, string $id): string
    {
        if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $id) !== 1) {
            return '';
        }
        $label = is_string($settings['search_label'] ?? null) ? trim($settings['search_label']) : '';
        $placeholder = is_string($settings['placeholder'] ?? null) ? trim($settings['placeholder']) : '';
        $button = is_string($settings['button_text'] ?? null) ? trim($settings['button_text']) : '';
        $label = $label !== '' ? $label : t('plugin::prime-builder.ui.search_form_default_label');
        $button = $button !== '' ? $button : t('plugin::prime-builder.ui.search_form_default_button');
        $input = 'pb-search-' . $id;
        return '<form class="pb-search-form" role="search" aria-label="' . Renderer::escape($label) . '" method="get" action="' . Renderer::escape($this->urls->searchUrl()) . '" data-pb-search-form>'
            . '<label for="' . Renderer::escape($input) . '">' . Renderer::escape($label) . '</label>'
            . '<div><input type="search" id="' . Renderer::escape($input) . '" name="q" maxlength="200"' . ($placeholder !== '' ? ' placeholder="' . Renderer::escape($placeholder) . '"' : '') . ' required>'
            . '<button type="submit">' . Renderer::escape($button) . '</button></div></form>';
    }

    /** @param array<string, mixed> $settings */
    public function navigation(array $settings, ?Entry $entry, bool $live): string
    {
        return Navigation::render($settings, $this->menus, $live, $entry !== null ? $this->urls->entryUrl($entry) : '/');
    }

    /**
     * Do not persist breadcrumb labels or URLs: both context and entry visibility can change.
     * The live endpoint has the current entry, but not its original request context;
     * derive the ordinary entry/front/blog context from the CMS's generated paths.
     *
     * @param array<string, mixed> $settings
     */
    public function breadcrumbs(array $settings, ?Entry $entry, bool $live, HtmlSanitizer $sanitizer): string
    {
        if (!$live || $this->seo === null || $entry === null) {
            return Breadcrumbs::fallback($settings);
        }
        $path = $this->urls->entryUrl($entry);
        $type = PageContext::TYPE_ENTRY;
        if ($path === '/') {
            $type = PageContext::TYPE_FRONT;
        } elseif ($entry->type === 'page' && $path === $this->urls->blogUrl()) {
            $type = PageContext::TYPE_BLOG;
        }
        $page = new PageContext(type: $type, path: $path, entry: $entry, contentType: $this->types->get($entry->type));
        $crumbs = $this->seo->breadcrumbs($page);
        // Core's blogUrl() does not check whether the configured posts page (or a
        // parent in its path) is still public. Never expose that path in a live
        // widget when it is private: /blog is the core's public listing route.
        if ($page->isEntry() && $page->entry?->type === 'post' && isset($crumbs[1]) && !$this->publicPostsPage()) {
            $crumbs[1]['url'] = '/blog';
        }
        return Breadcrumbs::render($settings, $crumbs, $sanitizer);
    }

    private function publicPostsPage(): bool
    {
        if ($this->permalinks === null) {
            return false;
        }
        if ($this->permalinks->frontMode() !== PermalinkSettings::FRONT_MODE_PAGE) {
            return true;
        }
        $id = $this->permalinks->postsPageId();
        if ($id === null) {
            return true;
        }
        if ($this->entries === null) {
            return false;
        }
        $seen = [];
        for ($depth = 0; $id > 0 && $depth < 12; $depth++) {
            if (isset($seen[$id])) {
                return false;
            }
            $seen[$id] = true;
            $page = $this->entries->find($id, withRelations: false);
            if ($page === null || $page->type !== 'page' || !UrlResolver::isPubliclyVisible($page)) {
                return false;
            }
            $id = $page->parentId;
        }
        return $id === 0;
    }

    /** @param array<string, mixed> $settings */
    public function siteTitle(array $settings): string
    {
        return $this->siteIdentity?->title($settings) ?? '';
    }

    /** @param array<string, mixed> $settings */
    public function siteLogo(array $settings): string
    {
        return $this->siteIdentity?->logo($settings) ?? '';
    }

    /** @param array<string, mixed> $settings */
    public function entryWidget(string $type, array $settings, ?Entry $entry, bool $live): string
    {
        // EntryService renders with the old entry before writing the new status,
        // title and metadata. On a simultaneous publish that old entry may be a
        // private draft; never persist any of its values in the public HTML.
        $public = $entry !== null && $this->types->get($entry->type)?->public === true && UrlResolver::isPubliclyVisible($entry);
        // Neighbours and author profiles can independently become private after a
        // save. Never persist them, including when the old entry was public.
        if (!$live && in_array($type, ['entry_navigation', 'entry_author'], true)) {
            return '';
        }
        $view = $this->entryView($live || $public ? $entry : null);
        return match ($type) {
            'entry_navigation' => $this->entryNavigation($settings, $view),
            'entry_author' => $this->entryAuthor($settings, $view),
            'entry_title' => CurrentEntry::title($settings, $view),
            'entry_excerpt' => CurrentEntry::excerpt($settings, $view),
            'entry_featured_image' => CurrentEntry::featuredImage($settings, $view),
            'entry_info' => $this->entryInfo($settings, $view, $live),
            default => '',
        };
    }

    /**
     * A theme partial receives the real resolved page, unlike a saved entry's
     * block renderer. Never expose draft, future or unreachable entry data even
     * when another plugin constructs an unexpected page context.
     *
     * @param array<string, mixed> $settings
     */
    public function entryWidgetInPage(string $type, array $settings, ?PageContext $page): string
    {
        $entry = $this->publicPageEntry($page);
        return $entry !== null ? $this->entryWidget($type, $settings, $entry, true) : '';
    }

    /** A copy is only useful in a page-context template, never inside its own saved entry. */
    public function entryContentInPage(?PageContext $page): string
    {
        $entry = $this->publicPageEntry($page);
        if ($entry === null || isset($this->renderingEntryContent[$entry->id])) {
            return '';
        }
        $this->renderingEntryContent[$entry->id] = true;
        try {
            $html = $this->entryView($entry)?->content() ?? '';
            // Each rendering receives a fresh scope, including multiple widgets in
            // both theme slots. The copied IDs cannot collide with the original.
            $prefix = 'pbc-' . bin2hex(random_bytes(12));
            $html = ContentFragment::scope($html, $prefix);
            return $html !== '' ? '<div data-pb-content-copy="' . $prefix . '">' . $html . '</div>' : '';
        } finally {
            unset($this->renderingEntryContent[$entry->id]);
        }
    }

    private function publicPageEntry(?PageContext $page): ?Entry
    {
        if ($page === null || $page->httpStatus !== 200 || $page->isRedirect()
            || !in_array($page->type, [PageContext::TYPE_ENTRY, PageContext::TYPE_FRONT, PageContext::TYPE_BLOG], true)) {
            return null;
        }
        $entry = $page->entry;
        if ($entry === null || $this->types->get($entry->type)?->public !== true || !UrlResolver::isPubliclyVisible($entry)) {
            return null;
        }
        if ($entry->type === 'page' && $this->resolvePageAncestors([$entry]) === []) {
            return null;
        }
        return $entry;
    }

    /** @param array<string, mixed> $settings */
    public function archiveWidget(string $type, array $settings, ?PageContext $page): string
    {
        if (!Archive::supports($page) || $page === null) {
            return '';
        }
        if ($type === 'archive_title') {
            return Archive::title($settings, $page);
        }
        if ($type !== 'archive_list') {
            return '';
        }
        // The resolver's paginated result is already public. Recheck definitions
        // and dates, and batch-check hierarchical pages before linking them.
        $items = [];
        foreach (array_slice($page->entries, 0, 50) as $entry) {
            if ($this->types->get($entry->type)?->public === true && UrlResolver::isPubliclyVisible($entry)) {
                $items[] = $entry;
            }
        }
        return Archive::listing($settings, $page, $this->resolvePageAncestors($items), $this->urls);
    }

    /** @param array<string, mixed> $settings */
    private function entryNavigation(array $settings, ?EntryView $entry): string
    {
        if ($entry === null || $this->types->get($entry->raw()->type)?->public !== true) {
            return '';
        }
        $previous = ($settings['previous'] ?? 'show') === 'show' ? $this->adjacentEntry($entry, 'previous') : null;
        $next = ($settings['next'] ?? 'show') === 'show' ? $this->adjacentEntry($entry, 'next') : null;
        return CurrentEntry::navigation($settings, $previous, $next);
    }

    private function adjacentEntry(EntryView $entry, string $direction): ?EntryView
    {
        if (array_key_exists($direction, $this->adjacentEntries)) {
            return $this->adjacentEntries[$direction];
        }
        $seen = [$entry->raw()->id => true];
        $candidate = $entry;
        // Core finds the nearest published entry, but a published child may have
        // a private ancestor. Skip at most ten unreachable neighbours without
        // disclosing their title or path; core itself bounds each query to ten.
        for ($attempt = 0; $attempt < 10; $attempt++) {
            $candidate = $direction === 'previous' ? $candidate->previous() : $candidate->next();
            if ($candidate === null || isset($seen[$candidate->raw()->id])) {
                break;
            }
            $raw = $candidate->raw();
            $seen[$raw->id] = true;
            if (UrlResolver::isPubliclyVisible($raw) && trim($candidate->rawTitle()) !== ''
                && ($raw->type !== 'page' || $this->resolvePageAncestors([$raw]) !== [])) {
                return $this->adjacentEntries[$direction] = $candidate;
            }
        }
        return $this->adjacentEntries[$direction] = null;
    }

    /** @param array<string, mixed> $settings */
    private function entryAuthor(array $settings, ?EntryView $entry): string
    {
        [$public, $archiveAvailable] = $this->authorAvailability($entry);
        return $public ? CurrentEntry::author($settings, $entry, $archiveAvailable) : '';
    }

    /** @param array<string, mixed> $settings */
    private function entryInfo(array $settings, ?EntryView $entry, bool $live): string
    {
        // An author or taxonomy can become private after the content is saved.
        // Neither profile names nor term names/links belong in the saved fallback.
        [$publicAuthor, $archiveAvailable] = $live && ($settings['author'] ?? 'show') === 'show'
            ? $this->authorAvailability($entry) : [false, false];
        return CurrentEntry::info($settings, $entry, $live && $this->taxonomies?->get('category')?->public === true, $publicAuthor, $archiveAvailable);
    }

    /** @return array{bool, bool} Public profile and unambiguous archive link. */
    private function authorAvailability(?EntryView $entry): array
    {
        $authorId = $entry?->raw()->authorId;
        $user = $authorId !== null && $authorId > 0 ? $this->users?->find($authorId) : null;
        if ($user === null || !$user->isActive()) {
            return [false, false];
        }
        // A duplicate slug can resolve to a different user. Keep the name but
        // never link it to someone else's archive.
        $resolved = $this->users->findByAuthorSlug($user->authorSlug());
        return [true, $resolved !== null && $resolved->id === $user->id && $resolved->isActive()];
    }

    private function entryView(?Entry $entry): ?EntryView
    {
        if ($entry === null) {
            return null;
        }
        // A renderer passes the same immutable Entry to every widget. Cache its
        // hydrated author/media once, but never reuse a different Entry instance.
        if ($this->viewEntry !== $entry || $this->entryView === null) {
            $this->viewEntry = $entry;
            $this->entryView = $this->entryViews?->makeOne($entry) ?? new EntryView($entry, $this->urls);
            $this->adjacentEntries = [];
        }
        return $this->entryView;
    }

    /** @param array<string, mixed> $settings */
    public function shareButtons(array $settings, ?Entry $entry): string
    {
        $url = $entry !== null ? $this->urls->absolute($this->urls->entryUrl($entry)) : '';
        return ShareButtons::render($settings, $url);
    }

    /** @param array<string, mixed> $settings */
    public function themeZone(array $settings, bool $live): string
    {
        $slug = is_string($settings['zone'] ?? null) ? $settings['zone'] : '';
        if ($slug === '' || preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/D', $slug) !== 1) {
            return '<p>' . Renderer::escape(t('plugin::prime-builder.ui.theme_zone_choose')) . '</p>';
        }
        if (!$live) {
            // Persist no zone contents: a zone can later be removed or made unavailable.
            return '<p>' . Renderer::escape(t('plugin::prime-builder.ui.theme_zone_fallback')) . '</p>';
        }
        if ($this->themes === null || $this->zones === null || !isset($this->themes->zones()[$slug])) {
            return '';
        }
        return $this->zones->renderLive($slug, $this->dynamicBlocks);
    }

    /** @param array<string, mixed> $settings */
    public function current(array $settings, ?Entry $entry, bool $live = false): string
    {
        // Core renders saved HTML with the previous entry during an update. Do
        // not leak an unpublished title or excerpt when publishing that entry.
        if ($entry === null || (!$live && ($this->types->get($entry->type)?->public !== true || !UrlResolver::isPubliclyVisible($entry)))) {
            return '';
        }
        $source = is_string($settings['source'] ?? null) ? $settings['source'] : 'title';
        if ($source === 'published') {
            $date = $entry->publishedAt ?? $entry->createdAt;
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $date) !== 1) {
                return '';
            }
            $day = substr($date, 0, 10);
            return '<time datetime="' . Renderer::escape($day) . '">' . Renderer::escape($day) . '</time>';
        }
        if ($source === 'author') {
            // A previously public profile may later be disabled. Do not keep
            // its name in saved HTML, and check the current account at runtime.
            $author = $live && $entry->authorId !== null && $entry->authorId > 0 ? $this->users?->find($entry->authorId) : null;
            $value = $author !== null && $author->isActive() ? $author->displayName : '';
        } else {
            $value = match ($source) {
                'excerpt' => $entry->excerpt ?? '',
                'title' => $entry->title,
                default => '',
            };
        }
        $value = trim(strip_tags($value));
        return $value === '' ? '' : '<p>' . Renderer::escape($value) . '</p>';
    }
}
