<?php

declare(strict_types=1);

namespace PrimeBuilder\Widgets;

use Prime\Content\Entry;
use Prime\Editor\BlockRegistry;
use Prime\Http\Request;
use Prime\Log\Logger;
use Prime\Plugins\PluginContext;
use Prime\Security\HtmlSanitizer;
use Prime\Site\PageContext;
use PrimeBuilder\Document\HtmlContent;
use PrimeBuilder\Document\MediaReferences;
use PrimeBuilder\Dynamic\Context as DynamicContext;
use PrimeBuilder\Dynamic\Layout as DynamicLayout;
use PrimeBuilder\Forms\Fields;
use PrimeBuilder\Forms\FileInput;
use PrimeBuilder\Styles\CssClasses;
use PrimeBuilder\Styles\Scope;
use Throwable;

/** Render safe, useful static HTML so saved pages remain readable without this plugin. */
final class Renderer
{
    private ?TableOfContents $tableOfContents = null;
    private string $styleOwner = '';
    /** @var array<string, array{width: int, height: int}> */
    private array $inferredImageDimensions = [];

    /** @param array<mixed> $pageQuery */
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
        private readonly ?MediaReferences $mediaReferences = null,
        private readonly bool $allowThemeHtml = false,
        private readonly array $pageQuery = [],
        private readonly ?PluginContext $dynamicPlugin = null,
        private readonly ?Request $request = null,
        private readonly ?PageContext $dynamicPageContext = null,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $styleLayout Full layout for style ownership and the outline when rendering a preview subtree.
     */
    public function render(array $data, ?array $styleLayout = null): string
    {
        $definitions = $this->registry->all();
        $sourceElements = is_array($data['elements'] ?? null) ? $data['elements'] : [];
        // CSS belongs to the saved tree, never to a visitor-dependent filtered copy.
        $this->styleOwner = Scope::forLayout($styleLayout ?? $data);
        $dynamic = $this->dynamicContext();
        $elements = DynamicLayout::prepare($sourceElements, $definitions, $dynamic !== null, $dynamic?->user(), $dynamic !== null ? $dynamic->value(...) : null);
        // A targeted preview renders only one subtree, but its anchors and any
        // table of contents must still agree with the complete visible document.
        $outlineSource = is_array($styleLayout['elements'] ?? null) ? $styleLayout['elements'] : $sourceElements;
        $outlineElements = DynamicLayout::prepare($outlineSource, $definitions, $dynamic !== null, $dynamic?->user(), $dynamic !== null ? $dynamic->value(...) : null);
        $this->tableOfContents = $this->containsTableOfContents($outlineElements) ? new TableOfContents($outlineElements, $this->sanitizer) : null;
        $this->inferredImageDimensions = [];
        if ($this->mediaReferences !== null) {
            try {
                $this->inferredImageDimensions = $this->mediaReferences->imageDimensions($this->imageUrlsForInference($elements));
            } catch (Throwable $exception) {
                // A media database failure must not prevent the rest of a page from rendering.
                $this->logger?->error('Prime Builder image dimensions lookup failed', ['message' => $exception->getMessage()]);
            }
        }
        // The editor can locate a saved entry without assuming a theme's markup.
        // A zone or theme partial is rendered without an Entry and has no marker.
        $document = $this->entry !== null && $this->entry->id > 0 ? ' data-pb-document="' . $this->entry->id . '"' : '';
        $refresh = $this->entry !== null && $this->containsLive($elements)
            ? ' data-pb-live="/api/prime-builder/v1/documents/' . $this->entry->id . '/live"'
            : '';
        return '<div class="pb-layout"' . $document . $refresh . '>' . $this->renderElements($elements, $definitions) . '</div>';
    }

    private function dynamicContext(): ?DynamicContext
    {
        if ($this->dynamicPlugin === null || (!$this->live && $this->pageContext === null && $this->dynamicPageContext === null && !$this->trustedPreview)) {
            return null;
        }
        try {
            return new DynamicContext($this->dynamicPlugin, $this->dynamicPageContext ?? $this->pageContext, $this->entry, $this->request, $this->trustedPreview);
        } catch (Throwable $exception) {
            // Failure to resolve a visitor must not expose conditional content.
            $this->logger?->error('Prime Builder dynamic context failed', ['message' => $exception->getMessage()]);
            return null;
        }
    }

    /**
     * Collect only native image URLs without author-supplied dimensions. A
     * single-image field with one explicit dimension is intentionally skipped.
     *
     * @param array<mixed> $elements
     * @return list<string>
     */
    private function imageUrlsForInference(array $elements): array
    {
        $urls = [];
        $pending = $elements;
        $fields = ['image' => 'url', 'card' => 'image_url', 'image_box' => 'image_url', 'testimonial' => 'image_url', 'review' => 'image_url', 'call_to_action' => 'image_url'];
        while ($pending !== []) {
            $element = array_pop($pending);
            if (!is_array($element)) {
                continue;
            }
            foreach (is_array($element['children'] ?? null) ? $element['children'] : [] as $child) {
                $pending[] = $child;
            }
            $type = $element['type'] ?? null;
            $settings = is_array($element['settings'] ?? null) ? $element['settings'] : [];
            $field = is_string($type) ? ($fields[$type] ?? null) : null;
            if ($field !== null) {
                if (!array_key_exists('width', $settings) && !array_key_exists('height', $settings) && is_string($settings[$field] ?? null)) {
                    $url = $this->sanitizer->sanitizeResourceUrl($settings[$field]);
                    if ($url !== null && $url !== '') {
                        $urls[$url] = $url;
                    }
                }
                continue;
            }
            if (!is_string($type) || !in_array($type, ['gallery', 'image_carousel', 'media_carousel', 'slideshow', 'portfolio', 'testimonial_carousel'], true)) {
                continue;
            }
            $explicit = $this->imageListDimensions($settings);
            foreach ($this->imageListUrls($type, $settings) as $url) {
                if (!isset($explicit[$url])) {
                    $urls[$url] = $url;
                }
            }
        }
        return array_values($urls);
    }

    /**
     * Use the same bounded parsers as the renderers and exclude video rows.
     *
     * @param array<string, mixed> $settings
     * @return list<string>
     */
    private function imageListUrls(string $type, array $settings): array
    {
        $urls = [];
        if ($type === 'gallery' || $type === 'image_carousel') {
            $lines = preg_split('/\\R/u', $this->fieldText($settings, 'images')) ?: [];
            foreach (array_slice($lines, 0, 20) as $line) {
                $url = $this->sanitizer->sanitizeResourceUrl(trim(explode('|', $line, 2)[0]));
                if ($url !== null && $url !== '') {
                    $urls[] = $url;
                }
            }
        } elseif ($type === 'media_carousel') {
            foreach (MediaCarousel::parse($this->fieldText($settings, 'items'), $this->sanitizer) ?? [] as $row) {
                if ($row['type'] === 'image') {
                    $urls[] = $row['url'];
                }
            }
        } else {
            $input = $this->fieldText($settings, 'items');
            $rows = match ($type) {
                'slideshow' => Slideshow::parse($input, $this->sanitizer),
                'portfolio' => Portfolio::parse($input, $this->sanitizer),
                'testimonial_carousel' => Testimonials::parse($input, $this->sanitizer),
                default => null,
            };
            foreach ($rows ?? [] as $row) {
                if ($row['image'] !== '') {
                    $urls[] = $row['image'];
                }
            }
        }
        return $urls;
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
                            $page = $this->pageContext ?? ($this->entry !== null ? new PageContext(type: PageContext::TYPE_ENTRY, entry: $this->entry) : null);
                            $output[$id] = $page !== null
                                ? $this->content->themeZoneForPage($settings, $page, $this->request, $this->entry)
                                : $this->content->themeZone($settings, true, $this->entry);
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
        $dynamic = $this->dynamicContext();
        $elements = DynamicLayout::prepare(is_array($data['elements'] ?? null) ? $data['elements'] : [], $this->registry->all(), $dynamic !== null, $dynamic?->user(), $dynamic !== null ? $dynamic->value(...) : null);
        $walk($elements);
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
                if (($definitions[$type]['container'] ?? false) === true || $type === 'global_widget') {
                    $content .= $this->renderElements($children, $definitions);
                }
                $hidden = ($element['hidden'] ?? false) === true ? ' hidden' : '';
                $target = $this->tableOfContents?->target($id);
                $anchor = $target !== null ? ' id="' . self::escape($target) . '" tabindex="-1"' : '';
                // Direct callers do not necessarily pass a validated layout. Never
                // apply custom classes to protected original content or invalid data.
                $classes = in_array($type, ['original_content', 'original_group'], true) ? '' : CssClasses::normalize($element['classes'] ?? '');
                $extra = is_string($classes) && $classes !== '' ? ' ' . self::escape($classes) : '';
                $html .= '<div class="pb-element pb-' . self::escape($type) . $extra . '" data-pb-owner="' . $this->styleOwner . '" data-pb-id="' . self::escape($id) . '"' . $anchor . $hidden . '>' . $content . '</div>';
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
        $rich = $this->richField($id, 'text', is_string($settings['text'] ?? null) ? $settings['text'] : '');
        $url = is_string($settings['url'] ?? null) ? $settings['url'] : '';
        $href = $this->sanitizer->sanitizeHref($url) ?? '';
        $resource = $this->sanitizer->sanitizeResourceUrl($url) ?? '';
        $level = max(1, min(6, (int) ($settings['level'] ?? 2)));
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
            'image' => $resource !== '' ? '<img src="' . self::escape($resource) . '" alt="' . self::escape((string) ($settings['alt'] ?? '')) . '"' . $this->imageDimensionAttributes($settings, $resource) . ' loading="lazy" decoding="async">' : '',
            'button' => $href !== '' ? '<a class="pb-button-link" href="' . self::escape($href) . '">' . $text . '</a>' : '',
            'separator' => '<hr>',
            'quote' => '<blockquote>' . $rich . (isset($settings['author']) && $settings['author'] !== '' ? '<cite>' . self::escape((string) $settings['author']) . '</cite>' : '') . '</blockquote>',
            'alert' => '<div class="pb-alert-content">' . $rich . '</div>',
            'progress' => '<label>' . $text . ' <progress max="100" value="' . $value . '">' . $value . '%</progress></label>',
            'anchor' => isset($settings['anchor']) && preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/', (string) $settings['anchor']) === 1 ? '<span id="' . self::escape((string) $settings['anchor']) . '"></span>' : '',
            'list' => $this->renderList($settings),
            'accordion' => '<details><summary>' . self::escape($this->fieldText($settings, 'title', t('plugin::prime-builder.widgets.accordion'))) . '</summary><div class="pb-accordion-content">' . $rich . '</div></details>',
            'card' => $this->renderCard($settings, $id),
            'icon_box' => $this->renderIconBox($settings, $id),
            'icon' => $this->renderIcon($settings),
            'icon_list' => $this->renderIconList($settings),
            'image_box' => $this->renderImageBox($settings, $id),
            'testimonial' => $this->renderTestimonial($settings, $id),
            'testimonial_carousel' => $this->renderTestimonialCarousel($settings),
            'media_carousel' => $this->renderMediaCarousel($settings),
            'slideshow' => $this->renderSlideshow($settings),
            'portfolio' => $this->renderPortfolio($settings),
            'review' => $this->renderReview($settings, $id),
            'flip_box' => $this->renderFlipBox($settings),
            'animated_heading' => $this->renderAnimatedHeading($settings),
            'code' => $this->renderCode($settings),
            'call_to_action' => $this->renderCallToAction($settings, $id),
            'price_table' => $this->renderPriceTable($settings, $id),
            'price_list' => $this->renderPriceList($settings),
            'countdown' => $this->renderCountdown($settings),
            'toggle' => '<details' . (($settings['initial'] ?? 'closed') === 'open' ? ' open' : '') . '><summary>' . self::escape($this->fieldText($settings, 'title', t('plugin::prime-builder.widgets.toggle'))) . '</summary><div>' . $rich . '</div></details>',
            'social' => $this->renderSocial($settings),
            'map' => $this->renderMap($settings),
            'star_rating' => $this->renderStarRating($settings),
            'counter' => '<div class="pb-counter-value">' . self::escape(is_string($settings['prefix'] ?? null) ? $settings['prefix'] : '') . max(0, min(1000000000, (int) ($settings['value'] ?? 0))) . self::escape(is_string($settings['suffix'] ?? null) ? $settings['suffix'] : '') . '</div><p>' . self::escape($this->fieldText($settings, 'title')) . '</p>',
            'tabs' => $this->renderTabs($settings, $id),
            'gallery' => $this->renderGallery($settings),
            'image_carousel' => $this->renderImageCarousel($settings),
            'video', 'audio' => $this->renderMedia($type, $settings, $id),
            'posts' => $this->pageContext !== null
                ? ($this->content?->postsInPage($settings, $id, $this->pageContext, $this->pageQuery) ?? '')
                : ($this->content?->posts($settings, $this->live, $id) ?? ''),
            'current_content' => $this->pageContext !== null
                ? ($this->content?->currentInPage($settings, $this->pageContext) ?? '')
                : ($this->content?->current($settings, $this->entry, $this->live) ?? ''),
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
            'table_of_contents' => ($this->tableOfContents ?? new TableOfContents([], $this->sanitizer))->render($settings),
            'theme_zone' => $this->pageContext !== null
                ? ($this->content?->themeZoneInPage($settings, $this->pageContext, $this->request) ?? '')
                : ($this->content?->themeZone($settings, $this->live, $this->entry) ?? '<p>' . self::escape(t('plugin::prime-builder.ui.theme_zone_fallback')) . '</p>'),
            'form' => $this->renderForm($settings, $id),
            'prime_block' => (new PrimeBlock($this->blocks ?? new BlockRegistry(), $this->sanitizer))->render($this->fieldText($settings, 'block_type', 'paragraph'), $this->fieldText($settings, 'block_data', '{}'), $id, $this->entry, is_string($settings['block_anchor'] ?? null) ? $settings['block_anchor'] : null),
            'original_content' => $this->renderOriginal($settings, false),
            'original_group' => $this->renderOriginal($settings, true),
            default => '',
        };
    }

    /**
     * A missing schema retains the original fixed form. Tokens are fetched by
     * JS and never embedded in saved or cached HTML.
     *
     * @param array<string, mixed> $settings
     */
    private function renderForm(array $settings, string $id): string
    {
        $entryId = $this->entry->id ?? 0;
        $prefix = 'pb-form-' . $id;
        $title = $this->fieldText($settings, 'title');
        $copy = static fn (string $key): string => self::escape(t('plugin::prime-builder.forms.' . $key));
        $custom = array_key_exists('form_fields', $settings);
        $fields = $custom && is_string($settings['form_fields']) ? Fields::parse($settings['form_fields']) : null;
        if ($custom && $fields === null) {
            // Corrupt or unvalidated stored data must not fall back to another form.
            return '';
        }
        $content = $fields !== null ? $this->renderCustomFields($fields, $prefix) : '<p>' . $copy('required') . '</p>'
            . '<label for="' . self::escape($prefix) . '-name">' . $copy('name') . '</label><input id="' . self::escape($prefix) . '-name" name="name" type="text" autocomplete="name" maxlength="160" required>'
            . '<label for="' . self::escape($prefix) . '-email">' . $copy('email') . '</label><input id="' . self::escape($prefix) . '-email" name="email" type="email" autocomplete="email" maxlength="254" required>'
            . '<label for="' . self::escape($prefix) . '-message">' . $copy('message') . '</label><textarea id="' . self::escape($prefix) . '-message" name="message" rows="5" maxlength="5000" required></textarea>';
        return '<form class="pb-contact-form" method="post" action="/api/prime-builder/v1/forms/' . $entryId . '/submit" data-pb-form data-pb-node="' . self::escape($id) . '"'
            . ($custom ? ' data-pb-custom="1" data-pb-required-error="' . $copy('choose_required') . '" data-pb-file-error="' . $copy('file_error') . '"' : '')
            . ' data-pb-error="' . $copy('error') . '" data-pb-wait="' . $copy('wait') . '" data-pb-sent="' . $copy('sent') . '" data-pb-limited="' . $copy('limited') . '" data-pb-redirecting="' . $copy('redirecting') . '">'
            . ($title !== '' ? '<h3>' . self::escape($title) . '</h3>' : '')
            . $content
            . '<div class="pb-contact-trap" aria-hidden="true"><label for="' . self::escape($prefix) . '-website">' . $copy('trap') . '</label><input id="' . self::escape($prefix) . '-website" name="website" type="text" tabindex="-1" autocomplete="off"></div>'
            . ($custom ? '<div class="pb-form-navigation"><button type="button" class="pb-form-previous" data-pb-form-previous hidden>' . $copy('step_previous') . '</button><button type="button" class="pb-form-next" data-pb-form-next hidden>' . $copy('step_next') . '</button></div>' : '')
            . '<button class="pb-contact-submit" type="submit" disabled>' . self::escape($this->fieldText($settings, 'button_text', t('plugin::prime-builder.forms.submit'))) . '</button>'
            . '<p class="pb-contact-status" role="status" aria-live="polite" data-pb-form-status></p>'
            . '<noscript><p>' . $copy('javascript') . '</p></noscript></form>';
    }

    /**
     * @param list<array{id: string, type: string, label: string, required: bool, step: int, options: list<string>, value: string}> $fields
     */
    private function renderCustomFields(array $fields, string $prefix): string
    {
        $maxStep = max([1, ...array_column($fields, 'step')]);
        $output = '';
        for ($step = 1; $step <= $maxStep; $step++) {
            $legend = $maxStep > 1
                ? t('plugin::prime-builder.forms.step', ['current' => $step, 'total' => $maxStep])
                : t('plugin::prime-builder.forms.fields');
            $output .= '<fieldset class="pb-form-step" data-pb-form-step><legend tabindex="-1">' . self::escape($legend) . '</legend>';
            foreach ($fields as $field) {
                if ($field['step'] !== $step) {
                    continue;
                }
                $name = self::escape($field['id']);
                $id = self::escape($prefix . '-' . $field['id']);
                $label = self::escape($field['label']);
                $required = $field['required'] ? ' required' : '';
                $suffix = $field['required'] ? ' <span class="pb-form-required">' . self::escape(t('plugin::prime-builder.forms.field_required')) . '</span>' : '';
                $output .= '<div class="pb-form-field" data-pb-field="' . $name . '" data-pb-kind="' . $field['type'] . '" data-pb-required="' . ($field['required'] ? '1' : '0') . '"' . ($field['type'] === 'hidden' ? ' hidden' : '') . '>';
                if ($field['type'] === 'hidden') {
                    $output .= '<input type="hidden" id="' . $id . '" name="' . $name . '" value="' . self::escape($field['value']) . '">';
                } elseif (in_array($field['type'], ['checkbox', 'radio'], true)) {
                    $output .= '<fieldset class="pb-form-choices"><legend>' . $label . $suffix . '</legend>';
                    foreach ($field['options'] as $index => $option) {
                        $choiceId = $id . '-' . $index;
                        // Required checkbox groups are checked together by JS; required
                        // on each individual checkbox would incorrectly require all of them.
                        $choiceRequired = $field['type'] === 'radio' && $index === 0 ? $required : '';
                        $output .= '<div class="pb-form-choice"><input type="' . $field['type'] . '" id="' . $choiceId . '" name="' . $name . '" value="' . self::escape($option) . '"' . $choiceRequired . '><label for="' . $choiceId . '">' . self::escape($option) . '</label></div>';
                    }
                    $output .= '</fieldset>';
                } elseif ($field['type'] === 'acceptance') {
                    $output .= '<div class="pb-form-choice"><input type="checkbox" id="' . $id . '" name="' . $name . '" value="1"' . $required . '><label for="' . $id . '">' . $label . $suffix . '</label></div>';
                } elseif ($field['type'] === 'select') {
                    $output .= '<label for="' . $id . '">' . $label . $suffix . '</label><select id="' . $id . '" name="' . $name . '"' . $required . '><option value="">' . self::escape(t('plugin::prime-builder.forms.choose')) . '</option>';
                    foreach ($field['options'] as $option) {
                        $output .= '<option value="' . self::escape($option) . '">' . self::escape($option) . '</option>';
                    }
                    $output .= '</select>';
                } elseif ($field['type'] === 'textarea') {
                    $output .= '<label for="' . $id . '">' . $label . $suffix . '</label><textarea id="' . $id . '" name="' . $name . '" rows="5" maxlength="2000"' . $required . '></textarea>';
                } elseif ($field['type'] === 'file') {
                    $output .= '<label for="' . $id . '">' . $label . $suffix . '</label><input id="' . $id . '" name="' . $name . '" type="file" accept="' . FileInput::ACCEPT . '" aria-describedby="' . $id . '-help"' . $required . '>'
                        . '<p id="' . $id . '-help" class="pb-form-help">' . self::escape(t('plugin::prime-builder.forms.file_help')) . '</p>';
                } else {
                    $type = $field['type'] === 'phone' ? 'tel' : $field['type'];
                    $extra = match ($type) {
                        'text' => ' maxlength="160"',
                        'email' => ' maxlength="254"',
                        'tel' => ' maxlength="40"',
                        'number' => ' step="any"',
                        default => '',
                    };
                    $output .= '<label for="' . $id . '">' . $label . $suffix . '</label><input id="' . $id . '" name="' . $name . '" type="' . $type . '"' . $extra . $required . '>';
                }
                $output .= '</div>';
            }
            $output .= '</fieldset>';
        }
        return $output;
    }

    /** @param array<string, mixed> $settings */
    private function renderHtmlWidget(string $id, array $settings): string
    {
        $verified = $this->entry !== null && ($this->html?->valid($id, $settings, $this->entry->id) ?? false);
        // Theme layouts have their own capability-protected storage, not an
        // entry-bound seal. Do not turn on trusted preview for public rendering:
        // that flag also enables preview-only behavior in other widgets.
        $themeHtml = $this->allowThemeHtml && $this->pageContext !== null && $this->entry === null;
        if (!$this->trustedPreview && !$verified && !$themeHtml) {
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

    /** Use generated heading targets only after the original field was sanitized. */
    private function richField(string $id, string $field, string $value): string
    {
        return $this->tableOfContents?->richText($id, $field) ?? $this->sanitizer->sanitizeBasic($value);
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
    private function imageDimensionAttributes(array $settings, string $url): string
    {
        if (!array_key_exists('width', $settings) && !array_key_exists('height', $settings)) {
            $settings += $this->inferredImageDimensions[$url] ?? [];
        }
        $attributes = '';
        foreach (['width', 'height'] as $name) {
            $value = $settings[$name] ?? null;
            if (is_int($value) && $value >= 1 && $value <= 10000) {
                $attributes .= ' ' . $name . '="' . $value . '"';
            }
        }
        return $attributes;
    }

    /** @param array<string, mixed> $settings */
    private function renderCard(array $settings, string $id): string
    {
        $image = $this->sanitizer->sanitizeResourceUrl($this->fieldText($settings, 'image_url'));
        $link = $this->sanitizer->sanitizeHref($this->fieldText($settings, 'url'));
        $html = '<article class="pb-card-content">';
        if ($image !== null) {
            $html .= '<img src="' . self::escape($image) . '" alt="' . self::escape($this->fieldText($settings, 'alt')) . '"' . $this->imageDimensionAttributes($settings, $image) . ' loading="lazy" decoding="async">';
        }
        $title = $this->fieldText($settings, 'title');
        if ($title !== '') {
            $html .= '<h3>' . self::escape($title) . '</h3>';
        }
        $html .= $this->richField($id, 'text', $this->fieldText($settings, 'text'));
        if ($link !== null) {
            $html .= '<a class="pb-button-link" href="' . self::escape($link) . '">' . self::escape($this->fieldText($settings, 'button_text', t('plugin::prime-builder.ui.read_more'))) . '</a>';
        }
        return $html . '</article>';
    }

    /** @param array<string, mixed> $settings */
    private function renderIconBox(array $settings, string $id): string
    {
        $symbol = $this->iconSymbol($settings);
        $title = $this->fieldText($settings, 'title');
        return '<div class="pb-icon-box-content"><span class="pb-icon-box-symbol" aria-hidden="true">' . $symbol . '</span>'
            . ($title !== '' ? '<h3>' . self::escape($title) . '</h3>' : '')
            . $this->richField($id, 'text', $this->fieldText($settings, 'text')) . '</div>';
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
    private function renderImageBox(array $settings, string $id): string
    {
        $image = $this->sanitizer->sanitizeResourceUrl($this->fieldText($settings, 'image_url'));
        $title = $this->fieldText($settings, 'title');
        $url = $this->sanitizer->sanitizeHref($this->fieldText($settings, 'url'));
        $html = '<div>';
        if ($image !== null && $image !== '') {
            $html .= '<img src="' . self::escape($image) . '" alt="' . self::escape($this->fieldText($settings, 'alt')) . '"' . $this->imageDimensionAttributes($settings, $image) . ' loading="lazy" decoding="async">';
        }
        if ($title !== '') {
            $html .= '<h3>' . self::escape($title) . '</h3>';
        }
        $html .= $this->richField($id, 'text', $this->fieldText($settings, 'text'));
        if ($url !== null && $url !== '') {
            $html .= '<a class="pb-button-link" href="' . self::escape($url) . '">' . self::escape($this->fieldText($settings, 'button_text', t('plugin::prime-builder.ui.read_more'))) . '</a>';
        }
        return $html . '</div>';
    }

    /** @param array<string, mixed> $settings */
    private function renderTestimonial(array $settings, string $id): string
    {
        $image = $this->sanitizer->sanitizeResourceUrl($this->fieldText($settings, 'image_url'));
        $author = $this->fieldText($settings, 'author');
        $job = $this->fieldText($settings, 'job_title');
        $html = '<figure><blockquote>' . $this->richField($id, 'text', $this->fieldText($settings, 'text')) . '</blockquote>';
        if (($image !== null && $image !== '') || $author !== '' || $job !== '') {
            $html .= '<figcaption>';
            if ($image !== null && $image !== '') {
                $html .= '<img src="' . self::escape($image) . '" alt="' . self::escape($this->fieldText($settings, 'alt')) . '"' . $this->imageDimensionAttributes($settings, $image) . ' loading="lazy" decoding="async"> ';
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
        $dimensions = $this->imageListDimensions($settings);
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
                    $html .= '<img src="' . self::escape($row['image']) . '" alt=""' . $this->dimensionAttributes($dimensions, $row['image']) . ' loading="lazy" decoding="async">';
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
        $dimensions = $this->imageListDimensions($settings);
        $copy = static fn (string $key): string => self::escape(t('plugin::prime-builder.ui.' . $key));
        $language = ($settings['caption_language'] ?? 'fr') === 'en' ? 'en' : 'fr';
        $html = '<div class="pb-media-carousel" data-pb-carousel data-pb-carousel-label="' . self::escape($label) . '"'
            . ' data-pb-carousel-role="' . $copy('carousel_role') . '" data-pb-carousel-slide-role="' . $copy('carousel_slide_role') . '"'
            . ' data-pb-carousel-previous="' . $copy('media_carousel_previous') . '" data-pb-carousel-next="' . $copy('media_carousel_next') . '"'
            . ' data-pb-carousel-position="' . $copy('media_carousel_position') . '"><div class="pb-media-carousel-slides">';
        foreach ($rows as $row) {
            $html .= '<div data-pb-slide><figure>';
            if ($row['type'] === 'image') {
                $html .= '<img src="' . self::escape($row['url']) . '" alt="' . self::escape($row['description']) . '"' . $this->dimensionAttributes($dimensions, $row['url']) . ' loading="lazy" decoding="async">';
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
        $dimensions = $this->imageListDimensions($settings);
        $level = max(2, min(6, (int) ($settings['level'] ?? 2)));
        $copy = static fn (string $key): string => self::escape(t('plugin::prime-builder.ui.' . $key));
        $html = '<div class="pb-slide-show" data-pb-carousel data-pb-carousel-label="' . self::escape($label) . '"'
            . ' data-pb-carousel-role="' . $copy('carousel_role') . '" data-pb-carousel-slide-role="' . $copy('carousel_slide_role') . '"'
            . ' data-pb-carousel-previous="' . $copy('slideshow_previous') . '" data-pb-carousel-next="' . $copy('slideshow_next') . '"'
            . ' data-pb-carousel-position="' . $copy('slideshow_position') . '"><div class="pb-slideshow-slides">';
        foreach ($slides as $slide) {
            $html .= '<article class="pb-slideshow-slide" data-pb-slide><img src="' . self::escape($slide['image']) . '" alt="'
                . self::escape($slide['alt']) . '"' . $this->dimensionAttributes($dimensions, $slide['image']) . ' loading="lazy" decoding="async">';
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
        $dimensions = $this->imageListDimensions($settings);
        $copy = static fn (string $key): string => self::escape(t('plugin::prime-builder.ui.' . $key));
        $html = '<div data-pb-portfolio data-pb-portfolio-filter-label="' . $copy('portfolio_filter_label') . '"'
            . ' data-pb-portfolio-all="' . $copy('portfolio_all') . '" data-pb-portfolio-category="' . $copy('portfolio_category') . '"'
            . ' data-pb-portfolio-single="' . $copy('portfolio_single') . '" data-pb-portfolio-plural="' . $copy('portfolio_plural') . '">'
            . '<ul class="pb-portfolio-list pb-portfolio-columns-' . $columns . '">';
        foreach ($items as $item) {
            $title = self::escape($item['title']);
            $html .= '<li class="pb-portfolio-item" data-pb-portfolio-item data-pb-category="' . self::escape($item['category']) . '"><article>'
                . '<img src="' . self::escape($item['image']) . '" alt="' . self::escape($item['alt']) . '"' . $this->dimensionAttributes($dimensions, $item['image']) . ' loading="lazy" decoding="async">'
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
    private function renderReview(array $settings, string $id): string
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
            . '<blockquote>' . $this->richField($id, 'text', $this->fieldText($settings, 'text')) . '</blockquote>';
        if (($image !== null && $image !== '') || $author !== '' || $job !== '') {
            $html .= '<div class="pb-review-credit">';
            if ($image !== null && $image !== '') {
                $html .= '<img src="' . self::escape($image) . '" alt="' . self::escape($this->fieldText($settings, 'alt')) . '"' . $this->imageDimensionAttributes($settings, $image) . ' loading="lazy" decoding="async">';
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
    private function renderCallToAction(array $settings, string $id): string
    {
        $title = $this->fieldText($settings, 'title');
        $level = max(2, min(6, (int) ($settings['level'] ?? 2)));
        $image = $this->sanitizer->sanitizeResourceUrl($this->fieldText($settings, 'image_url'));
        $url = $this->sanitizer->sanitizeHref($this->fieldText($settings, 'url'));
        $html = '<div class="pb-cta-content">';
        if ($image !== null && $image !== '') {
            $html .= '<img src="' . self::escape($image) . '" alt="' . self::escape($this->fieldText($settings, 'alt')) . '"' . $this->imageDimensionAttributes($settings, $image) . ' loading="lazy" decoding="async">';
        }
        $html .= '<div class="pb-cta-copy">';
        if ($title !== '') {
            $html .= '<h' . $level . '>' . self::escape($title) . '</h' . $level . '>';
        }
        $html .= $this->richField($id, 'text', $this->fieldText($settings, 'text'));
        if ($url !== null && $url !== '') {
            $html .= '<a class="pb-button-link" href="' . self::escape($url) . '">' . self::escape($this->fieldText($settings, 'button_text', t('plugin::prime-builder.ui.read_more'))) . '</a>';
        }
        return $html . '</div></div>';
    }

    /** @param array<string, mixed> $settings */
    private function renderPriceTable(array $settings, string $id): string
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
        $html .= $this->richField($id, 'text', $this->fieldText($settings, 'text'));
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

    /**
     * @param array<string, mixed> $settings
     * @return array<string, array{width: int, height: int}>
     */
    private function imageListDimensions(array $settings): array
    {
        $input = is_string($settings['images_dimensions'] ?? null) ? $settings['images_dimensions'] : '[]';
        $manual = is_string($settings['manual_dimensions'] ?? null) ? $settings['manual_dimensions'] : '';
        $result = [];
        // Explicit author values override a library snapshot for the same URL.
        foreach ([MediaDimensions::parse($input, $this->sanitizer) ?? [], MediaDimensions::parseManual($manual, $this->sanitizer) ?? []] as $rows) {
            foreach ($rows as $row) {
                $result[$row['url']] = ['width' => $row['width'], 'height' => $row['height']];
            }
        }
        return $result;
    }

    /** @param array<string, array{width: int, height: int}> $dimensions */
    private function dimensionAttributes(array $dimensions, string $url): string
    {
        $size = $dimensions[$url] ?? $this->inferredImageDimensions[$url] ?? null;
        return $size === null ? '' : ' width="' . $size['width'] . '" height="' . $size['height'] . '"';
    }

    /** @param array<string, mixed> $settings */
    private function renderGallery(array $settings): string
    {
        $lines = preg_split('/\R/u', $this->fieldText($settings, 'images')) ?: [];
        $dimensions = $this->imageListDimensions($settings);
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
                    . '<img src="' . self::escape($url) . '" alt="' . self::escape($alt) . '"' . $this->dimensionAttributes($dimensions, $url) . ' loading="lazy" decoding="async"></a>';
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
        $dimensions = $this->imageListDimensions($settings);
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
                $html .= '<div data-pb-slide><img src="' . self::escape($url) . '" alt="' . self::escape(trim(strip_tags($parts[1] ?? ''))) . '"' . $this->dimensionAttributes($dimensions, $url) . ' loading="lazy" decoding="async"></div>';
            }
        }
        return $html . '</div></div>';
    }

    /** @param array<string, mixed> $settings */
    private function renderMedia(string $type, array $settings, string $id): string
    {
        $url = $this->sanitizer->sanitizeResourceUrl($this->fieldText($settings, 'url'));
        $transcript = $this->richField($id, 'transcript', $this->fieldText($settings, 'transcript'));
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
