<?php

declare(strict_types=1);

namespace PrimeBuilder\Theme;

use Prime\Content\Entry;
use Prime\Editor\BlockRegistry;
use Prime\Hooks\Hooks;
use Prime\Plugins\PluginContext;
use Prime\Security\HtmlSanitizer;
use Prime\Site\PageContext;
use Prime\Site\SiteRenderer;
use Prime\Site\Zone\ZoneRepository;
use Prime\Themes\ThemeManager;
use PrimeBuilder\Document\HtmlContent;
use PrimeBuilder\Document\MediaReferences;
use PrimeBuilder\Document\Validator;
use PrimeBuilder\Dynamic\Layout as DynamicLayout;
use PrimeBuilder\Widgets\Content;
use PrimeBuilder\Widgets\OriginalContent;
use PrimeBuilder\Widgets\Registry;
use PrimeBuilder\Widgets\Renderer;

/** Re-evaluate shared references without rewriting a saved entry or the active theme. */
final class LiveEntry
{
    public const MARKER = 'prime_builder_live_entry';

    public function __construct(private readonly PluginContext $plugin, private readonly GlobalWidgets $globals)
    {
    }

    public function needsRefresh(PageContext $page): bool
    {
        if ($page->entry === null || $page->isRedirect() || $page->isPreview || $page->isCustomizerPreview || ($page->extra[self::MARKER] ?? false) === true) {
            return false;
        }
        $document = $page->entry->decodedContent();
        $blocks = $document['blocks'];
        if (count($blocks) !== 1 || $blocks[0]['type'] !== 'prime-builder/layout') {
            return false;
        }
        $data = $blocks[0]['data'];
        $elements = is_array($data['elements'] ?? null) ? $data['elements'] : [];
        // Only upgrade the zone path when its referenced document needs runtime
        // evaluation; ordinary static zones retain their established JS fallback.
        return GlobalWidgets::countReferences($elements) > 0 || DynamicLayout::uses($elements)
            || $this->referencesDynamicZone($elements);
    }

    /** @param array<mixed> $elements */
    private function referencesDynamicZone(array $elements): bool
    {
        $slugs = [];
        $pending = $elements;
        while ($pending !== []) {
            $element = array_pop($pending);
            if (!is_array($element)) {
                continue;
            }
            $slug = is_array($element['settings'] ?? null) ? ($element['settings']['zone'] ?? null) : null;
            if (($element['type'] ?? null) === 'theme_zone' && is_string($slug) && preg_match('/^[a-z0-9][a-z0-9_-]{0,79}$/D', $slug) === 1) {
                $slugs[$slug] = true;
            }
            foreach (is_array($element['children'] ?? null) ? $element['children'] : [] as $child) {
                $pending[] = $child;
            }
        }
        if ($slugs === []) {
            return false;
        }
        try {
            $themes = $this->plugin->app()->get(ThemeManager::class);
            $zones = $this->plugin->app()->get(ZoneRepository::class);
            $declared = $themes->zones();
            foreach (array_keys($slugs) as $slug) {
                if (!isset($declared[$slug])) {
                    continue;
                }
                foreach ($zones->document($slug)['blocks'] as $block) {
                    $data = $block['type'] === 'prime-builder/layout' ? $block['data'] : [];
                    $nodes = is_array($data['elements'] ?? null) ? $data['elements'] : [];
                    if (DynamicLayout::uses($nodes) || GlobalWidgets::countReferences($nodes) > 0) {
                        return true;
                    }
                }
            }
        } catch (\Throwable) {
            // An unavailable zone must not prevent the safe stored fallback.
        }
        return false;
    }

    /**
     * Core Entry and PageContext are immutable. Render a fresh copy through the
     * original theme hierarchy, with a recursion marker in the copied context.
     * If validation fails, the core's previously saved, filtered HTML is retained.
     */
    public function render(PageContext $page): string
    {
        $entry = $page->entry;
        if ($entry === null || !$this->needsRefresh($page)) {
            return '';
        }
        $data = $entry->decodedContent()['blocks'][0]['data'];
        $registry = new Registry($this->plugin->app()->get(Hooks::class));
        $sanitizer = $this->plugin->app()->get(HtmlSanitizer::class);
        $blocks = $this->plugin->app()->get(BlockRegistry::class);
        $originals = OriginalContent::fromAppKey($this->plugin->app()->config()->get('app.key'));
        $html = HtmlContent::fromAppKey($this->plugin->app()->config()->get('app.key'));
        $result = (new Validator($registry, $sanitizer, $blocks, $originals, $entry->id, $html, true))->validate($data);
        $contentHtml = $entry->contentHtml;
        if ($result['errors'] === []) {
            $pageRequest = $this->plugin->app()->get(PageRequest::class);
            $renderer = new Renderer($registry, $sanitizer, $this->plugin->logger(), $this->plugin->app()->get(Content::class), $entry, true, $blocks, $originals, $html, pageContext: $page, mediaReferences: $this->plugin->app()->get(MediaReferences::class), pageQuery: $pageRequest->query($page), dynamicPlugin: $this->plugin, request: $pageRequest->request($page));
            $contentHtml = $renderer->render($this->globals->expand($result['data']));
        }
        $live = new Entry(
            id: $entry->id,
            type: $entry->type,
            status: $entry->status,
            title: $entry->title,
            slug: $entry->slug,
            excerpt: $entry->excerpt,
            content: $entry->content,
            contentHtml: $contentHtml,
            contentText: $entry->contentText,
            authorId: $entry->authorId,
            parentId: $entry->parentId,
            menuOrder: $entry->menuOrder,
            template: $entry->template,
            publishedAt: $entry->publishedAt,
            previousStatus: $entry->previousStatus,
            trashedAt: $entry->trashedAt,
            createdAt: $entry->createdAt,
            updatedAt: $entry->updatedAt,
            meta: $entry->meta,
            terms: $entry->terms,
            authorName: $entry->authorName,
            authorEmail: $entry->authorEmail,
        );
        $copy = new PageContext(
            type: $page->type,
            path: $page->path,
            page: $page->page,
            perPage: $page->perPage,
            entry: $live,
            entries: $page->entries,
            totalEntries: $page->totalEntries,
            totalPages: $page->totalPages,
            contentType: $page->contentType,
            taxonomy: $page->taxonomy,
            term: $page->term,
            author: $page->author,
            searchQuery: $page->searchQuery,
            isPreview: $page->isPreview,
            isCustomizerPreview: $page->isCustomizerPreview,
            httpStatus: $page->httpStatus,
            redirectTo: $page->redirectTo,
            redirectStatus: $page->redirectStatus,
            extra: array_merge($page->extra, [self::MARKER => true]),
        );
        return $this->plugin->app()->get(SiteRenderer::class)->render($copy);
    }
}
