<?php

declare(strict_types=1);

namespace PrimeBuilder\Document;

/** Locate the builder block without changing unrelated Prime Editor documents. */
final class Layout
{
    /** @param array<string, mixed> $document @return array<string, mixed>|null */
    public static function fromDocument(array $document): ?array
    {
        foreach (is_array($document['blocks'] ?? null) ? $document['blocks'] : [] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'prime-builder/layout' && is_array($block['data'] ?? null)) {
                return $block['data'];
            }
        }
        return null;
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public static function document(array $data): array
    {
        return ['version' => 1, 'blocks' => [['id' => 'pb_layout', 'type' => 'prime-builder/layout', 'data' => $data]]];
    }
}
