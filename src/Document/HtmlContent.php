<?php

declare(strict_types=1);

namespace PrimeBuilder\Document;

use Prime\Security\Encrypter;
use Throwable;

/**
 * Authenticate filtered HTML authored through the builder. The CMS block validator has
 * no actor or entry argument, so it checks the seal and the renderer checks the entry.
 */
final class HtmlContent
{
    private function __construct(private readonly string $key)
    {
    }

    public static function fromAppKey(mixed $appKey): ?self
    {
        if (!is_string($appKey)) {
            return null;
        }
        try {
            // Follow the CMS key requirements instead of deriving a key from an empty secret.
            new Encrypter($appKey);
            return new self(hash_hkdf('sha256', $appKey, 32, 'prime-builder:filtered-html:v1'));
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $settings */
    public function valid(string $id, array $settings, ?int $entryId = null): bool
    {
        $entry = $settings['signed_entry'] ?? null;
        $markup = $settings['markup'] ?? null;
        $signature = $settings['signature'] ?? null;
        if (!is_int($entry) || $entry < 1 || ($entryId !== null && $entry !== $entryId)
            || !is_string($markup) || !is_string($signature) || preg_match('/^[a-f0-9]{64}$/D', $signature) !== 1) {
            return false;
        }
        return hash_equals($this->signature($entry, $id, $markup), $signature);
    }

    /**
     * Only a privileged actor may add or change HTML. Existing authenticated nodes may
     * be retained, moved or deleted by other editors; copying one requires the capability.
     *
     * @param array<string, mixed> $layout Normalized candidate layout
     * @param array<string, mixed> $document Previously saved Prime document
     */
    public function allows(array $layout, array $document, int $entryId, bool $canEdit): bool
    {
        if ($canEdit) {
            return true;
        }
        $available = [];
        foreach (is_array($document['blocks'] ?? null) ? $document['blocks'] : [] as $block) {
            if (!is_array($block) || ($block['type'] ?? null) !== 'prime-builder/layout' || !is_array($block['data'] ?? null)) {
                continue;
            }
            foreach (self::htmlNodes($block['data']) as $node) {
                $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];
                $id = is_string($node['id'] ?? null) ? $node['id'] : '';
                if ($this->valid($id, $settings, $entryId)) {
                    $fingerprint = self::fingerprint($id, (string) $settings['markup']);
                    $available[$fingerprint] = ($available[$fingerprint] ?? 0) + 1;
                }
            }
        }
        foreach (self::htmlNodes($layout) as $node) {
            $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];
            $id = is_string($node['id'] ?? null) ? $node['id'] : '';
            if (!$this->valid($id, $settings, $entryId)) {
                return false;
            }
            $markup = (string) $settings['markup'];
            $fingerprint = self::fingerprint($id, $markup);
            if (($available[$fingerprint] ?? 0) < 1) {
                return false;
            }
            $available[$fingerprint]--;
        }
        return true;
    }

    /**
     * @param array<string, mixed> $candidate
     * @param array<string, mixed> $previous
     */
    public function allowsDocument(array $candidate, array $previous, int $entryId): bool
    {
        $elements = [];
        foreach (is_array($candidate['blocks'] ?? null) ? $candidate['blocks'] : [] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'prime-builder/layout' && is_array($block['data']['elements'] ?? null)) {
                foreach ($block['data']['elements'] as $element) {
                    $elements[] = $element;
                }
            }
        }
        return $this->allows(['elements' => $elements], $previous, $entryId, false);
    }

    /** @param array<string, mixed> $layout */
    public static function contains(array $layout): bool
    {
        return self::htmlNodes($layout) !== [];
    }

    /** @param array<string, mixed> $document */
    public static function documentContains(array $document): bool
    {
        foreach (is_array($document['blocks'] ?? null) ? $document['blocks'] : [] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'prime-builder/layout' && is_array($block['data'] ?? null) && self::contains($block['data'])) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $document */
    public function documentIsBound(array $document, int $entryId): bool
    {
        foreach (is_array($document['blocks'] ?? null) ? $document['blocks'] : [] as $block) {
            if (!is_array($block) || ($block['type'] ?? null) !== 'prime-builder/layout' || !is_array($block['data'] ?? null)) {
                continue;
            }
            foreach (self::htmlNodes($block['data']) as $node) {
                $id = is_string($node['id'] ?? null) ? $node['id'] : '';
                $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];
                if (!$this->valid($id, $settings, $entryId)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public function sealDocument(array $document, int $entryId): array
    {
        if (!is_array($document['blocks'] ?? null)) {
            return $document;
        }
        foreach ($document['blocks'] as $index => $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'prime-builder/layout' && is_array($block['data'] ?? null)) {
                $block['data'] = $this->seal($block['data'], $entryId);
                $document['blocks'][$index] = $block;
            }
        }
        return $document;
    }

    /**
     * Sign a validated, sanitized layout for its target entry. Signatures are regenerated
     * server-side, never trusted from the editor or an imported template.
     *
     * @param array<string, mixed> $layout
     * @return array<string, mixed>
     */
    public function seal(array $layout, int $entryId): array
    {
        return self::map($layout, function (array $node) use ($entryId): array {
            $id = is_string($node['id'] ?? null) ? $node['id'] : '';
            $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];
            $markup = is_string($settings['markup'] ?? null) ? $settings['markup'] : '';
            $node['settings'] = [
                'markup' => $markup,
                'signed_entry' => $entryId,
                'signature' => $this->signature($entryId, $id, $markup),
            ];
            return $node;
        });
    }

    /**
     * Remove entry-bound signatures from reusable templates.
     *
     * @param array<string, mixed> $layout
     * @return array<string, mixed>
     */
    public static function withoutSeals(array $layout): array
    {
        return self::map($layout, static function (array $node): array {
            $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];
            unset($settings['signed_entry'], $settings['signature']);
            $node['settings'] = $settings;
            return $node;
        });
    }

    /**
     * A core duplicate creates a new entry before its ID exists. Preserve the already
     * filtered content as an ordinary rich-text widget instead of copying an entry-bound
     * HTML seal or silently discarding the whole new document.
     *
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    public static function asTextDocument(array $document): array
    {
        if (!is_array($document['blocks'] ?? null)) {
            return $document;
        }
        foreach ($document['blocks'] as $index => $block) {
            if (!is_array($block) || ($block['type'] ?? null) !== 'prime-builder/layout' || !is_array($block['data'] ?? null)) {
                continue;
            }
            $block['data'] = self::map($block['data'], static function (array $node): array {
                $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];
                $node['type'] = 'text';
                $node['settings'] = ['text' => is_string($settings['markup'] ?? null) ? $settings['markup'] : ''];
                return $node;
            });
            $document['blocks'][$index] = $block;
        }
        return $document;
    }

    private function signature(int $entryId, string $id, string $markup): string
    {
        return hash_hmac('sha256', $entryId . "\0" . $id . "\0" . $markup, $this->key);
    }

    private static function fingerprint(string $id, string $markup): string
    {
        return hash('sha256', $id . "\0" . $markup);
    }

    /**
     * @param array<string, mixed> $layout
     * @return list<array<string, mixed>>
     */
    private static function htmlNodes(array $layout): array
    {
        $pending = is_array($layout['elements'] ?? null) ? $layout['elements'] : [];
        $found = [];
        while ($pending !== []) {
            $node = array_pop($pending);
            if (!is_array($node)) {
                continue;
            }
            if (($node['type'] ?? null) === 'html') {
                $found[] = $node;
            }
            if (is_array($node['children'] ?? null)) {
                foreach ($node['children'] as $child) {
                    $pending[] = $child;
                }
            }
        }
        return $found;
    }

    /**
     * @param array<string, mixed> $layout
     * @param callable(array<string, mixed>): array<string, mixed> $transform
     * @return array<string, mixed>
     */
    private static function map(array $layout, callable $transform): array
    {
        $walk = static function (array $nodes) use (&$walk, $transform): array {
            foreach ($nodes as $index => $node) {
                if (!is_array($node)) {
                    continue;
                }
                if (($node['type'] ?? null) === 'html') {
                    $node = $transform($node);
                }
                if (is_array($node['children'] ?? null)) {
                    $node['children'] = $walk($node['children']);
                }
                $nodes[$index] = $node;
            }
            return $nodes;
        };
        $layout['elements'] = $walk(is_array($layout['elements'] ?? null) ? $layout['elements'] : []);
        return $layout;
    }
}
