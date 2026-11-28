<?php

declare(strict_types=1);

namespace PrimeBuilder\Widgets;

use Prime\Content\Entry;
use Prime\Site\PageContext;
use Prime\Site\UrlGenerator;
use Prime\Site\UrlResolver;

/** Render the listing already resolved by Prime CMS, not a second independent query. */
final class Archive
{
    public static function supports(?PageContext $page): bool
    {
        if ($page === null || $page->httpStatus !== 200 || $page->isRedirect()) {
            return false;
        }
        return match ($page->type) {
            PageContext::TYPE_FRONT => $page->entry === null,
            PageContext::TYPE_BLOG => true,
            PageContext::TYPE_ARCHIVE => $page->contentType !== null && $page->contentType->public,
            PageContext::TYPE_TERM => $page->taxonomy !== null && $page->taxonomy->public
                && $page->term !== null && $page->term->taxonomy === $page->taxonomy->name,
            PageContext::TYPE_AUTHOR => $page->author !== null && $page->author->isActive(),
            PageContext::TYPE_SEARCH => true,
            default => false,
        };
    }

    /** @param array<string, mixed> $settings */
    public static function title(array $settings, ?PageContext $page): string
    {
        if (!self::supports($page) || $page === null) {
            return '';
        }
        $title = match ($page->type) {
            PageContext::TYPE_FRONT => t('plugin::prime-builder.ui.archive_latest'),
            PageContext::TYPE_BLOG => $page->entry !== null && UrlResolver::isPubliclyVisible($page->entry)
                ? $page->entry->title : t('plugin::prime-builder.ui.archive_latest'),
            PageContext::TYPE_ARCHIVE => $page->contentType?->label('plural') ?? '',
            PageContext::TYPE_TERM => $page->term->name ?? '',
            PageContext::TYPE_AUTHOR => $page->author->displayName ?? '',
            PageContext::TYPE_SEARCH => trim($page->searchQuery ?? '') !== ''
                ? t('plugin::prime-builder.ui.archive_search_query', ['query' => trim($page->searchQuery ?? '')])
                : t('plugin::prime-builder.ui.archive_search'),
            default => '',
        };
        if (trim($title) === '') {
            return '';
        }
        $tag = is_string($settings['tag'] ?? null) && in_array($settings['tag'], ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true) ? $settings['tag'] : 'h2';
        return '<' . $tag . ' class="pb-archive-title__text">' . Renderer::escape($title) . '</' . $tag . '>';
    }

    /**
     * The caller passes only public, reachable entries from the resolved page.
     *
     * @param array<string, mixed> $settings
     * @param list<Entry> $items
     */
    public static function listing(array $settings, ?PageContext $page, array $items, UrlGenerator $urls): string
    {
        if (!self::supports($page) || $page === null) {
            return '';
        }
        $cards = ($settings['layout'] ?? 'cards') === 'cards';
        $columns = max(1, min(4, (int) ($settings['columns'] ?? 3)));
        if ($items === []) {
            $html = '<p class="pb-archive-list__empty">' . Renderer::escape(t('plugin::prime-builder.ui.archive_empty')) . '</p>';
        } else {
            $html = '<ul class="pb-archive-list__items' . ($cards ? ' pb-archive-list__items--cards pb-archive-list__columns-' . $columns : '') . '">';
            foreach ($items as $entry) {
                $title = trim($entry->title) !== '' ? $entry->title : t('plugin::prime-builder.ui.post_untitled');
                $html .= '<li><article><h3><a href="' . Renderer::escape($urls->entryUrl($entry)) . '">' . Renderer::escape($title) . '</a></h3>';
                if (($settings['show_excerpt'] ?? 'show') === 'show') {
                    $source = $entry->excerpt !== null && trim($entry->excerpt) !== '' ? $entry->excerpt : $entry->contentText;
                    $excerpt = trim(preg_replace('/\s+/u', ' ', strip_tags($source)) ?? '');
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
        if (($settings['pagination'] ?? 'show') === 'show') {
            $html .= self::pagination($page, $urls);
        }
        return $html;
    }

    private static function pagination(PageContext $page, UrlGenerator $urls): string
    {
        $current = max(1, $page->page);
        $total = max(1, $page->totalPages);
        if ($total <= 1 || $current > $total) {
            return '';
        }
        // The CMS resolves paths, not arbitrary URLs. Keep only a root-relative
        // path and the public search term; never propagate unrelated parameters.
        $base = preg_replace('#/page/[0-9]+/?$#D', '', $page->path) ?? '';
        $base = $base === '' ? '/' : $base;
        if (!str_starts_with($base, '/') || str_starts_with($base, '//') || preg_match('/[?#\x00-\x20\x7f]/', $base) === 1) {
            return '';
        }
        $query = $page->isSearch() && trim($page->searchQuery ?? '') !== '' ? '?q=' . rawurlencode(trim($page->searchQuery ?? '')) : '';
        $link = static fn (int $target, string $rel, string $label): string => '<a href="' . Renderer::escape($urls->paginatePath($base, $target) . $query) . '" rel="' . $rel . '">' . Renderer::escape($label) . '</a>';
        $html = '<nav class="pb-archive-list__pagination" aria-label="' . Renderer::escape(t('plugin::prime-builder.ui.archive_pagination')) . '">';
        if ($current > 1) {
            $html .= $link($current - 1, 'prev', t('plugin::prime-builder.ui.post_previous'));
        }
        $html .= '<span aria-current="page">' . Renderer::escape(t('plugin::prime-builder.ui.post_page', ['page' => $current, 'total' => $total])) . '</span>';
        if ($current < $total) {
            $html .= $link($current + 1, 'next', t('plugin::prime-builder.ui.post_next'));
        }
        return $html . '</nav>';
    }
}
