<?php

declare(strict_types=1);

namespace PrimeBuilder\Widgets;

use Prime\Content\Entry;
use Prime\Editor\BlockDefinition;
use Prime\Editor\BlockRegistry;
use Prime\Security\HtmlSanitizer;
use Throwable;

/** A deliberately limited bridge to non-privileged, static Prime Editor blocks. */
final class PrimeBlock
{
    /** @var list<string> */
    public const ALLOWED = ['paragraph', 'heading', 'list', 'quote', 'code', 'separator', 'table', 'callout'];

    public function __construct(private readonly BlockRegistry $blocks, private readonly HtmlSanitizer $sanitizer)
    {
    }

    private function definition(string $type): ?BlockDefinition
    {
        if (!in_array($type, self::ALLOWED, true)) {
            return null;
        }
        $definition = $this->blocks->get($type);
        return $definition !== null && $definition->source === 'core' && $definition->requiresCapability === null ? $definition : null;
    }

    /** Return canonical JSON after the real core block validator has accepted it. */
    public function normalize(string $type, string $json): ?string
    {
        $definition = $this->definition($type);
        if ($definition === null || strlen($json) > 20000 || !is_object(json_decode($json))) {
            return null;
        }
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }
        try {
            $result = ($definition->validator)($data, $this->sanitizer);
            if ($result['errors'] !== []) {
                return null;
            }
            $encoded = json_encode((object) $result['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
            return is_string($encoded) ? $encoded : null;
        } catch (Throwable) {
            return null;
        }
    }

    public function render(string $type, string $json, string $id, ?Entry $entry, ?string $anchor = null): string
    {
        $normalized = $this->normalize($type, $json);
        $definition = $this->definition($type);
        if ($normalized === null || $definition === null) {
            return '';
        }
        $data = json_decode($normalized, true);
        if (!is_array($data)) {
            return '';
        }
        return ($definition->renderer)($data, $this->sanitizer, [
            'entry' => $entry,
            'allowUnfilteredHtml' => false,
            // New widgets are namespaced; converted headings can retain their original public anchor.
            'anchor' => $anchor ?? 'pb-' . $id,
        ]);
    }
}
