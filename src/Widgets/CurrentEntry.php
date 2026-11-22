<?php

declare(strict_types=1);

namespace PrimeBuilder\Widgets;

use Prime\Site\View\EntryView;

/** Render individual pieces of the current entry using the CMS's public view. */
final class CurrentEntry
{
    /** @param array<string, mixed> $settings */
    public static function title(array $settings, ?EntryView $entry): string
    {
        if ($entry === null || trim($entry->rawTitle()) === '') {
            return '';
        }
        $tag = is_string($settings['tag'] ?? null) && in_array($settings['tag'], ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'], true) ? $settings['tag'] : 'h2';
        $title = $entry->title();
        if (($settings['link'] ?? 'hide') === 'show') {
            $title = '<a href="' . $entry->url() . '">' . $title . '</a>';
        }
        return '<' . $tag . ' class="pb-entry-title__text">' . $title . '</' . $tag . '>';
    }

    /** @param array<string, mixed> $settings */
    public static function excerpt(array $settings, ?EntryView $entry): string
    {
        if ($entry === null) {
            return '';
        }
        $length = max(1, min(1000, (int) ($settings['length'] ?? 180)));
        $excerpt = $entry->excerpt($length);
        return $excerpt === '' ? '' : '<p class="pb-entry-excerpt__text">' . $excerpt . '</p>';
    }

    /** @param array<string, mixed> $settings */
    public static function featuredImage(array $settings, ?EntryView $entry): string
    {
        if ($entry === null) {
            return '';
        }
        $size = is_string($settings['size'] ?? null) && in_array($settings['size'], ['thumbnail', 'medium', 'large', 'full'], true) ? $settings['size'] : 'large';
        return $entry->featuredImage($size, ['class' => 'pb-entry-featured-image__image', 'loading' => 'lazy']);
    }

    /** @param array<string, mixed> $settings */
    public static function info(array $settings, ?EntryView $entry, bool $publicCategories): string
    {
        if ($entry === null) {
            return '';
        }
        $items = [];
        if (($settings['date'] ?? 'show') === 'show') {
            $date = $entry->date();
            $iso = $entry->isoDate();
            if ($date !== '' && $iso !== '') {
                $items[] = '<li><span>' . Renderer::escape(t('plugin::prime-builder.ui.entry_info_date')) . '</span> <time datetime="' . Renderer::escape($iso) . '">' . $date . '</time></li>';
            }
        }
        if (($settings['author'] ?? 'show') === 'show') {
            $author = $entry->author();
            if ($author['id'] > 0 && $author['name'] !== '') {
                $items[] = '<li><span>' . Renderer::escape(t('plugin::prime-builder.ui.entry_info_author')) . '</span> <a href="' . $author['url'] . '">' . $author['name'] . '</a></li>';
            }
        }
        if (($settings['categories'] ?? 'show') === 'show' && $publicCategories) {
            $links = [];
            foreach ($entry->terms('category') as $term) {
                $links[] = '<a href="' . $term['url'] . '">' . $term['name'] . '</a>';
            }
            if ($links !== []) {
                $items[] = '<li><span>' . Renderer::escape(t('plugin::prime-builder.ui.entry_info_categories')) . '</span> ' . implode(', ', $links) . '</li>';
            }
        }
        if ($items === []) {
            return '';
        }
        return '<ul class="pb-entry-info__list" aria-label="' . Renderer::escape(t('plugin::prime-builder.ui.entry_info_label')) . '">' . implode('', $items) . '</ul>';
    }
}
