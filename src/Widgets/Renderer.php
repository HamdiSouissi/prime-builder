<?php

declare(strict_types=1);

namespace PrimeBuilder\Widgets;

use Prime\Content\Entry;
use Prime\Editor\BlockRegistry;
use Prime\Log\Logger;
use Prime\Security\HtmlSanitizer;
use PrimeBuilder\Document\HtmlContent;
use Throwable;

/** Render safe, useful static HTML so saved pages remain readable without this plugin. */
final class Renderer
{
    public function __construct(
        private readonly Registry $registry,
        private readonly HtmlSanitizer $sanitizer,
        private readonly ?Logger $logger = null,
        private readonly ?Content $content = null,
        private readonly ?Entry $entry = null,
        private readonly bool $live = false,
        private readonly ?BlockRegistry $blocks = null,
        private readonly ?OriginalContent $originals = null,
        private readonly ?HtmlContent $html = null,
        private readonly bool $trustedPreview = false,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function render(array $data): string
    {
        $elements = is_array($data['elements'] ?? null) ? $data['elements'] : [];
        $refresh = $this->entry !== null && $this->containsLive($elements)
            ? ' data-pb-live="/api/prime-builder/v1/documents/' . $this->entry->id . '/live"'
            : '';
        return '<div class="pb-layout"' . $refresh . '>' . $this->renderElements($elements, $this->registry->all()) . '</div>';
    }

    /** @param array<mixed> $elements */
    private function containsLive(array $elements): bool
    {
        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }
            if (in_array($element['type'] ?? null, ['posts', 'current_content'], true)) {
                return true;
            }
            if (is_array($element['children'] ?? null) && $this->containsLive($element['children'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Collect live widget bodies by stable element ID. Values are server-generated HTML.
     *
     * @param array<string, mixed> $data
     * @return array<string, string>
     */
    public function liveWidgets(array $data): array
    {
        $output = [];
        $walk = function (array $elements) use (&$walk, &$output): void {
            foreach ($elements as $element) {
                if (!is_array($element)) {
                    continue;
                }
                $id = $element['id'] ?? null;
                $type = $element['type'] ?? null;
                $settings = is_array($element['settings'] ?? null) ? $element['settings'] : [];
                if (is_string($id) && preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $id) === 1 && $this->content !== null) {
                    if ($type === 'posts') {
                        $output[$id] = $this->content->posts($settings, true);
                    } elseif ($type === 'current_content') {
                        $output[$id] = $this->content->current($settings, $this->entry);
                    }
                }
                if (is_array($element['children'] ?? null)) {
                    $walk($element['children']);
                }
            }
        };
        $walk(is_array($data['elements'] ?? null) ? $data['elements'] : []);
        return $output;
    }

    /**
     * @param array<mixed> $elements
     * @param array<string, array<string, mixed>> $definitions
     */
    private function renderElements(array $elements, array $definitions): string
    {
        $html = '';
        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }
            $type = is_string($element['type'] ?? null) ? $element['type'] : '';
            $id = is_string($element['id'] ?? null) ? $element['id'] : '';
            $settings = is_array($element['settings'] ?? null) ? $element['settings'] : [];
            $children = is_array($element['children'] ?? null) ? $element['children'] : [];
            if (!isset($definitions[$type]) || preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $id) !== 1) {
                continue;
            }
            try {
                $content = $this->renderWidget($type, $settings, $definitions[$type], $id);
                if (($definitions[$type]['container'] ?? false) === true) {
                    $content .= $this->renderElements($children, $definitions);
                }
                $hidden = ($element['hidden'] ?? false) === true ? ' hidden' : '';
                $html .= '<div class="pb-element pb-' . self::escape($type) . '" data-pb-id="' . self::escape($id) . '"' . $hidden . '>' . $content . '</div>';
            } catch (Throwable $exception) {
                $this->logger?->error('Prime Builder widget failed', ['widget' => $type, 'message' => $exception->getMessage()]);
            }
        }
        return $html;
    }

    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $definition
     */
    private function renderWidget(string $type, array $settings, array $definition, string $id): string
    {
        $text = self::escape(is_string($settings['text'] ?? null) ? $settings['text'] : '');
        $rich = $this->sanitizer->sanitizeBasic(is_string($settings['text'] ?? null) ? $settings['text'] : '');
        $url = is_string($settings['url'] ?? null) ? $settings['url'] : '';
        $href = $this->sanitizer->sanitizeHref($url) ?? '';
        $resource = $this->sanitizer->sanitizeResourceUrl($url) ?? '';
        $level = max(1, min(6, (int) ($settings['level'] ?? 2)));
        $width = max(1, min(10000, (int) ($settings['width'] ?? 800)));
        $height = max(1, min(10000, (int) ($settings['height'] ?? 600)));
        $value = max(0, min(100, (int) ($settings['value'] ?? 0)));
        $callback = $definition['render'] ?? null;
        if (is_callable($callback)) {
            // Extension code is trusted, but its returned user HTML still goes through the sanitizer.
            $result = $callback($settings, $this->sanitizer);
            return is_string($result) ? $this->sanitizer->sanitizeBasic($result) : '';
        }
        return match ($type) {
            'container', 'spacer' => '',
            'heading' => '<h' . $level . '>' . $text . '</h' . $level . '>',
            'text' => $rich,
            'html' => $this->renderHtmlWidget($id, $settings),
            'image' => $resource !== '' ? '<img src="' . self::escape($resource) . '" alt="' . self::escape((string) ($settings['alt'] ?? '')) . '" width="' . $width . '" height="' . $height . '" loading="lazy" decoding="async">' : '',
            'button' => $href !== '' ? '<a class="pb-button-link" href="' . self::escape($href) . '">' . $text . '</a>' : '',
            'separator' => '<hr>',
            'quote' => '<blockquote>' . $rich . (isset($settings['author']) && $settings['author'] !== '' ? '<cite>' . self::escape((string) $settings['author']) . '</cite>' : '') . '</blockquote>',
            'alert' => '<div class="pb-alert-content">' . $rich . '</div>',
            'progress' => '<label>' . $text . ' <progress max="100" value="' . $value . '">' . $value . '%</progress></label>',
            'anchor' => isset($settings['anchor']) && preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/', (string) $settings['anchor']) === 1 ? '<span id="' . self::escape((string) $settings['anchor']) . '"></span>' : '',
            'list' => $this->renderList($settings),
            'accordion' => '<details><summary>' . self::escape($this->fieldText($settings, 'title', t('plugin::prime-builder.widgets.accordion'))) . '</summary><div class="pb-accordion-content">' . $rich . '</div></details>',
            'card' => $this->renderCard($settings),
            'icon_box' => $this->renderIconBox($settings),
            'counter' => '<div class="pb-counter-value">' . self::escape($this->fieldText($settings, 'prefix')) . max(0, min(1000000000, (int) ($settings['value'] ?? 0))) . self::escape($this->fieldText($settings, 'suffix')) . '</div><p>' . self::escape($this->fieldText($settings, 'title')) . '</p>',
            'tabs' => $this->renderTabs($settings, $id),
            'gallery' => $this->renderGallery($settings),
            'video', 'audio' => $this->renderMedia($type, $settings),
            'posts' => $this->content?->posts($settings, $this->live) ?? '',
            'current_content' => $this->content?->current($settings, $this->entry) ?? '',
            'prime_block' => (new PrimeBlock($this->blocks ?? new BlockRegistry(), $this->sanitizer))->render($this->fieldText($settings, 'block_type', 'paragraph'), $this->fieldText($settings, 'block_data', '{}'), $id, $this->entry, is_string($settings['block_anchor'] ?? null) ? $settings['block_anchor'] : null),
            'original_content' => $this->renderOriginal($settings, false),
            'original_group' => $this->renderOriginal($settings, true),
            default => '',
        };
    }

    /** @param array<string, mixed> $settings */
    private function renderHtmlWidget(string $id, array $settings): string
    {
        $verified = $this->entry !== null && ($this->html?->valid($id, $settings, $this->entry->id) ?? false);
        if (!$this->trustedPreview && !$verified) {
            return '';
        }
        return $this->sanitizer->sanitizeBasic(is_string($settings['markup'] ?? null) ? $settings['markup'] : '');
    }

    /** @param array<string, mixed> $settings */
    private function renderOriginal(array $settings, bool $grouped): string
    {
        $payload = is_string($settings['payload'] ?? null) ? $settings['payload'] : '';
        $original = $this->originals?->open($payload);
        // Only HTML previously saved for this very entry is allowed to bypass the sanitizer.
        return $this->entry !== null && $original !== null && $original['entry'] === $this->entry->id && $original['grouped'] === $grouped ? $original['html'] : '';
    }

    /** @param array<string, mixed> $settings */
    private function fieldText(array $settings, string $key, string $fallback = ''): string
    {
        return is_string($settings[$key] ?? null) && trim($settings[$key]) !== '' ? $settings[$key] : $fallback;
    }

    /** @param array<string, mixed> $settings */
    private function renderList(array $settings): string
    {
        $tag = ($settings['list_type'] ?? '') === 'ordered' ? 'ol' : 'ul';
        $lines = preg_split('/\R/u', $this->fieldText($settings, 'items')) ?: [];
        $items = '';
        foreach (array_slice($lines, 0, 50) as $line) {
            if (trim($line) !== '') {
                $items .= '<li>' . self::escape(trim($line)) . '</li>';
            }
        }
        return '<' . $tag . '>' . $items . '</' . $tag . '>';
    }

    /** @param array<string, mixed> $settings */
    private function renderCard(array $settings): string
    {
        $image = $this->sanitizer->sanitizeResourceUrl($this->fieldText($settings, 'image_url'));
        $link = $this->sanitizer->sanitizeHref($this->fieldText($settings, 'url'));
        $html = '<article class="pb-card-content">';
        if ($image !== null) {
            $html .= '<img src="' . self::escape($image) . '" alt="' . self::escape($this->fieldText($settings, 'alt')) . '" loading="lazy" decoding="async">';
        }
        $title = $this->fieldText($settings, 'title');
        if ($title !== '') {
            $html .= '<h3>' . self::escape($title) . '</h3>';
        }
        $html .= $this->sanitizer->sanitizeBasic($this->fieldText($settings, 'text'));
        if ($link !== null) {
            $html .= '<a class="pb-button-link" href="' . self::escape($link) . '">' . self::escape($this->fieldText($settings, 'button_text', t('plugin::prime-builder.ui.read_more'))) . '</a>';
        }
        return $html . '</article>';
    }

    /** @param array<string, mixed> $settings */
    private function renderIconBox(array $settings): string
    {
        $icons = ['star' => '★', 'check' => '✓', 'heart' => '♥', 'spark' => '✦'];
        $icon = $settings['icon'] ?? 'star';
        $symbol = is_string($icon) ? ($icons[$icon] ?? $icons['star']) : $icons['star'];
        $title = $this->fieldText($settings, 'title');
        return '<div class="pb-icon-box-content"><span class="pb-icon-box-symbol" aria-hidden="true">' . $symbol . '</span>'
            . ($title !== '' ? '<h3>' . self::escape($title) . '</h3>' : '')
            . $this->sanitizer->sanitizeBasic($this->fieldText($settings, 'text')) . '</div>';
    }

    /** @param array<string, mixed> $settings */
    private function renderTabs(array $settings, string $id): string
    {
        $label = $this->fieldText($settings, 'label', t('plugin::prime-builder.widgets.tabs'));
        $html = '<div data-pb-tabs data-pb-tabs-label="' . self::escape($label) . '">';
        for ($i = 1; $i <= 3; $i++) {
            $title = $this->fieldText($settings, 'title_' . $i);
            $body = $this->fieldText($settings, 'text_' . $i);
            if ($i === 3 && $title === '' && $body === '') {
                continue;
            }
            if ($title === '') {
                $title = t('plugin::prime-builder.ui.tab_default') . ' ' . $i;
            }
            $html .= '<section data-pb-tab-panel id="pb-tab-' . self::escape($id) . '-' . $i . '"><h3 data-pb-tab-heading>' . self::escape($title) . '</h3>'
                . $this->sanitizer->sanitizeBasic($body) . '</section>';
        }
        return $html . '</div>';
    }

    /** @param array<string, mixed> $settings */
    private function renderGallery(array $settings): string
    {
        $lines = preg_split('/\R/u', $this->fieldText($settings, 'images')) ?: [];
        $html = '<div class="pb-gallery-grid">';
        foreach (array_slice($lines, 0, 20) as $line) {
            $parts = explode('|', $line, 2);
            $url = $this->sanitizer->sanitizeResourceUrl(trim($parts[0]));
            if ($url !== null) {
                $html .= '<img src="' . self::escape($url) . '" alt="' . self::escape(trim($parts[1] ?? '')) . '" loading="lazy" decoding="async">';
            }
        }
        return $html . '</div>';
    }

    /** @param array<string, mixed> $settings */
    private function renderMedia(string $type, array $settings): string
    {
        $url = $this->sanitizer->sanitizeResourceUrl($this->fieldText($settings, 'url'));
        $transcript = $this->sanitizer->sanitizeBasic($this->fieldText($settings, 'transcript'));
        if ($url === null) {
            return $transcript;
        }
        $html = '<' . $type . ' controls preload="metadata"><source src="' . self::escape($url) . '">';
        if ($type === 'video') {
            $captions = $this->sanitizer->sanitizeResourceUrl($this->fieldText($settings, 'captions'));
            $language = ($settings['caption_language'] ?? 'fr') === 'en' ? 'en' : 'fr';
            if ($captions !== null) {
                $html .= '<track kind="captions" src="' . self::escape($captions) . '" srclang="' . $language . '" label="' . self::escape(t('plugin::prime-builder.ui.caption_label')) . '">';
            }
        }
        $html .= '<a href="' . self::escape($url) . '">' . self::escape(t('plugin::prime-builder.ui.download_media')) . '</a></' . $type . '>';
        return $html . ($transcript !== '' ? '<div class="pb-media-transcript">' . $transcript . '</div>' : '');
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
