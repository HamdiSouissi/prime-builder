<?php

declare(strict_types=1);

namespace PrimeBuilder\Widgets;

use Prime\Content\ContentTypeRegistry;
use Prime\Content\Entry;
use Prime\Content\EntryQuery;
use Prime\Content\EntryRepository;
use Prime\Content\TaxonomyRegistry;
use Prime\Site\UrlGenerator;
use Prime\Site\UrlResolver;
use Prime\Site\Zone\DynamicBlockRegistry;
use Prime\Site\Zone\ZoneRepository;
use Prime\Themes\ThemeManager;

/** Read-only, public content provider. Identical bounded queries are shared per request. */
final class Content
{
    /** @var array<string, array{items: list<Entry>, page: int, last_page: int}> */
    private array $postQueries = [];

    public function __construct(
        private readonly EntryQuery $query,
        private readonly ContentTypeRegistry $types,
        private readonly UrlGenerator $urls,
        private readonly ?ThemeManager $themes = null,
        private readonly ?ZoneRepository $zones = null,
        private readonly ?DynamicBlockRegistry $dynamicBlocks = null,
        private readonly ?TaxonomyRegistry $taxonomies = null,
        private readonly ?EntryRepository $entries = null,
    ) {
    }

    public function invalidate(): void
    {
        $this->postQueries = [];
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
    public function current(array $settings, ?Entry $entry): string
    {
        if ($entry === null) {
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
        $value = match ($source) {
            'excerpt' => $entry->excerpt ?? '',
            'author' => $entry->authorName ?? '',
            'title' => $entry->title,
            default => '',
        };
        $value = trim(strip_tags($value));
        return $value === '' ? '' : '<p>' . Renderer::escape($value) . '</p>';
    }
}
