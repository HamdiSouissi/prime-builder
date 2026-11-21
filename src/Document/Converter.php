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
     * Reproduce the current renderers, including unique heading anchors. With one unavailable
     * renderer, recover its HTML only when all surrounding output matches the saved prefix and
     * suffix. This assumes those other renderers have not changed since the content was saved;
     * the CMS stores only a combined HTML string, not authoritative per-block fragments.
     * Refuse mismatches or multiple unavailable renderers instead of discarding content.
     *
     * @return list<string>|null
     */
    private function fragments(Entry $entry, bool $allowUnfilteredHtml): ?array
    {
        $fragments = [];
        $chunks = [];
        $missingIndex = null;
        $usedAnchors = [];
        try {
            foreach ($entry->decodedContent()['blocks'] as $index => $block) {
                $type = $block['type'];
                $data = $block['data'];
                $definition = $this->blocks->get($type);
                if ($definition === null) {
                    // Two unavailable renderers cannot in general be separated from one saved HTML string.
                    if ($missingIndex !== null) {
                        return null;
                    }
                    $missingIndex = $index;
                    $fragments[] = null;
                    continue;
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
        $knownHtml = implode("\n", $chunks);
        if ($missingIndex === null) {
            return $knownHtml === $entry->contentHtml ? $this->completeFragments($fragments) : null;
        }
        if ($knownHtml === $entry->contentHtml) {
            // The known output accounts for all saved HTML; core omits empty fragments.
            $fragments[$missingIndex] = '';
            return $this->completeFragments($fragments);
        }

        // With exactly one unavailable block, require the current known prefix and suffix
        // to match the saved HTML byte for byte before attributing the remainder to it.
        $before = array_filter(array_slice($fragments, 0, $missingIndex), static fn (?string $fragment): bool => $fragment !== null && $fragment !== '');
        $after = array_filter(array_slice($fragments, $missingIndex + 1), static fn (?string $fragment): bool => $fragment !== null && $fragment !== '');
        $prefix = $before === [] ? '' : implode("\n", $before) . "\n";
        $suffix = $after === [] ? '' : "\n" . implode("\n", $after);
        $length = strlen($entry->contentHtml) - strlen($prefix) - strlen($suffix);
        if ($length <= 0 || !str_starts_with($entry->contentHtml, $prefix) || !str_ends_with($entry->contentHtml, $suffix)) {
            return null;
        }
        $fragments[$missingIndex] = substr($entry->contentHtml, strlen($prefix), $length);
        $complete = $this->completeFragments($fragments);
        if ($complete === null) {
            return null;
        }
        $reconstructed = array_filter($complete, static fn (string $fragment): bool => $fragment !== '');
        return implode("\n", $reconstructed) === $entry->contentHtml ? $complete : null;
    }

    /**
     * @param list<string|null> $fragments
     * @return list<string>|null
     */
    private function completeFragments(array $fragments): ?array
    {
        $result = [];
        foreach ($fragments as $fragment) {
            if (!is_string($fragment)) {
                return null;
            }
            $result[] = $fragment;
        }
        return $result;
    }

    private function id(): string
    {
        return 'pb_' . bin2hex(random_bytes(8));
    }
}
