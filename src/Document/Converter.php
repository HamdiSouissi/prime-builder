<?php

declare(strict_types=1);

namespace PrimeBuilder\Document;

use Prime\Content\Entry;
use Prime\Editor\BlockRegistry;
use Prime\Security\HtmlSanitizer;
use Prime\Support\Str;
use PrimeBuilder\Widgets\OriginalContent;
use PrimeBuilder\Widgets\PrimeBlock;
use PrimeBuilder\Widgets\Registry;
use Throwable;

/** Converts only when the individual saved HTML fragments can be recovered without guessing. */
final class Converter
{
    public function __construct(
        private readonly BlockRegistry $blocks,
        private readonly HtmlSanitizer $sanitizer,
        private readonly Registry $registry,
        private readonly ?OriginalContent $originals,
    ) {
    }

    /** @return array<string, mixed>|null Null means that saving must be blocked, not that content may be discarded. */
    public function convert(Entry $entry): ?array
    {
        $document = $entry->decodedContent();
        $blocks = $document['blocks'];
        if (count($blocks) === 1 && $blocks[0]['type'] === 'prime-builder/layout') {
            return $document;
        }
        if ($blocks === []) {
            return trim($entry->contentHtml) === '' ? Layout::document(['version' => 1, 'elements' => []]) : null;
        }
        // The new container consumes one element of the validator's budget.
        if (count($blocks) >= Validator::MAX_ELEMENTS) {
            return null;
        }

        $fragments = $this->fragments($entry, true);
        if ($fragments === null) {
            $fragments = $this->fragments($entry, false);
        }
        if ($fragments === null) {
            return null;
        }

        $bridge = new PrimeBlock($this->blocks, $this->sanitizer);
        $children = [];
        try {
            foreach ($blocks as $index => $block) {
                $type = $block['type'];
                $data = $block['data'];
                if ($type === 'prime-builder/layout') {
                    $existing = (new Validator($this->registry, $this->sanitizer, $this->blocks, $this->originals, $entry->id))->validate($data);
                    if ($existing['errors'] !== [] || !is_array($existing['data']['elements'] ?? null)) {
                        return null;
                    }
                    foreach ($existing['data']['elements'] as $element) {
                        $children[] = $element;
                    }
                    continue;
                }
                $json = json_encode((object) $data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $normalized = $bridge->normalize($type, $json);
                $nodeId = $this->id();
                $anchor = null;
                if ($type === 'heading' && preg_match('/^<h[234](?: id="([a-z0-9-]+)")? class="pc-block pc-block--heading">/', $fragments[$index], $matches) === 1) {
                    // Core makes repeated heading anchors unique; retain the exact public URL fragment.
                    $anchor = $matches[1] ?? '';
                }
                // Normalization or a different render context can change output, even for an allowed type.
                // Only make a block editable when its reconstructed HTML is byte-for-byte identical.
                $sameHtml = $normalized !== null && ($type !== 'heading' || $anchor !== null)
                    && $bridge->render($type, $normalized, $nodeId, $entry, $anchor) === $fragments[$index];
                if ($sameHtml) {
                    $widget = 'prime_block';
                    $settings = ['block_type' => $type, 'block_data' => $normalized];
                    if ($anchor !== null) {
                        $settings['block_anchor'] = $anchor;
                    }
                } else {
                    $payload = $this->originals?->protect($entry->id, $type, $fragments[$index]);
                    if ($payload === null) {
                        return null;
                    }
                    $widget = 'original_content';
                    $settings = ['payload' => $payload];
                }
                $children[] = ['id' => $nodeId, 'type' => $widget, 'settings' => $settings, 'styles' => [], 'children' => []];
            }
            $layout = ['version' => 1, 'elements' => [[
                'id' => $this->id(), 'type' => 'container',
                'settings' => ['layout' => 'flex', 'direction' => 'column', 'columns' => 1],
                'styles' => [], 'children' => $children,
            ]]];
            $validated = (new Validator($this->registry, $this->sanitizer, $this->blocks, $this->originals, $entry->id))->validate($layout);
            return $validated['errors'] === [] ? Layout::document($validated['data']) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Render the stored blocks exactly as the CMS does, including unique heading anchors. If
     * the result differs from saved HTML (missing plugin, changed renderer, or global filter),
     * there is no reliable per-block boundary in content_html and conversion must stop.
     *
     * @return list<string>|null
     */
    private function fragments(Entry $entry, bool $allowUnfilteredHtml): ?array
    {
        $fragments = [];
        $chunks = [];
        $usedAnchors = [];
        try {
            foreach ($entry->decodedContent()['blocks'] as $block) {
                $type = $block['type'];
                $data = $block['data'];
                $definition = $this->blocks->get($type);
                if ($definition === null) {
                    // With just one unknown block its entire saved output is unambiguous.
                    return count($entry->decodedContent()['blocks']) === 1 ? [$entry->contentHtml] : null;
                }
                $context = ['entry' => $entry, 'allowUnfilteredHtml' => $allowUnfilteredHtml];
                if ($type === 'heading') {
                    $anchor = is_string($data['anchor'] ?? null) && $data['anchor'] !== ''
                        ? Str::slug($data['anchor'], '-', 80)
                        : Str::slug(Str::plainText((string) ($data['text'] ?? '')), '-', 80);
                    if ($anchor !== '') {
                        $candidate = $anchor;
                        $suffix = 2;
                        while (isset($usedAnchors[$candidate])) {
                            $candidate = $anchor . '-' . $suffix++;
                        }
                        $usedAnchors[$candidate] = true;
                        $context['anchor'] = $candidate;
                    }
                }
                $fragment = ($definition->renderer)($data, $this->sanitizer, $context);
                $fragments[] = $fragment;
                if ($fragment !== '') {
                    $chunks[] = $fragment;
                }
            }
        } catch (Throwable) {
            return null;
        }
        return implode("\n", $chunks) === $entry->contentHtml ? $fragments : null;
    }

    private function id(): string
    {
        return 'pb_' . bin2hex(random_bytes(8));
    }
}
