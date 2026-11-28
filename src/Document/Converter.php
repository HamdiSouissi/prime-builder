<?php

declare(strict_types=1);

namespace PrimeBuilder\Document;

use Prime\Content\Entry;
use Prime\Editor\BlockRegistry;
use Prime\Media\MediaUsageRepository;
use Prime\Security\HtmlSanitizer;
use Prime\Support\Str;
use PrimeBuilder\Widgets\OriginalContent;
use PrimeBuilder\Widgets\PrimeBlock;
use PrimeBuilder\Widgets\Registry;
use Throwable;

/** Converts without guessing fragment boundaries; otherwise keeps the saved HTML together. */
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
        // The CMS decoder maps corrupt/scalar JSON to empty blocks, filters
        // non-array blocks and defaults malformed versions. Do not silently
        // turn discarded stored data into an edit. Blank input is accepted by
        // the core and can still have a separately saved HTML snapshot.
        // Associative decoding alone turns {"0": ...} into a PHP list, losing
        // the distinction between an object and the document's block array.
        $raw = json_decode($entry->content);
        if (!is_array($raw) && !is_object($raw)) {
            if (trim($entry->content, " \t\r\n") !== '') {
                return null;
            }
            $raw = [];
        }
        $raw = (array) $raw;
        if ((array_key_exists('version', $raw) && $raw['version'] !== 1)
            || (!array_key_exists('blocks', $raw) && array_diff_key($raw, ['version' => true]) !== [])) {
            return null;
        }
        if (array_key_exists('blocks', $raw)) {
            $rawBlocks = $raw['blocks'];
            // The core accepts an empty object as empty blocks; preserve that
            // legacy form, but never normalize a nonempty object into a list.
            if (is_object($rawBlocks) && get_object_vars($rawBlocks) === []) {
                $rawBlocks = [];
            }
            if (!is_array($rawBlocks) || !array_is_list($rawBlocks) || count($rawBlocks) !== count($blocks)) {
                return null;
            }
        }
        $layouts = [];
        // Validate every saved Builder block before attempting the grouped-HTML
        // fallback. A core duplicate may contain fragments bound to its source;
        // grouping its incomplete rendered output would otherwise hide that loss.
        foreach ($blocks as $index => $block) {
            if (!$this->usableBlock($block)) {
                return null;
            }
            if ($block['type'] !== 'prime-builder/layout') {
                continue;
            }
            $data = Layout::fromDocument(['blocks' => [$block]]);
            if ($document['version'] !== 1 || $data === null) {
                return null;
            }
            try {
                $validated = (new Validator($this->registry, $this->sanitizer, $this->blocks, $this->originals, $entry->id))->validate($data);
                if ($validated['errors'] !== []) {
                    return null;
                }
                $layouts[$index] = $validated['data'];
            } catch (Throwable) {
                return null;
            }
        }
        if (count($blocks) === 1 && isset($layouts[0])) {
            return $document;
        }
        if ($blocks === []) {
            return $entry->contentHtml === '' ? Layout::document(['version' => 1, 'elements' => []]) : $this->grouped($entry);
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
            return $this->grouped($entry);
        }

        $bridge = new PrimeBlock($this->blocks, $this->sanitizer);
        $children = [];
        try {
            foreach ($blocks as $index => $block) {
                $type = $block['type'];
                $data = $block['data'];
                if ($type === 'prime-builder/layout') {
                    $elements = $layouts[$index]['elements'] ?? null;
                    if (!is_array($elements)) {
                        return null;
                    }
                    foreach ($elements as $element) {
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
                    // Keep only IDs that the core itself recognizes for this block.
                    // The authenticated payload travels with its immutable HTML fragment.
                    $mediaIds = MediaUsageRepository::extractMediaIdsFromDocument(['blocks' => [$block]]);
                    $payload = $this->originals?->protect($entry->id, $type, $fragments[$index], mediaIds: $mediaIds);
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

    /** @param array<string, mixed> $block */
    private function usableBlock(array $block): bool
    {
        return is_string($block['type'] ?? null) && $block['type'] !== '' && is_array($block['data'] ?? null);
    }

    /**
     * Preserve the actual saved HTML, not a guessed re-render, as one authenticated leaf.
     *
     * @return array<string, mixed>|null
     */
    private function grouped(Entry $entry): ?array
    {
        try {
            $mediaIds = MediaUsageRepository::extractMediaIdsFromDocument($entry->decodedContent());
            $payload = $this->originals?->protect($entry->id, 'saved-document', $entry->contentHtml, true, $mediaIds);
            if ($payload === null) {
                return null;
            }
            $layout = ['version' => 1, 'elements' => [[
                'id' => $this->id(), 'type' => 'original_group',
                'settings' => ['payload' => $payload], 'styles' => [], 'children' => [],
            ]]];
            // Encryption expands the input; do not bypass the normal size, node or key checks.
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
     * Signal mismatches or multiple unavailable renderers so they can be preserved together.
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
     * @param array<int, string|null> $fragments
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
