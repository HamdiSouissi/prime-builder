<?php

declare(strict_types=1);

namespace PrimeBuilder\Widgets;

use Prime\Hooks\Hooks;

/** Widget definitions are declarative; extensions may add definitions via prime_builder.widgets. */
final class Registry
{
    public function __construct(private readonly ?Hooks $hooks = null)
    {
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $text = ['type' => 'text', 'max' => 1000];
        $url = ['type' => 'url', 'max' => 2048];
        $icon = ['type' => 'enum', 'values' => ['star', 'check', 'heart', 'spark']];
        $dimension = ['type' => 'integer', 'min' => 1, 'max' => 10000];
        $listDimensions = ['type' => 'media_dimensions', 'max' => MediaDimensions::MAX_BYTES, 'internal' => true];
        $manualDimensions = ['type' => 'media_dimensions_manual', 'max' => MediaDimensions::MAX_BYTES, 'label' => 'plugin::prime-builder.ui.image_dimensions_manual'];
        $fields = [
            'container' => ['layout' => ['type' => 'enum', 'values' => ['flex', 'grid']], 'direction' => ['type' => 'enum', 'values' => ['column', 'row']], 'columns' => ['type' => 'integer', 'min' => 1, 'max' => 6]],
            'heading' => ['text' => $text, 'level' => ['type' => 'integer', 'min' => 1, 'max' => 6]],
            'text' => ['text' => ['type' => 'rich', 'max' => 20000]],
            'image' => ['url' => $url, 'alt' => $text, 'width' => ['type' => 'integer', 'min' => 1, 'max' => 10000], 'height' => ['type' => 'integer', 'min' => 1, 'max' => 10000]],
            'button' => ['text' => $text, 'url' => ['type' => 'href', 'max' => 2048]],
            'separator' => [],
            'spacer' => [],
            'quote' => ['text' => ['type' => 'rich', 'max' => 5000], 'author' => $text],
            'alert' => ['text' => ['type' => 'rich', 'max' => 5000]],
            'progress' => ['text' => $text, 'value' => ['type' => 'integer', 'min' => 0, 'max' => 100]],
            'anchor' => ['anchor' => ['type' => 'slug', 'max' => 64]],
            'list' => ['items' => ['type' => 'multiline', 'max' => 5000], 'list_type' => ['type' => 'enum', 'values' => ['unordered', 'ordered']]],
            'accordion' => ['title' => $text, 'text' => ['type' => 'rich', 'max' => 10000]],
            'card' => ['title' => $text, 'text' => ['type' => 'rich', 'max' => 10000], 'image_url' => $url, 'alt' => $text, 'width' => $dimension, 'height' => $dimension, 'url' => ['type' => 'href', 'max' => 2048], 'button_text' => $text],
            'icon_box' => ['icon' => $icon, 'title' => $text, 'text' => ['type' => 'rich', 'max' => 5000]],
            'icon' => ['icon' => $icon, 'accessible_label' => $text],
            'icon_list' => ['icon' => $icon, 'items' => ['type' => 'multiline', 'max' => 5000]],
            'image_box' => ['image_url' => $url, 'alt' => $text, 'width' => $dimension, 'height' => $dimension, 'title' => $text, 'text' => ['type' => 'rich', 'max' => 10000], 'url' => ['type' => 'href', 'max' => 2048], 'button_text' => $text],
            'testimonial' => ['text' => ['type' => 'rich', 'max' => 5000], 'author' => $text, 'job_title' => $text, 'image_url' => $url, 'alt' => $text, 'width' => $dimension, 'height' => $dimension],
            'testimonial_carousel' => [
                'label' => ['type' => 'text', 'max' => 200, 'label' => 'plugin::prime-builder.ui.carousel_label'],
                'items' => ['type' => 'testimonial_items', 'max' => 10000, 'label' => 'plugin::prime-builder.ui.testimonials_items'],
                'images_dimensions' => $listDimensions, 'manual_dimensions' => $manualDimensions,
            ],
            'media_carousel' => [
                'label' => ['type' => 'text', 'max' => 200, 'label' => 'plugin::prime-builder.ui.carousel_label'],
                'items' => ['type' => 'media_carousel_items', 'max' => 15000, 'label' => 'plugin::prime-builder.ui.media_carousel_items'],
                'images_dimensions' => $listDimensions, 'manual_dimensions' => $manualDimensions,
                'caption_language' => ['type' => 'enum', 'values' => ['fr', 'en'], 'label' => 'plugin::prime-builder.ui.caption_language'],
            ],
            'slideshow' => [
                'label' => ['type' => 'text', 'max' => 200, 'label' => 'plugin::prime-builder.ui.slideshow_label'],
                'items' => ['type' => 'slideshow_items', 'max' => 15000, 'label' => 'plugin::prime-builder.ui.slideshow_items'],
                'images_dimensions' => $listDimensions, 'manual_dimensions' => $manualDimensions,
                'level' => ['type' => 'integer', 'min' => 2, 'max' => 6],
            ],
            'portfolio' => [
                'items' => ['type' => 'portfolio_items', 'max' => 15000, 'label' => 'plugin::prime-builder.ui.portfolio_items'],
                'images_dimensions' => $listDimensions, 'manual_dimensions' => $manualDimensions,
                'columns' => ['type' => 'integer', 'min' => 1, 'max' => 4],
                'level' => ['type' => 'integer', 'min' => 2, 'max' => 6],
            ],
            'review' => [
                'title' => $text, 'level' => ['type' => 'integer', 'min' => 2, 'max' => 6],
                'rating' => ['type' => 'integer', 'min' => 0, 'max' => 5, 'label' => 'plugin::prime-builder.ui.review_rating'],
                'text' => ['type' => 'rich', 'max' => 5000], 'author' => $text, 'job_title' => $text,
                'image_url' => $url, 'alt' => $text, 'width' => $dimension, 'height' => $dimension,
            ],
            'flip_box' => [
                'front_title' => ['type' => 'text', 'max' => 200, 'label' => 'plugin::prime-builder.ui.flip_front_title'],
                'front_text' => ['type' => 'rich', 'max' => 5000, 'label' => 'plugin::prime-builder.ui.flip_front_text'],
                'back_title' => ['type' => 'text', 'max' => 200, 'label' => 'plugin::prime-builder.ui.flip_back_title'],
                'back_text' => ['type' => 'rich', 'max' => 5000, 'label' => 'plugin::prime-builder.ui.flip_back_text'],
                'level' => ['type' => 'integer', 'min' => 2, 'max' => 6],
                'url' => ['type' => 'href', 'max' => 2048], 'button_text' => $text,
            ],
            'animated_heading' => [
                'before' => ['type' => 'text', 'max' => 300, 'label' => 'plugin::prime-builder.ui.animated_before'],
                'highlight' => ['type' => 'text', 'max' => 300, 'label' => 'plugin::prime-builder.ui.animated_highlight'],
                'after' => ['type' => 'text', 'max' => 300, 'label' => 'plugin::prime-builder.ui.animated_after'],
                'level' => ['type' => 'integer', 'min' => 1, 'max' => 6],
                'effect' => ['type' => 'enum', 'values' => ['underline', 'rise'], 'label' => 'plugin::prime-builder.ui.animated_effect', 'option_labels' => ['underline' => 'plugin::prime-builder.ui.animated_underline', 'rise' => 'plugin::prime-builder.ui.animated_rise']],
            ],
            'code' => [
                'label' => ['type' => 'text', 'max' => 200, 'label' => 'plugin::prime-builder.ui.code_label'],
                'language' => ['type' => 'enum', 'values' => ['text', 'html', 'css', 'javascript', 'json', 'php'], 'label' => 'plugin::prime-builder.ui.code_language', 'option_labels' => ['text' => 'plugin::prime-builder.ui.code_plain']],
                'source' => ['type' => 'code', 'max' => 20000, 'label' => 'plugin::prime-builder.ui.code_source'],
            ],
            'call_to_action' => ['title' => $text, 'level' => ['type' => 'integer', 'min' => 2, 'max' => 6], 'text' => ['type' => 'rich', 'max' => 10000], 'image_url' => $url, 'alt' => $text, 'width' => $dimension, 'height' => $dimension, 'url' => ['type' => 'href', 'max' => 2048], 'button_text' => $text],
            'price_table' => ['title' => $text, 'level' => ['type' => 'integer', 'min' => 2, 'max' => 6], 'text' => ['type' => 'rich', 'max' => 5000], 'price' => ['type' => 'text', 'max' => 120, 'label' => 'plugin::prime-builder.ui.price'], 'period' => ['type' => 'text', 'max' => 120, 'label' => 'plugin::prime-builder.ui.period'], 'features' => ['type' => 'multiline', 'max' => 5000, 'label' => 'plugin::prime-builder.ui.price_features'], 'url' => ['type' => 'href', 'max' => 2048], 'button_text' => $text],
            'price_list' => ['title' => $text, 'level' => ['type' => 'integer', 'min' => 2, 'max' => 6], 'items' => ['type' => 'price_items', 'max' => 10000, 'label' => 'plugin::prime-builder.ui.price_items']],
            'countdown' => [
                'title' => $text,
                'level' => ['type' => 'integer', 'min' => 2, 'max' => 6],
                'deadline' => ['type' => 'datetime_utc', 'max' => 16, 'label' => 'plugin::prime-builder.ui.countdown_deadline'],
                'finished_text' => ['type' => 'text', 'max' => 200, 'label' => 'plugin::prime-builder.ui.countdown_finished_text'],
            ],
            'search_form' => ['search_label' => ['type' => 'text', 'max' => 200, 'label' => 'plugin::prime-builder.ui.search_form_label'], 'placeholder' => ['type' => 'text', 'max' => 200, 'label' => 'plugin::prime-builder.ui.search_form_placeholder'], 'button_text' => $text],
            'navigation' => [
                'menu_id' => ['type' => 'menu_id', 'max' => 9, 'label' => 'plugin::prime-builder.ui.navigation_select'],
                'label' => ['type' => 'text', 'max' => 200, 'label' => 'plugin::prime-builder.ui.navigation_label'],
            ],
            'breadcrumbs' => [
                'label' => ['type' => 'text', 'max' => 200, 'label' => 'plugin::prime-builder.ui.breadcrumb_label'],
            ],
            'site_title' => [
                'tag' => ['type' => 'enum', 'values' => ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'], 'label' => 'plugin::prime-builder.ui.site_title_tag', 'option_labels' => ['p' => 'plugin::prime-builder.ui.site_title_paragraph']],
                'link_home' => ['type' => 'enum', 'values' => ['show', 'hide'], 'label' => 'plugin::prime-builder.ui.site_identity_link'],
            ],
            'site_logo' => [
                'link_home' => ['type' => 'enum', 'values' => ['show', 'hide'], 'label' => 'plugin::prime-builder.ui.site_identity_link'],
            ],
            'entry_title' => [
                'tag' => ['type' => 'enum', 'values' => ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'], 'label' => 'plugin::prime-builder.ui.entry_title_tag', 'option_labels' => ['p' => 'plugin::prime-builder.ui.site_title_paragraph']],
                'link' => ['type' => 'enum', 'values' => ['show', 'hide'], 'label' => 'plugin::prime-builder.ui.entry_title_link'],
            ],
            'entry_excerpt' => [
                'length' => ['type' => 'integer', 'min' => 1, 'max' => 1000, 'label' => 'plugin::prime-builder.ui.entry_excerpt_length'],
            ],
            'entry_content' => [],
            'entry_featured_image' => [
                'size' => ['type' => 'enum', 'values' => ['thumbnail', 'medium', 'large', 'full'], 'label' => 'plugin::prime-builder.ui.entry_image_size', 'option_labels' => ['thumbnail' => 'plugin::prime-builder.ui.entry_image_thumbnail', 'medium' => 'plugin::prime-builder.ui.entry_image_medium', 'large' => 'plugin::prime-builder.ui.entry_image_large', 'full' => 'plugin::prime-builder.ui.entry_image_full']],
            ],
            'entry_info' => [
                'date' => ['type' => 'enum', 'values' => ['show', 'hide'], 'label' => 'plugin::prime-builder.ui.entry_show_date'],
                'author' => ['type' => 'enum', 'values' => ['show', 'hide'], 'label' => 'plugin::prime-builder.ui.entry_show_author'],
                'categories' => ['type' => 'enum', 'values' => ['show', 'hide'], 'label' => 'plugin::prime-builder.ui.entry_show_categories'],
            ],
            'entry_navigation' => [
                'label' => ['type' => 'text', 'max' => 200, 'label' => 'plugin::prime-builder.ui.entry_navigation_label'],
                'previous' => ['type' => 'enum', 'values' => ['show', 'hide'], 'label' => 'plugin::prime-builder.ui.entry_navigation_show_previous'],
                'next' => ['type' => 'enum', 'values' => ['show', 'hide'], 'label' => 'plugin::prime-builder.ui.entry_navigation_show_next'],
            ],
            'entry_author' => [
                'avatar' => ['type' => 'enum', 'values' => ['show', 'hide'], 'label' => 'plugin::prime-builder.ui.entry_author_show_avatar'],
                'bio' => ['type' => 'enum', 'values' => ['show', 'hide'], 'label' => 'plugin::prime-builder.ui.entry_author_show_bio'],
                'link' => ['type' => 'enum', 'values' => ['show', 'hide'], 'label' => 'plugin::prime-builder.ui.entry_author_archive_link'],
            ],
            'archive_title' => [
                'tag' => ['type' => 'enum', 'values' => ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'], 'label' => 'plugin::prime-builder.ui.archive_title_tag', 'option_labels' => ['p' => 'plugin::prime-builder.ui.site_title_paragraph']],
            ],
            'archive_list' => [
                'layout' => ['type' => 'enum', 'values' => ['list', 'cards'], 'label' => 'plugin::prime-builder.ui.post_layout', 'option_labels' => ['list' => 'plugin::prime-builder.ui.post_list', 'cards' => 'plugin::prime-builder.ui.post_cards']],
                'columns' => ['type' => 'integer', 'min' => 1, 'max' => 4, 'label' => 'plugin::prime-builder.ui.post_columns'],
                'show_excerpt' => ['type' => 'enum', 'values' => ['show', 'hide'], 'label' => 'plugin::prime-builder.ui.show_excerpt'],
                'pagination' => ['type' => 'enum', 'values' => ['show', 'hide'], 'label' => 'plugin::prime-builder.ui.post_pagination'],
            ],
            'share_buttons' => [
                'label' => ['type' => 'text', 'max' => 200, 'label' => 'plugin::prime-builder.ui.share_label'],
                'message' => ['type' => 'text', 'max' => 200, 'label' => 'plugin::prime-builder.ui.share_message'],
                'facebook' => ['type' => 'enum', 'values' => ['show', 'hide'], 'label' => 'plugin::prime-builder.ui.share_toggle_facebook'],
                'linkedin' => ['type' => 'enum', 'values' => ['show', 'hide'], 'label' => 'plugin::prime-builder.ui.share_toggle_linkedin'],
                'x' => ['type' => 'enum', 'values' => ['show', 'hide'], 'label' => 'plugin::prime-builder.ui.share_toggle_x'],
                'email' => ['type' => 'enum', 'values' => ['show', 'hide'], 'label' => 'plugin::prime-builder.ui.share_toggle_email'],
            ],
            'table_of_contents' => [
                'label' => ['type' => 'text', 'max' => 200, 'label' => 'plugin::prime-builder.ui.toc_label'],
                'min_level' => ['type' => 'integer', 'min' => 1, 'max' => 6, 'label' => 'plugin::prime-builder.ui.toc_min_level'],
                'max_level' => ['type' => 'integer', 'min' => 1, 'max' => 6, 'label' => 'plugin::prime-builder.ui.toc_max_level'],
            ],
            'toggle' => ['title' => $text, 'text' => ['type' => 'rich', 'max' => 10000], 'initial' => ['type' => 'enum', 'values' => ['closed', 'open']]],
            'social' => ['label' => $text, 'facebook' => ['type' => 'href', 'max' => 2048], 'instagram' => ['type' => 'href', 'max' => 2048], 'linkedin' => ['type' => 'href', 'max' => 2048], 'youtube' => ['type' => 'href', 'max' => 2048], 'mastodon' => ['type' => 'href', 'max' => 2048]],
            'theme_zone' => ['zone' => ['type' => 'zone_slug', 'max' => 80, 'label' => 'plugin::prime-builder.ui.theme_zone_select']],
            'map' => ['title' => ['type' => 'text', 'max' => 1000, 'label' => 'plugin::prime-builder.ui.map_title'], 'latitude' => ['type' => 'latitude', 'max' => 16, 'label' => 'plugin::prime-builder.ui.map_latitude'], 'longitude' => ['type' => 'longitude', 'max' => 16, 'label' => 'plugin::prime-builder.ui.map_longitude'], 'zoom' => ['type' => 'integer', 'min' => 3, 'max' => 18, 'label' => 'plugin::prime-builder.ui.map_zoom']],
            'star_rating' => ['value' => ['type' => 'integer', 'min' => 0, 'max' => 5]],
            'counter' => ['value' => ['type' => 'integer', 'min' => 0, 'max' => 1000000000], 'prefix' => ['type' => 'text', 'max' => 1000, 'preserve_whitespace' => true], 'suffix' => ['type' => 'text', 'max' => 1000, 'preserve_whitespace' => true], 'title' => $text],
            'gallery' => [
                'images' => ['type' => 'media_list', 'max' => 10000],
                'images_dimensions' => $listDimensions, 'manual_dimensions' => $manualDimensions,
            ],
            'image_carousel' => [
                'label' => ['type' => 'text', 'max' => 1000, 'label' => 'plugin::prime-builder.ui.carousel_label'],
                'images' => ['type' => 'media_list', 'max' => 10000],
                'images_dimensions' => $listDimensions, 'manual_dimensions' => $manualDimensions,
            ],
            'video' => ['url' => $url, 'video_title' => $text, 'captions' => $url, 'caption_language' => ['type' => 'enum', 'values' => ['fr', 'en']], 'transcript' => ['type' => 'rich', 'max' => 20000]],
            'audio' => ['url' => $url, 'transcript' => ['type' => 'rich', 'max' => 20000]],
            'posts' => [
                'content_type' => ['type' => 'text', 'max' => 40, 'label' => 'plugin::prime-builder.ui.post_type'],
                'taxonomy' => ['type' => 'text', 'max' => 40, 'label' => 'plugin::prime-builder.ui.post_taxonomy'],
                'term_ids' => ['type' => 'term_ids', 'max' => 200, 'label' => 'plugin::prime-builder.ui.post_terms'],
                'author_id' => ['type' => 'integer', 'min' => 1, 'max' => 2147483647, 'label' => 'plugin::prime-builder.ui.post_author'],
                'order_by' => [
                    'type' => 'enum', 'values' => ['published_at', 'created_at', 'updated_at', 'title', 'menu_order'], 'label' => 'plugin::prime-builder.ui.post_order',
                    'option_labels' => ['published_at' => 'plugin::prime-builder.ui.post_published', 'created_at' => 'plugin::prime-builder.ui.post_created', 'updated_at' => 'plugin::prime-builder.ui.post_updated', 'title' => 'plugin::prime-builder.ui.post_title', 'menu_order' => 'plugin::prime-builder.ui.post_menu_order'],
                ],
                'order_direction' => ['type' => 'enum', 'values' => ['desc', 'asc'], 'label' => 'plugin::prime-builder.ui.post_direction', 'option_labels' => ['desc' => 'plugin::prime-builder.ui.post_descending', 'asc' => 'plugin::prime-builder.ui.post_ascending']],
                'limit' => ['type' => 'integer', 'min' => 1, 'max' => 12],
                'pagination' => ['type' => 'enum', 'values' => ['hide', 'show'], 'label' => 'plugin::prime-builder.ui.post_pagination'],
                'layout' => ['type' => 'enum', 'values' => ['list', 'cards'], 'label' => 'plugin::prime-builder.ui.post_layout', 'option_labels' => ['list' => 'plugin::prime-builder.ui.post_list', 'cards' => 'plugin::prime-builder.ui.post_cards']],
                'columns' => ['type' => 'integer', 'min' => 1, 'max' => 4, 'label' => 'plugin::prime-builder.ui.post_columns'],
                'show_excerpt' => ['type' => 'enum', 'values' => ['show', 'hide']],
            ],
            'current_content' => ['source' => ['type' => 'enum', 'values' => ['title', 'excerpt', 'published', 'author']]],
            'form' => ['title' => $text, 'button_text' => $text],
            'prime_block' => ['block_type' => ['type' => 'enum', 'values' => PrimeBlock::ALLOWED], 'block_data' => ['type' => 'json_object', 'max' => 20000], 'block_anchor' => ['type' => 'text', 'max' => 100]],
            'html' => [
                'markup' => ['type' => 'rich', 'max' => 20000],
                'signed_entry' => ['type' => 'integer', 'min' => 1, 'max' => PHP_INT_MAX, 'internal' => true],
                'signature' => ['type' => 'signature', 'max' => 64, 'internal' => true],
            ],
            'original_content' => ['payload' => ['type' => 'protected', 'max' => 1048576]],
            'original_group' => ['payload' => ['type' => 'protected', 'max' => 1048576]],
            'tabs' => [
                'label' => $text,
                'title_1' => $text, 'text_1' => ['type' => 'rich', 'max' => 10000],
                'title_2' => $text, 'text_2' => ['type' => 'rich', 'max' => 10000],
                'title_3' => $text, 'text_3' => ['type' => 'rich', 'max' => 10000],
            ],
        ];
        $definitions = [];
        foreach ($fields as $type => $schema) {
            $definitions[$type] = [
                'label' => 'plugin::prime-builder.widgets.' . $type,
                'category' => $type === 'container' ? 'structure' : (in_array($type, ['posts', 'current_content', 'form', 'prime_block', 'call_to_action', 'price_table', 'price_list', 'countdown', 'review', 'flip_box', 'animated_heading', 'code', 'testimonial_carousel', 'media_carousel', 'slideshow', 'portfolio', 'search_form', 'navigation', 'breadcrumbs', 'site_title', 'site_logo', 'entry_title', 'entry_excerpt', 'entry_content', 'entry_featured_image', 'entry_info', 'entry_navigation', 'entry_author', 'archive_title', 'archive_list', 'share_buttons', 'table_of_contents'], true) ? 'pro' : 'base'),
                'fields' => $schema,
                'container' => $type === 'container',
            ];
            if (in_array($type, ['original_content', 'original_group'], true)) {
                $definitions[$type]['hidden'] = true;
                $definitions[$type]['read_only'] = true;
            }
            if (in_array($type, ['container', 'button', 'spacer', 'quote', 'alert', 'progress', 'accordion', 'card', 'icon_box', 'image_box', 'counter', 'tabs', 'toggle', 'gallery', 'image_carousel', 'map', 'video', 'audio', 'posts', 'form', 'call_to_action', 'price_table', 'price_list', 'countdown', 'review', 'flip_box', 'animated_heading', 'code', 'testimonial_carousel', 'media_carousel', 'slideshow', 'portfolio', 'search_form', 'navigation', 'breadcrumbs', 'site_title', 'site_logo', 'entry_title', 'entry_excerpt', 'entry_content', 'entry_featured_image', 'entry_info', 'entry_navigation', 'entry_author', 'archive_title', 'archive_list', 'share_buttons', 'table_of_contents'], true)) {
                $definitions[$type]['style'] = '/assets/plugins/prime-builder/css/widgets/' . $type . '.css';
            }
            if ($type === 'tabs') {
                $definitions[$type]['script'] = '/assets/plugins/prime-builder/js/tabs.js';
            } elseif (in_array($type, ['countdown', 'flip_box', 'portfolio', 'share_buttons'], true)) {
                $definitions[$type]['script'] = '/assets/plugins/prime-builder/js/' . $type . '.js';
            } elseif (in_array($type, ['posts', 'current_content', 'theme_zone', 'search_form', 'navigation', 'breadcrumbs', 'site_title', 'site_logo', 'entry_title', 'entry_excerpt', 'entry_featured_image', 'entry_info', 'entry_navigation', 'entry_author'], true)) {
                $definitions[$type]['script'] = '/assets/plugins/prime-builder/js/content.js';
            } elseif ($type === 'map') {
                $definitions[$type]['script'] = '/assets/plugins/prime-builder/js/map.js';
            } elseif (in_array($type, ['image_carousel', 'testimonial_carousel', 'media_carousel', 'slideshow'], true)) {
                $definitions[$type]['script'] = '/assets/plugins/prime-builder/js/image_carousel.js';
            } elseif ($type === 'gallery') {
                $definitions[$type]['script'] = '/assets/plugins/prime-builder/js/gallery.js';
            } elseif ($type === 'video') {
                $definitions[$type]['script'] = '/assets/plugins/prime-builder/js/video.js';
            } elseif ($type === 'form') {
                $definitions[$type]['script'] = '/assets/plugins/prime-builder/js/forms.js';
            }
        }
        $filtered = $this->hooks?->apply('prime_builder.widgets', $definitions) ?? $definitions;
        if (!is_array($filtered)) {
            return $definitions;
        }
        // Only named, schema-bearing definitions are usable. Executable extension callbacks are trusted PHP.
        foreach ($filtered as $type => $definition) {
            if (!is_string($type) || preg_match('/^[a-z][a-z0-9_-]{0,63}$/', $type) !== 1 || !is_array($definition) || !is_array($definition['fields'] ?? null)) {
                unset($filtered[$type]);
            }
        }

        return $filtered;
    }
}
