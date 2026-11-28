<?php

declare(strict_types=1);

namespace PrimeBuilder\Widgets;

use DOMDocument;
use DOMElement;

/** Isolate IDs in a second rendering of trusted, already-rendered CMS content. */
final class ContentFragment
{
    /**
     * This is not an HTML sanitizer: the input is the CMS's own rendered content.
     * Scripts are removed from the copy so an embedded script is not executed
     * twice. Widget assets continue to be registered by the normal site hook.
     */
    public static function scope(string $html, string $prefix): string
    {
        if ($html === '' || strlen($html) > 2 * 1024 * 1024 || preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/D', $prefix) !== 1) {
            return '';
        }
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body><div id="__pb_content_root__">' . $html . '</div></body></html>',
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        $root = $loaded ? $document->getElementById('__pb_content_root__') : null;
        // Do not silently return a truncated fragment if malformed markup escapes
        // the wrapper. The original entry remains untouched in the theme.
        if (!$root instanceof DOMElement || $root->nextSibling !== null) {
            return '';
        }
        $nodes = [];
        foreach ($root->getElementsByTagName('*') as $node) {
            $nodes[] = $node;
        }
        /** @var array<string, string> $ids */
        $ids = [];
        $number = 0;
        foreach ($nodes as $node) {
            if (strtolower($node->tagName) === 'script') {
                $node->parentNode?->removeChild($node);
                continue;
            }
            $old = $node->getAttribute('id');
            if ($old === '') {
                continue;
            }
            $new = $prefix . '-' . ++$number;
            $ids[$old] ??= $new;
            $node->setAttribute('id', $new);
        }
        // Image maps use a name rather than an ID as their fragment target.
        // Scope names separately, since a map's name and id need not match.
        /** @var array<string, string> $maps */
        $maps = [];
        foreach ($nodes as $node) {
            if (strtolower($node->tagName) !== 'map' || !$node->hasAttribute('name')) {
                continue;
            }
            $old = $node->getAttribute('name');
            if ($old !== '') {
                $new = $prefix . '-map-' . ++$number;
                $maps[$old] ??= $new;
                $node->setAttribute('name', $new);
            }
        }
        $imageMapTargets = $maps + $ids;
        $idLists = ['for', 'list', 'form', 'headers', 'aria-labelledby', 'aria-describedby', 'aria-controls', 'aria-owns', 'aria-flowto', 'aria-activedescendant', 'aria-details', 'aria-errormessage'];
        foreach ($nodes as $node) {
            foreach ($idLists as $attribute) {
                if (!$node->hasAttribute($attribute)) {
                    continue;
                }
                $value = $node->getAttribute($attribute);
                $parts = preg_split('/\s+/', trim($value)) ?: [];
                $node->setAttribute($attribute, implode(' ', array_map(static fn (string $part): string => $ids[$part] ?? $part, $parts)));
            }
            foreach (['href', 'xlink:href', 'usemap'] as $attribute) {
                $value = $node->getAttribute($attribute);
                if (!str_starts_with($value, '#')) {
                    continue;
                }
                $targets = $attribute === 'usemap' ? $imageMapTargets : $ids;
                $target = substr($value, 1);
                $mapped = $targets[$target] ?? $targets[rawurldecode($target)] ?? null;
                if ($mapped !== null) {
                    $node->setAttribute($attribute, '#' . $mapped);
                }
            }
            // References to SVG masks, filters and gradients can occur in either
            // a presentation attribute or an inline style.
            foreach (['style', 'fill', 'stroke', 'filter', 'clip-path', 'mask', 'marker-start', 'marker-mid', 'marker-end'] as $attribute) {
                if (!$node->hasAttribute($attribute)) {
                    continue;
                }
                $value = preg_replace_callback('/url\(\s*(["\']?)#([^\s)"\']+)\1\s*\)/', static function (array $match) use ($ids): string {
                    $mapped = $ids[$match[2]] ?? $ids[rawurldecode($match[2])] ?? null;
                    return $mapped !== null ? 'url(#' . $mapped . ')' : $match[0];
                }, $node->getAttribute($attribute));
                if (is_string($value)) {
                    $node->setAttribute($attribute, $value);
                }
            }
        }
        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $document->saveHTML($child) ?: '';
        }
        return $output;
    }
}
