<?php

declare(strict_types=1);

namespace PrimeBuilder\Widgets;

use Prime\Content\Entry;
use Prime\Editor\BlockRegistry;
use Prime\Log\Logger;
use Prime\Security\HtmlSanitizer;
use Prime\Site\PageContext;
use PrimeBuilder\Document\HtmlContent;
use PrimeBuilder\Styles\Scope;
use Throwable;

/** Render safe, useful static HTML so saved pages remain readable without this plugin. */
final class Renderer
{
    private ?TableOfContents $tableOfContents = null;
    private string $styleOwner = '';

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
        private readonly ?PageContext $pageContext = null,
    ) {
    }

    /** @param array<string, mixed> $data */
    public function render(array $data): string
    {
        $elements = is_array($data['elements'] ?? null) ? $data['elements'] : [];
        $this->styleOwner = Scope::forLayout($data);
        $this->tableOfContents = $this->containsTableOfContents($elements) ? new TableOfContents($elements) : null;
        $refresh = $this->entry !== null && $this->containsLive($elements)
            ? ' data-pb-live="/api/prime-builder/v1/documents/' . $this->entry->id . '/live"'
            : '';
        return '<div class="pb-layout"' . $refresh . '>' . $this->renderElements($elements, $this->registry->all()) . '</div>';
    }

    /** @param array<mixed> $elements */
    private function containsTableOfContents(array $elements): bool
    {
        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }
            if (($element['type'] ?? null) === 'table_of_contents') {
                return true;
            }
            if (is_array($element['children'] ?? null) && $this->containsTableOfContents($element['children'])) {
                return true;
            }
        }
        return false;
    }

    /** @param array<mixed> $elements */
    private function containsLive(array $elements): bool
    {
        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }
            if (in_array($element['type'] ?? null, ['posts', 'current_content', 'theme_zone', 'search_form', 'navigation', 'breadcrumbs', 'site_title', 'site_logo', 'entry_title', 'entry_excerpt', 'entry_featured_image', 'entry_info', 'entry_navigation', 'entry_author'], true)) {
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
     * @param array<array-key, mixed> $query
     * @return array<string, string>
     */
    public function liveWidgets(array $data, array $query = []): array
    {
        $output = [];
        $walk = function (array $elements) use (&$walk, &$output, $query): void {
            foreach ($elements as $element) {
                if (!is_array($element)) {
                    continue;
                }
                $id = $element['id'] ?? null;
                $type = $element['type'] ?? null;
                $settings = is_array($element['settings'] ?? null) ? $element['settings'] : [];
                if (is_string($id) && preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $id) === 1 && $this->content !== null) {
                    if ($type === 'posts') {
                        $rawPage = $query['pb_page_' . $id] ?? null;
                        $rawPage = is_int($rawPage) ? (string) $rawPage : $rawPage;
                        $page = is_string($rawPage) && preg_match('/^[1-9][0-9]{0,3}$/D', $rawPage) === 1 && (int) $rawPage <= 1000 ? (int) $rawPage : 1;
                        $output[$id] = $this->content->posts($settings, true, $id, $page);
                    } elseif ($type === 'current_content') {
                        $output[$id] = $this->content->current($settings, $this->entry, true);
                    } elseif ($type === 'search_form') {
                        $output[$id] = $this->content->searchForm($settings, $id);
                    } elseif ($type === 'navigation') {
                        $output[$id] = $this->content->navigation($settings, $this->entry, true);
                    } elseif ($type === 'breadcrumbs') {
                        $output[$id] = $this->content->breadcrumbs($settings, $this->entry, true, $this->sanitizer);
                    } elseif ($type === 'site_title' || $type === 'site_logo') {
                        try {
                            $output[$id] = $type === 'site_title' ? $this->content->siteTitle($settings) : $this->content->siteLogo($settings);
                        } catch (Throwable $exception) {
                            // Keep the saved fallback when the identity service fails.
                            $this->logger?->error('Prime Builder site identity failed', ['widget' => $type, 'message' => $exception->getMessage()]);
                        }
                    } elseif (in_array($type, ['entry_title', 'entry_excerpt', 'entry_featured_image', 'entry_info', 'entry_navigation', 'entry_author'], true)) {
                        try {
                            $output[$id] = $this->content->entryWidget($type, $settings, $this->entry, true);
                        } catch (Throwable $exception) {
                            // Do not erase the saved fallback if core services fail.
                            $this->logger?->error('Prime Builder entry widget failed', ['widget' => $type, 'message' => $exception->getMessage()]);
                        }
                    } elseif ($type === 'theme_zone') {
                        try {
                            $output[$id] = $this->content->themeZone($settings, true);
                        } catch (Throwable $exception) {
                            $this->logger?->error('Prime Builder theme zone failed', ['message' => $exception->getMessage()]);
                            $output[$id] = '';
                        }
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
                $target = $this->tableOfContents?->target($id);
                $anchor = $target !== null ? ' id="' . self::escape($target) . '" tabindex="-1"' : '';
                $html .= '<div class="pb-element pb-' . self::escape($type) . '" data-pb-owner="' . $this->styleOwner . '" data-pb-id="' . self::escape($id) . '"' . $anchor . $hidden . '>' . $content . '</div>';
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
            'icon' => $this->renderIcon($settings),
            'icon_list' => $this->renderIconList($settings),
            'image_box' => $this->renderImageBox($settings),
            'testimonial' => $this->renderTestimonial($settings),
            'testimonial_carousel' => $this->renderTestimonialCarousel($settings),
            'media_carousel' => $this->renderMediaCarousel($settings),
            'slideshow' => $this->renderSlideshow($settings),
            'portfolio' => $this->renderPortfolio($settings),
            'review' => $this->renderReview($settings),
            'flip_box' => $this->renderFlipBox($settings),
            'animated_heading' => $this->renderAnimatedHeading($settings),
            'code' => $this->renderCode($settings),
            'call_to_action' => $this->renderCallToAction($settings),
            'price_table' => $this->renderPriceTable($settings),
            'price_list' => $this->renderPriceList($settings),
            'countdown' => $this->renderCountdown($settings),
            'toggle' => '<details' . (($settings['initial'] ?? 'closed') === 'open' ? ' open' : '') . '><summary>' . self::escape($this->fieldText($settings, 'title', t('plugin::prime-builder.widgets.toggle'))) . '</summary><div>' . $rich . '</div></details>',
            'social' => $this->renderSocial($settings),
            'map' => $this->renderMap($settings),
            'star_rating' => $this->renderStarRating($settings),
            'counter' => '<div class="pb-counter-value">' . self::escape($this->fieldText($settings, 'prefix')) . max(0, min(1000000000, (int) ($settings['value'] ?? 0))) . self::escape($this->fieldText($settings, 'suffix')) . '</div><p>' . self::escape($this->fieldText($settings, 'title')) . '</p>',
            'tabs' => $this->renderTabs($settings, $id),
            'gallery' => $this->renderGallery($settings),
            'image_carousel' => $this->renderImageCarousel($settings),
            'video', 'audio' => $this->renderMedia($type, $settings),
            'posts' => $this->content?->posts($settings, $this->live, $id) ?? '',
            'current_content' => $this->content?->current($settings, $this->entry, $this->live) ?? '',
            'search_form' => $this->content?->searchForm($settings, $id) ?? '',
            'navigation' => $this->pageContext !== null
                ? ($this->content?->navigationInPage($settings, $this->pageContext) ?? Navigation::render($settings, null, false))
                : ($this->content?->navigation($settings, $this->entry, $this->live) ?? Navigation::render($settings, null, false)),
            'breadcrumbs' => $this->pageContext !== null
                ? ($this->content?->breadcrumbsInPage($settings, $this->pageContext, $this->sanitizer) ?? '')
                : ($this->content?->breadcrumbs($settings, $this->entry, $this->live, $this->sanitizer, $this->trustedPreview) ?? Breadcrumbs::fallback($settings)),
            'site_title' => $this->content?->siteTitle($settings) ?? '',
            'site_logo' => $this->content?->siteLogo($settings) ?? '',
            'entry_title', 'entry_excerpt', 'entry_featured_image', 'entry_info', 'entry_navigation', 'entry_author' => $this->pageContext !== null
                ? ($this->content?->entryWidgetInPage($type, $settings, $this->pageContext) ?? '')
                : ($this->content?->entryWidget($type, $settings, $this->entry, $this->live) ?? ''),
            'entry_content' => $this->pageContext !== null
                ? ($this->content?->entryContentInPage($this->pageContext) ?? '')
                : ($this->live && $this->trustedPreview ? '<p class="pb-entry-content__preview">' . self::escape(t('plugin::prime-builder.ui.entry_content_preview')) . '</p>' : ''),
            'archive_title', 'archive_list' => $this->pageContext !== null
                ? ($this->content?->archiveWidget($type, $settings, $this->pageContext) ?? '')
                : ($this->live && $this->trustedPreview ? '<p class="pb-archive-preview">' . self::escape(t('plugin::prime-builder.ui.archive_preview')) . '</p>' : ''),
            'share_buttons' => $this->pageContext !== null
                ? ($this->content?->shareButtonsInPage($settings, $this->pageContext) ?? ShareButtons::render($settings, '', false))
                : ($this->content?->shareButtons($settings, $this->entry) ?? ShareButtons::render($settings, '')),
            'table_of_contents' => ($this->tableOfContents ?? new TableOfContents([]))->render($settings),
            'theme_zone' => $this->content?->themeZone($settings, $this->live) ?? '<p>' . self::escape(t('plugin::prime-builder.ui.theme_zone_fallback')) . '</p>',
            'form' => $this->renderForm($settings, $id),
            'prime_block' => (new PrimeBlock($this->blocks ?? new BlockRegistry(), $this->sanitizer))->render($this->fieldText($settings, 'block_type', 'paragraph'), $this->fieldText($settings, 'block_data', '{}'), $id, $this->entry, is_string($settings['block_anchor'] ?? null) ? $settings['block_anchor'] : null),
            'original_content' => $this->renderOriginal($settings, false),
            'original_group' => $this->renderOriginal($settings, true),
            default => '',
        };
    }

    /**
     * This first form has fixed fields. The start token is fetched anonymously by JS,
     * never embedded in saved or cached HTML. The server checks the published entry.
     *
     * @param array<string, mixed> $settings
     */
    private function renderForm(array $settings, string $id): string
    {
        $entryId = $this->entry->id ?? 0;
        $prefix = 'pb-form-' . $id;
        $title = $this->fieldText($settings, 'title');
        $copy = static fn (string $key): string => self::escape(t('plugin::prime-builder.forms.' . $key));
        return '<form class="pb-contact-form" method="post" action="/api/prime-builder/v1/forms/' . $entryId . '/submit" data-pb-form data-pb-node="' . self::escape($id) . '"'
            . ' data-pb-error="' . $copy('error') . '" data-pb-wait="' . $copy('wait') . '" data-pb-sent="' . $copy('sent') . '" data-pb-limited="' . $copy('limited') . '">'
            . ($title !== '' ? '<h3>' . self::escape($title) . '</h3>' : '')
            . '<p>' . $copy('required') . '</p>'
            . '<label for="' . self::escape($prefix) . '-name">' . $copy('name') . '</label><input id="' . self::escape($prefix) . '-name" name="name" type="text" autocomplete="name" maxlength="160" required>'
            . '<label for="' . self::escape($prefix) . '-email">' . $copy('email') . '</label><input id="' . self::escape($prefix) . '-email" name="email" type="email" autocomplete="email" maxlength="254" required>'
            . '<label for="' . self::escape($prefix) . '-message">' . $copy('message') . '</label><textarea id="' . self::escape($prefix) . '-message" name="message" rows="5" maxlength="5000" required></textarea>'
            . '<div class="pb-contact-trap" aria-hidden="true"><label for="' . self::escape($prefix) . '-website">' . $copy('trap') . '</label><input id="' . self::escape($prefix) . '-website" name="website" type="text" tabindex="-1" autocomplete="off"></div>'
            . '<button class="pb-contact-submit" type="submit" disabled>' . self::escape($this->fieldText($settings, 'button_text', t('plugin::prime-builder.forms.submit'))) . '</button>'
            . '<p class="pb-contact-status" role="status" aria-live="polite" data-pb-form-status></p>'
            . '<noscript><p>' . $copy('javascript') . '</p></noscript></form>';
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
    private function renderAnimatedHeading(array $settings): string
    {
        $level = max(1, min(6, (int) ($settings['level'] ?? 2)));
        $effect = ($settings['effect'] ?? '') === 'rise' ? 'rise' : 'underline';
        $parts = [];
        $before = $this->fieldText($settings, 'before');
        $highlight = $this->fieldText($settings, 'highlight');
        $after = $this->fieldText($settings, 'after');
        if ($before !== '') {
            $parts[] = self::escape($before);
        }
        if ($highlight !== '') {
            $parts[] = '<span class="pb-animated-highlight pb-animated-highlight--' . $effect . '">' . self::escape($highlight) . '</span>';
        }
        if ($after !== '') {
            $parts[] = self::escape($after);
        }
        return $parts === [] ? '' : '<h' . $level . ' class="pb-animated-heading">' . implode(' ', $parts) . '</h' . $level . '>';
    }

    /** @param array<string, mixed> $settings */
    private function renderCode(array $settings): string
    {
        $language = is_string($settings['language'] ?? null) ? $settings['language'] : 'text';
        if (!in_array($language, ['text', 'html', 'css', 'javascript', 'json', 'php'], true)) {
            $language = 'text';
        }
        $source = is_string($settings['source'] ?? null) ? $settings['source'] : '';
        $label = $this->fieldText($settings, 'label', t('plugin::prime-builder.ui.code_default_label'));
        return '<pre class="pb-code-content" role="region" aria-label="' . self::escape($label) . '" tabindex="0"><code class="language-' . $language . '">'
            . CodeHighlighter::render($source, $language) . '</code></pre>';
    }

    /** @param array<string, mixed> $settings */
    private function renderCountdown(array $settings): string
    {
        $deadline = $this->fieldText($settings, 'deadline');
        $canonical = Countdown::canonical($deadline);
        if ($canonical === null) {
            return '<p>' . self::escape(t('plugin::prime-builder.ui.countdown_choose')) . '</p>';
        }
        $title = $this->fieldText($settings, 'title', t('plugin::prime-builder.widgets.countdown'));
        $level = max(2, min(6, (int) ($settings['level'] ?? 2)));
        $finished = $this->fieldText($settings, 'finished_text', t('plugin::prime-builder.ui.countdown_finished'));
        $html = '<div class="pb-countdown-widget" data-pb-countdown data-pb-deadline="' . self::escape($canonical) . '" data-pb-finished="' . self::escape($finished) . '">'
            . '<h' . $level . '>' . self::escape($title) . '</h' . $level . '><p class="pb-countdown-date">' . self::escape(t('plugin::prime-builder.ui.countdown_date_label'))
            . ' <time datetime="' . self::escape($canonical) . '">' . self::escape(str_replace('T', ' ', $deadline) . ' UTC') . '</time></p>'
            . '<div class="pb-countdown-values" data-pb-countdown-values role="group" aria-label="' . self::escape(t('plugin::prime-builder.ui.countdown_remaining') . ' : ' . $title) . '" hidden>';
        foreach (['day', 'hour', 'minute', 'second'] as $unit) {
            $one = t('plugin::prime-builder.ui.countdown_' . $unit . '_one');
            $many = t('plugin::prime-builder.ui.countdown_' . $unit . '_many');
            $html .= '<span class="pb-countdown-unit"><strong data-pb-countdown-value="' . $unit . '">0</strong>'
                . '<span data-pb-countdown-label="' . $unit . '" data-pb-one="' . self::escape($one) . '" data-pb-many="' . self::escape($many) . '">' . self::escape($many) . '</span></span>';
        }
        return $html . '</div><p class="pb-countdown-status" role="status" data-pb-countdown-status></p></div>';
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
        $symbol = $this->iconSymbol($settings);
        $title = $this->fieldText($settings, 'title');
        return '<div class="pb-icon-box-content"><span class="pb-icon-box-symbol" aria-hidden="true">' . $symbol . '</span>'
            . ($title !== '' ? '<h3>' . self::escape($title) . '</h3>' : '')
            . $this->sanitizer->sanitizeBasic($this->fieldText($settings, 'text')) . '</div>';
    }

    /** @param array<string, mixed> $settings */
    private function iconSymbol(array $settings): string
    {
        $icons = ['star' => '★', 'check' => '✓', 'heart' => '♥', 'spark' => '✦'];
        $icon = $settings['icon'] ?? 'star';
        return is_string($icon) ? ($icons[$icon] ?? $icons['star']) : $icons['star'];
    }

    /** @param array<string, mixed> $settings */
    private function renderIcon(array $settings): string
    {
        $label = $this->fieldText($settings, 'accessible_label');
        $attributes = $label === '' ? ' aria-hidden="true"' : ' role="img" aria-label="' . self::escape($label) . '"';
        return '<span' . $attributes . '>' . $this->iconSymbol($settings) . '</span>';
    }

    /** @param array<string, mixed> $settings */
    private function renderIconList(array $settings): string
    {
        $lines = preg_split('/\R/u', $this->fieldText($settings, 'items')) ?: [];
        $items = '';
        foreach (array_slice($lines, 0, 50) as $line) {
            if (trim($line) !== '') {
                $items .= '<li><span aria-hidden="true">' . $this->iconSymbol($settings) . '</span> ' . self::escape(trim($line)) . '</li>';
            }
        }
        return $items === '' ? '' : '<ul>' . $items . '</ul>';
    }

    /** @param array<string, mixed> $settings */
    private function renderImageBox(array $settings): string
    {
        $image = $this->sanitizer->sanitizeResourceUrl($this->fieldText($settings, 'image_url'));
        $width = max(1, min(10000, (int) ($settings['width'] ?? 800)));
        $height = max(1, min(10000, (int) ($settings['height'] ?? 600)));
        $title = $this->fieldText($settings, 'title');
        $url = $this->sanitizer->sanitizeHref($this->fieldText($settings, 'url'));
        $html = '<div>';
        if ($image !== null && $image !== '') {
            $html .= '<img src="' . self::escape($image) . '" alt="' . self::escape($this->fieldText($settings, 'alt')) . '" width="' . $width . '" height="' . $height . '" loading="lazy" decoding="async">';
        }
        if ($title !== '') {
            $html .= '<h3>' . self::escape($title) . '</h3>';
        }
        $html .= $this->sanitizer->sanitizeBasic($this->fieldText($settings, 'text'));
        if ($url !== null && $url !== '') {
            $html .= '<a class="pb-button-link" href="' . self::escape($url) . '">' . self::escape($this->fieldText($settings, 'button_text', t('plugin::prime-builder.ui.read_more'))) . '</a>';
        }
        return $html . '</div>';
    }

    /** @param array<string, mixed> $settings */
    private function renderTestimonial(array $settings): string
    {
        $image = $this->sanitizer->sanitizeResourceUrl($this->fieldText($settings, 'image_url'));
        $author = $this->fieldText($settings, 'author');
        $job = $this->fieldText($settings, 'job_title');
        $html = '<figure><blockquote>' . $this->sanitizer->sanitizeBasic($this->fieldText($settings, 'text')) . '</blockquote>';
        if (($image !== null && $image !== '') || $author !== '' || $job !== '') {
            $html .= '<figcaption>';
            if ($image !== null && $image !== '') {
                $width = max(1, min(10000, (int) ($settings['width'] ?? 100)));
                $height = max(1, min(10000, (int) ($settings['height'] ?? 100)));
                $html .= '<img src="' . self::escape($image) . '" alt="' . self::escape($this->fieldText($settings, 'alt')) . '" width="' . $width . '" height="' . $height . '" loading="lazy" decoding="async"> ';
            }
            if ($author !== '') {
                $html .= '<cite>' . self::escape($author) . '</cite>';
            }
            if ($job !== '') {
                $html .= ' <span>' . self::escape($job) . '</span>';
            }
            $html .= '</figcaption>';
        }
        return $html . '</figure>';
    }

    /** @param array<string, mixed> $settings */
    private function renderTestimonialCarousel(array $settings): string
    {
        $rows = Testimonials::parse(is_string($settings['items'] ?? null) ? $settings['items'] : '', $this->sanitizer);
        if ($rows === null || $rows === []) {
            return '<p>' . self::escape(t('plugin::prime-builder.ui.testimonials_empty')) . '</p>';
        }
        $label = $this->fieldText($settings, 'label', t('plugin::prime-builder.widgets.testimonial_carousel'));
        $copy = static fn (string $key): string => self::escape(t('plugin::prime-builder.ui.' . $key));
        $html = '<div class="pb-testimonials" data-pb-carousel data-pb-carousel-label="' . self::escape($label) . '"'
            . ' data-pb-carousel-role="' . $copy('carousel_role') . '" data-pb-carousel-slide-role="' . $copy('carousel_slide_role') . '"'
            . ' data-pb-carousel-previous="' . $copy('testimonials_previous') . '" data-pb-carousel-next="' . $copy('testimonials_next') . '"'
            . ' data-pb-carousel-position="' . $copy('testimonials_position') . '"><div class="pb-testimonials-slides">';
        foreach ($rows as $row) {
            $html .= '<div data-pb-slide><figure><blockquote><p>' . self::escape($row['text']) . '</p></blockquote>';
            if ($row['author'] !== '' || $row['job'] !== '' || $row['image'] !== '') {
                $html .= '<figcaption>';
                if ($row['image'] !== '') {
                    $html .= '<img src="' . self::escape($row['image']) . '" alt="" width="96" height="96" loading="lazy" decoding="async">';
                }
                if ($row['author'] !== '' || $row['job'] !== '') {
                    $html .= '<span>' . ($row['author'] !== '' ? '<cite>' . self::escape($row['author']) . '</cite>' : '')
                        . ($row['job'] !== '' ? '<span>' . self::escape($row['job']) . '</span>' : '') . '</span>';
                }
                $html .= '</figcaption>';
            }
            $html .= '</figure></div>';
        }
        return $html . '</div></div>';
    }

    /** @param array<string, mixed> $settings */
    private function renderMediaCarousel(array $settings): string
    {
        $rows = MediaCarousel::parse(is_string($settings['items'] ?? null) ? $settings['items'] : '', $this->sanitizer);
        if ($rows === null || $rows === []) {
            return '<p>' . self::escape(t('plugin::prime-builder.ui.media_carousel_empty')) . '</p>';
        }
        $label = $this->fieldText($settings, 'label', t('plugin::prime-builder.widgets.media_carousel'));
        $copy = static fn (string $key): string => self::escape(t('plugin::prime-builder.ui.' . $key));
        $language = ($settings['caption_language'] ?? 'fr') === 'en' ? 'en' : 'fr';
        $html = '<div class="pb-media-carousel" data-pb-carousel data-pb-carousel-label="' . self::escape($label) . '"'
            . ' data-pb-carousel-role="' . $copy('carousel_role') . '" data-pb-carousel-slide-role="' . $copy('carousel_slide_role') . '"'
            . ' data-pb-carousel-previous="' . $copy('media_carousel_previous') . '" data-pb-carousel-next="' . $copy('media_carousel_next') . '"'
            . ' data-pb-carousel-position="' . $copy('media_carousel_position') . '"><div class="pb-media-carousel-slides">';
        foreach ($rows as $row) {
            $html .= '<div data-pb-slide><figure>';
            if ($row['type'] === 'image') {
                $html .= '<img src="' . self::escape($row['url']) . '" alt="' . self::escape($row['description']) . '" loading="lazy" decoding="async">';
                if ($row['caption'] !== '') {
                    $html .= '<figcaption>' . self::escape($row['caption']) . '</figcaption>';
                }
            } else {
                $html .= '<video controls preload="none" aria-label="' . self::escape($row['description']) . '"><source src="' . self::escape($row['url']) . '">';
                if ($row['captions'] !== '') {
                    $html .= '<track kind="captions" src="' . self::escape($row['captions']) . '" srclang="' . $language . '" label="' . $copy('caption_label') . '">';
                }
                $html .= '<a href="' . self::escape($row['url']) . '">' . $copy('download_media') . '</a></video>';
                $html .= '<figcaption>' . self::escape($row['description'])
                    . ($row['transcript'] !== '' ? '<p>' . self::escape($row['transcript']) . '</p>' : '') . '</figcaption>';
            }
            $html .= '</figure></div>';
        }
        return $html . '</div></div>';
    }

    /** @param array<string, mixed> $settings */
    private function renderSlideshow(array $settings): string
    {
        $slides = Slideshow::parse(is_string($settings['items'] ?? null) ? $settings['items'] : '', $this->sanitizer);
        if ($slides === null || $slides === []) {
            return '<p>' . self::escape(t('plugin::prime-builder.ui.slideshow_empty')) . '</p>';
        }
        $label = $this->fieldText($settings, 'label', t('plugin::prime-builder.widgets.slideshow'));
        $level = max(2, min(6, (int) ($settings['level'] ?? 2)));
        $copy = static fn (string $key): string => self::escape(t('plugin::prime-builder.ui.' . $key));
        $html = '<div class="pb-slide-show" data-pb-carousel data-pb-carousel-label="' . self::escape($label) . '"'
            . ' data-pb-carousel-role="' . $copy('carousel_role') . '" data-pb-carousel-slide-role="' . $copy('carousel_slide_role') . '"'
            . ' data-pb-carousel-previous="' . $copy('slideshow_previous') . '" data-pb-carousel-next="' . $copy('slideshow_next') . '"'
            . ' data-pb-carousel-position="' . $copy('slideshow_position') . '"><div class="pb-slideshow-slides">';
        foreach ($slides as $slide) {
            $html .= '<article class="pb-slideshow-slide" data-pb-slide><img src="' . self::escape($slide['image']) . '" alt="'
                . self::escape($slide['alt']) . '" width="1600" height="900" loading="lazy" decoding="async">';
            if ($slide['title'] !== '' || $slide['description'] !== '' || $slide['link'] !== '') {
                $html .= '<div class="pb-slideshow-content">';
                if ($slide['title'] !== '') {
                    $html .= '<h' . $level . '>' . self::escape($slide['title']) . '</h' . $level . '>';
                }
                if ($slide['description'] !== '') {
                    $html .= '<p>' . self::escape($slide['description']) . '</p>';
                }
                if ($slide['link'] !== '') {
                    $html .= '<a href="' . self::escape($slide['link']) . '">' . self::escape($slide['link_text']) . '</a>';
                }
                $html .= '</div>';
            }
            $html .= '</article>';
        }
        return $html . '</div></div>';
    }

    /** @param array<string, mixed> $settings */
    private function renderPortfolio(array $settings): string
    {
        $items = Portfolio::parse(is_string($settings['items'] ?? null) ? $settings['items'] : '', $this->sanitizer);
        if ($items === null || $items === []) {
            return '<p>' . self::escape(t('plugin::prime-builder.ui.portfolio_empty')) . '</p>';
        }
        $columns = max(1, min(4, (int) ($settings['columns'] ?? 3)));
        $level = max(2, min(6, (int) ($settings['level'] ?? 3)));
        $copy = static fn (string $key): string => self::escape(t('plugin::prime-builder.ui.' . $key));
        $html = '<div data-pb-portfolio data-pb-portfolio-filter-label="' . $copy('portfolio_filter_label') . '"'
            . ' data-pb-portfolio-all="' . $copy('portfolio_all') . '" data-pb-portfolio-category="' . $copy('portfolio_category') . '"'
            . ' data-pb-portfolio-single="' . $copy('portfolio_single') . '" data-pb-portfolio-plural="' . $copy('portfolio_plural') . '">'
            . '<ul class="pb-portfolio-list pb-portfolio-columns-' . $columns . '">';
        foreach ($items as $item) {
            $title = self::escape($item['title']);
            $html .= '<li class="pb-portfolio-item" data-pb-portfolio-item data-pb-category="' . self::escape($item['category']) . '"><article>'
                . '<img src="' . self::escape($item['image']) . '" alt="' . self::escape($item['alt']) . '" width="800" height="600" loading="lazy" decoding="async">'
                . '<div class="pb-portfolio-copy"><p class="pb-portfolio-category">' . self::escape($item['category']) . '</p><h' . $level . '>'
                . ($item['link'] !== '' ? '<a href="' . self::escape($item['link']) . '">' . $title . '</a>' : $title)
                . '</h' . $level . '>';
            if ($item['description'] !== '') {
                $html .= '<p>' . self::escape($item['description']) . '</p>';
            }
            $html .= '</div></article></li>';
        }
        return $html . '</ul></div>';
    }

    /** @param array<string, mixed> $settings */
    private function renderReview(array $settings): string
    {
        $title = $this->fieldText($settings, 'title');
        $level = max(2, min(6, (int) ($settings['level'] ?? 2)));
        $rating = max(0, min(5, (int) ($settings['rating'] ?? 5)));
        $author = $this->fieldText($settings, 'author');
        $job = $this->fieldText($settings, 'job_title');
        $image = $this->sanitizer->sanitizeResourceUrl($this->fieldText($settings, 'image_url'));
        $html = '<article class="pb-review-card">';
        if ($title !== '') {
            $html .= '<h' . $level . '>' . self::escape($title) . '</h' . $level . '>';
        }
        $html .= '<p class="pb-review-rating">' . $this->renderStarRating(['value' => $rating]) . '</p>'
            . '<blockquote>' . $this->sanitizer->sanitizeBasic($this->fieldText($settings, 'text')) . '</blockquote>';
        if (($image !== null && $image !== '') || $author !== '' || $job !== '') {
            $html .= '<div class="pb-review-credit">';
            if ($image !== null && $image !== '') {
                $width = max(1, min(10000, (int) ($settings['width'] ?? 100)));
                $height = max(1, min(10000, (int) ($settings['height'] ?? 100)));
                $html .= '<img src="' . self::escape($image) . '" alt="' . self::escape($this->fieldText($settings, 'alt')) . '" width="' . $width . '" height="' . $height . '" loading="lazy" decoding="async">';
            }
            if ($author !== '' || $job !== '') {
                $html .= '<p>' . ($author !== '' ? '<cite>' . self::escape($author) . '</cite>' : '')
                    . ($job !== '' ? '<span>' . self::escape($job) . '</span>' : '') . '</p>';
            }
            $html .= '</div>';
        }
        return $html . '</article>';
    }

    /** @param array<string, mixed> $settings */
    private function renderFlipBox(array $settings): string
    {
        $front = $this->fieldText($settings, 'front_title', t('plugin::prime-builder.ui.flip_front_default'));
        $back = $this->fieldText($settings, 'back_title', t('plugin::prime-builder.ui.flip_back_default'));
        $level = max(2, min(6, (int) ($settings['level'] ?? 2)));
        $url = $this->sanitizer->sanitizeHref($this->fieldText($settings, 'url'));
        $html = '<div class="pb-flip-box" data-pb-flip data-pb-flip-context="' . self::escape($front) . '"'
            . ' data-pb-show-back="' . self::escape(t('plugin::prime-builder.ui.flip_show_back')) . '"'
            . ' data-pb-show-front="' . self::escape(t('plugin::prime-builder.ui.flip_show_front')) . '">'
            . '<div class="pb-flip-face pb-flip-front" data-pb-flip-front><h' . $level . '>' . self::escape($front) . '</h' . $level . '>'
            . $this->sanitizer->sanitizeBasic($this->fieldText($settings, 'front_text')) . '</div>'
            . '<div class="pb-flip-face pb-flip-back" data-pb-flip-back><h' . $level . '>' . self::escape($back) . '</h' . $level . '>'
            . $this->sanitizer->sanitizeBasic($this->fieldText($settings, 'back_text'));
        if ($url !== null && $url !== '') {
            $html .= '<a class="pb-flip-link" href="' . self::escape($url) . '">' . self::escape($this->fieldText($settings, 'button_text', t('plugin::prime-builder.ui.read_more'))) . '</a>';
        }
        return $html . '</div></div>';
    }

    /** @param array<string, mixed> $settings */
    private function renderCallToAction(array $settings): string
    {
        $title = $this->fieldText($settings, 'title');
        $level = max(2, min(6, (int) ($settings['level'] ?? 2)));
        $image = $this->sanitizer->sanitizeResourceUrl($this->fieldText($settings, 'image_url'));
        $url = $this->sanitizer->sanitizeHref($this->fieldText($settings, 'url'));
        $html = '<div class="pb-cta-content">';
        if ($image !== null && $image !== '') {
            $width = max(1, min(10000, (int) ($settings['width'] ?? 800)));
            $height = max(1, min(10000, (int) ($settings['height'] ?? 600)));
            $html .= '<img src="' . self::escape($image) . '" alt="' . self::escape($this->fieldText($settings, 'alt')) . '" width="' . $width . '" height="' . $height . '" loading="lazy" decoding="async">';
        }
        $html .= '<div class="pb-cta-copy">';
        if ($title !== '') {
            $html .= '<h' . $level . '>' . self::escape($title) . '</h' . $level . '>';
        }
        $html .= $this->sanitizer->sanitizeBasic($this->fieldText($settings, 'text'));
        if ($url !== null && $url !== '') {
            $html .= '<a class="pb-button-link" href="' . self::escape($url) . '">' . self::escape($this->fieldText($settings, 'button_text', t('plugin::prime-builder.ui.read_more'))) . '</a>';
        }
        return $html . '</div></div>';
    }

    /** @param array<string, mixed> $settings */
    private function renderPriceTable(array $settings): string
    {
        $title = $this->fieldText($settings, 'title');
        $level = max(2, min(6, (int) ($settings['level'] ?? 2)));
        $price = $this->fieldText($settings, 'price');
        $period = $this->fieldText($settings, 'period');
        $url = $this->sanitizer->sanitizeHref($this->fieldText($settings, 'url'));
        $html = '<div class="pb-price-table-content">';
        if ($title !== '') {
            $html .= '<h' . $level . '>' . self::escape($title) . '</h' . $level . '>';
        }
        $html .= $this->sanitizer->sanitizeBasic($this->fieldText($settings, 'text'));
        if ($price !== '' || $period !== '') {
            $html .= '<p class="pb-price-table-amount"><strong>' . self::escape($price) . '</strong>' . ($period !== '' ? ' <span>' . self::escape($period) . '</span>' : '') . '</p>';
        }
        $features = preg_split('/\R/u', $this->fieldText($settings, 'features')) ?: [];
        $items = '';
        foreach ($features as $feature) {
            if (trim($feature) !== '') {
                $items .= '<li>' . self::escape(trim(strip_tags($feature))) . '</li>';
            }
        }
        if ($items !== '') {
            $html .= '<ul>' . $items . '</ul>';
        }
        if ($url !== null && $url !== '') {
            $html .= '<a class="pb-button-link" href="' . self::escape($url) . '">' . self::escape($this->fieldText($settings, 'button_text', t('plugin::prime-builder.ui.read_more'))) . '</a>';
        }
        return $html . '</div>';
    }

    /** @param array<string, mixed> $settings */
    private function renderPriceList(array $settings): string
    {
        $title = $this->fieldText($settings, 'title');
        $level = max(2, min(6, (int) ($settings['level'] ?? 2)));
        $html = '<div class="pb-price-list-content">';
        if ($title !== '') {
            $html .= '<h' . $level . '>' . self::escape($title) . '</h' . $level . '>';
        }
        $items = '';
        $lines = preg_split('/\R/u', $this->fieldText($settings, 'items')) ?: [];
        foreach (array_slice($lines, 0, 30) as $line) {
            $parts = array_map(static fn (string $part): string => trim(strip_tags($part)), explode('|', $line));
            if (count($parts) < 2 || count($parts) > 3 || $parts[0] === '' || $parts[1] === '') {
                continue;
            }
            $items .= '<div><dt>' . self::escape($parts[0]) . '</dt><dd><strong>' . self::escape($parts[1]) . '</strong>'
                . (($parts[2] ?? '') !== '' ? '<span>' . self::escape($parts[2]) . '</span>' : '') . '</dd></div>';
        }
        return $html . ($items !== '' ? '<dl>' . $items . '</dl>' : '') . '</div>';
    }

    /** @param array<string, mixed> $settings */
    private function renderSocial(array $settings): string
    {
        $links = '';
        foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'youtube' => 'YouTube', 'mastodon' => 'Mastodon'] as $key => $name) {
            $url = $this->sanitizer->sanitizeHref($this->fieldText($settings, $key));
            if ($url !== null && $url !== '') {
                $links .= '<li><a href="' . self::escape($url) . '">' . $name . '</a></li>';
            }
        }
        return $links === '' ? '' : '<nav aria-label="' . self::escape($this->fieldText($settings, 'label', t('plugin::prime-builder.widgets.social'))) . '"><ul>' . $links . '</ul></nav>';
    }

    /** @param array<string, mixed> $settings */
    private function renderStarRating(array $settings): string
    {
        $value = max(0, min(5, (int) ($settings['value'] ?? 0)));
        $label = t('plugin::prime-builder.ui.star_rating_value', ['value' => $value]);
        return '<span role="img" aria-label="' . self::escape($label) . '"><span aria-hidden="true">' . str_repeat('★', $value) . str_repeat('☆', 5 - $value) . '</span></span>';
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
        $copy = static fn (string $key): string => self::escape(t('plugin::prime-builder.ui.gallery_' . $key));
        $html = '<div class="pb-gallery-grid" data-pb-gallery data-pb-gallery-dialog="' . $copy('dialog') . '"'
            . ' data-pb-gallery-close="' . $copy('close') . '" data-pb-gallery-previous="' . $copy('previous') . '"'
            . ' data-pb-gallery-next="' . $copy('next') . '" data-pb-gallery-position="' . $copy('position') . '">';
        foreach (array_slice($lines, 0, 20) as $index => $line) {
            $parts = explode('|', $line, 2);
            $url = $this->sanitizer->sanitizeResourceUrl(trim($parts[0]));
            if ($url !== null) {
                $alt = trim(strip_tags($parts[1] ?? ''));
                $label = $alt !== ''
                    ? t('plugin::prime-builder.ui.gallery_open_named', ['number' => $index + 1, 'description' => $alt])
                    : t('plugin::prime-builder.ui.gallery_open', ['number' => $index + 1]);
                // The link is a useful fallback if JS is unavailable or the plugin is disabled.
                $html .= '<a href="' . self::escape($url) . '" data-pb-gallery-item aria-label="' . self::escape($label) . '">'
                    . '<img src="' . self::escape($url) . '" alt="' . self::escape($alt) . '" loading="lazy" decoding="async"></a>';
            }
        }
        return $html . '</div>';
    }

    /** @param array<string, mixed> $settings */
    private function renderMap(array $settings): string
    {
        $urls = Map::urls($settings['latitude'] ?? null, $settings['longitude'] ?? null, $settings['zoom'] ?? null);
        if ($urls === null) {
            return '<p>' . self::escape(t('plugin::prime-builder.ui.map_configure')) . '</p>';
        }
        $title = $this->fieldText($settings, 'title', t('plugin::prime-builder.widgets.map'));
        $copy = static fn (string $key): string => self::escape(t('plugin::prime-builder.ui.map_' . $key));
        return '<div class="pb-map-content" data-pb-map data-pb-map-src="' . self::escape($urls['frame']) . '" data-pb-map-title="' . self::escape($title) . '">'
            . '<p>' . $copy('privacy') . '</p>'
            . '<button type="button" data-pb-map-load hidden>' . $copy('load') . '</button>'
            . '<p><a href="' . self::escape($urls['link']) . '" rel="noopener noreferrer">' . $copy('open') . '</a> — <a href="https://www.openstreetmap.org/copyright" rel="noopener noreferrer">' . $copy('attribution') . '</a></p></div>';
    }

    /** @param array<string, mixed> $settings */
    private function renderImageCarousel(array $settings): string
    {
        $lines = preg_split('/\R/u', $this->fieldText($settings, 'images')) ?: [];
        $copy = static fn (string $key): string => self::escape(t('plugin::prime-builder.ui.carousel_' . $key));
        $label = $this->fieldText($settings, 'label', t('plugin::prime-builder.widgets.image_carousel'));
        $html = '<div class="pb-carousel-content" data-pb-carousel data-pb-carousel-label="' . self::escape($label) . '"'
            . ' data-pb-carousel-role="' . $copy('role') . '" data-pb-carousel-slide-role="' . $copy('slide_role') . '"'
            . ' data-pb-carousel-previous="' . $copy('previous') . '" data-pb-carousel-next="' . $copy('next') . '"'
            . ' data-pb-carousel-position="' . $copy('position') . '"><div class="pb-carousel-slides">';
        foreach (array_slice($lines, 0, 20) as $line) {
            $parts = explode('|', $line, 2);
            $url = $this->sanitizer->sanitizeResourceUrl(trim($parts[0]));
            if ($url !== null) {
                $html .= '<div data-pb-slide><img src="' . self::escape($url) . '" alt="' . self::escape(trim(strip_tags($parts[1] ?? ''))) . '" loading="lazy" decoding="async"></div>';
            }
        }
        return $html . '</div></div>';
    }

    /** @param array<string, mixed> $settings */
    private function renderMedia(string $type, array $settings): string
    {
        $url = $this->sanitizer->sanitizeResourceUrl($this->fieldText($settings, 'url'));
        $transcript = $this->sanitizer->sanitizeBasic($this->fieldText($settings, 'transcript'));
        if ($url === null) {
            return $transcript;
        }
        if ($type === 'video') {
            $embed = BlockRegistry::parseEmbedUrl($url);
            if ($embed !== null) {
                return $this->renderEmbeddedVideo($settings, $url, $embed)
                    . ($transcript !== '' ? '<div class="pb-media-transcript">' . $transcript . '</div>' : '');
            }
            // Never try to fetch an unsupported YouTube/Vimeo URL as a media file.
            $host = parse_url($url, PHP_URL_HOST);
            if (is_string($host) && in_array(strtolower($host), ['youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtube-nocookie.com', 'www.youtube-nocookie.com', 'youtu.be', 'www.youtu.be', 'vimeo.com', 'www.vimeo.com', 'player.vimeo.com'], true)) {
                return '<p><a href="' . self::escape($url) . '" rel="noopener noreferrer">' . self::escape(t('plugin::prime-builder.ui.video_invalid')) . '</a></p>'
                    . ($transcript !== '' ? '<div class="pb-media-transcript">' . $transcript . '</div>' : '');
            }
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

    /**
     * A neutral placeholder avoids contacting video platforms before the visitor
     * explicitly requests the player. The regular link is also a no-JS fallback.
     *
     * @param array<string, mixed> $settings
     * @param array{provider: 'youtube'|'vimeo', id: string, embedUrl: string, defaultTitle: string} $embed
     */
    private function renderEmbeddedVideo(array $settings, string $url, array $embed): string
    {
        $provider = $embed['provider'] === 'youtube' ? 'YouTube' : 'Vimeo';
        $args = ['provider' => $provider];
        $title = $this->fieldText($settings, 'video_title', t('plugin::prime-builder.ui.video_default_title', $args));
        return '<div class="pb-video-embed" data-pb-embed-video data-pb-provider="' . self::escape($embed['provider']) . '" data-pb-video-id="' . self::escape($embed['id']) . '">'
            . '<p>' . self::escape(t('plugin::prime-builder.ui.video_privacy')) . '</p>'
            . '<button type="button" hidden data-pb-video-load data-pb-video-title="' . self::escape($title) . '">' . self::escape(t('plugin::prime-builder.ui.video_load', $args)) . '</button>'
            . '<p><a href="' . self::escape($url) . '" rel="noopener noreferrer">' . self::escape(t('plugin::prime-builder.ui.video_open', $args)) . '</a></p></div>';
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
