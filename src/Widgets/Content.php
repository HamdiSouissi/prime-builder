<?php

declare(strict_types=1);

namespace PrimeBuilder\Widgets;

use Prime\Content\ContentTypeRegistry;
use Prime\Content\Entry;
use Prime\Content\EntryQuery;
use Prime\Site\UrlGenerator;
use Prime\Site\Zone\DynamicBlockRegistry;
use Prime\Site\Zone\ZoneRepository;
use Prime\Themes\ThemeManager;

/** Read-only, public content provider. It shares a single bounded query per render. */
final class Content
{
    /** @var list<Entry>|null */
    private ?array $posts = null;
    private ?string $postArchive = null;

    public function __construct(
        private readonly EntryQuery $query,
        private readonly ContentTypeRegistry $types,
        private readonly UrlGenerator $urls,
        private readonly ?ThemeManager $themes = null,
        private readonly ?ZoneRepository $zones = null,
        private readonly ?DynamicBlockRegistry $dynamicBlocks = null,
    ) {
    }

    public function invalidate(): void
    {
        $this->posts = null;
    }

    /** @param array<string, mixed> $settings */
    public function posts(array $settings, bool $live): string
    {
        $type = $this->types->get('post');
        if ($type === null || !$type->public) {
            return '';
        }
        if (!$live) {
            // Never persist a list of titles/excerpts: a post may later become private.
            // The archive link is a useful, safe fallback when JavaScript or the plugin is disabled.
            $this->postArchive ??= $this->urls->archiveUrl('post');
            return '<p><a href="' . Renderer::escape($this->postArchive) . '">'
                . Renderer::escape(t('plugin::prime-builder.ui.posts_fallback')) . '</a></p>';
        }
        if ($this->posts === null) {
            $this->posts = $this->query->newQuery()->type('post')->orderBy('published_at', 'DESC')
                ->limit(12)->withRelations(false, false, false)->get();
        }
        $limit = max(1, min(12, (int) ($settings['limit'] ?? 3)));
        $items = array_slice($this->posts, 0, $limit);
        if ($items === []) {
            return '<p>' . Renderer::escape(t('plugin::prime-builder.ui.posts_empty')) . '</p>';
        }
        $html = '<ul class="pb-post-list">';
        foreach ($items as $post) {
            $html .= '<li><a href="' . Renderer::escape($this->urls->entryUrl($post)) . '">' . Renderer::escape($post->title) . '</a>';
            if (($settings['show_excerpt'] ?? 'hide') === 'show') {
                $excerpt = trim(preg_replace('/\s+/u', ' ', strip_tags($post->excerpt ?? $post->contentText)) ?? '');
                if (mb_strlen($excerpt, 'UTF-8') > 180) {
                    $excerpt = rtrim(mb_substr($excerpt, 0, 179, 'UTF-8')) . '…';
                }
                if ($excerpt !== '') {
                    $html .= '<p>' . Renderer::escape($excerpt) . '</p>';
                }
            }
            $html .= '</li>';
        }
        return $html . '</ul>';
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
