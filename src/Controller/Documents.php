<?php

declare(strict_types=1);

namespace PrimeBuilder\Controller;

use Prime\Api\ApiError;
use Prime\Auth\Auth;
use Prime\Auth\User;
use Prime\Content\ContentTypeRegistry;
use Prime\Content\Entry;
use Prime\Content\EntryRepository;
use Prime\Content\EntryService;
use Prime\Content\Exception\ContentException;
use Prime\Content\Exception\ContentForbiddenException;
use Prime\Content\Exception\ContentLockedException;
use Prime\Content\Exception\ContentValidationException;
use Prime\Content\TaxonomyRegistry;
use Prime\Editor\BlockRegistry;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\I18n\Translator;
use Prime\Plugins\PluginContext;
use PrimeBuilder\Configuration;
use PrimeBuilder\Document\Converter;
use PrimeBuilder\Document\HtmlContent;
use PrimeBuilder\Document\Layout;
use PrimeBuilder\Document\MediaReferences;
use PrimeBuilder\Document\Validator;
use PrimeBuilder\Styles\Compiler;
use PrimeBuilder\Styles\EditorCss;
use PrimeBuilder\Styles\Kit;
use PrimeBuilder\Styles\Snapshots;
use PrimeBuilder\Theme\GlobalWidgets;
use PrimeBuilder\Theme\Models;
use PrimeBuilder\Theme\Partials;
use PrimeBuilder\Widgets\Assets;
use PrimeBuilder\Widgets\Content;
use PrimeBuilder\Widgets\OriginalContent;
use PrimeBuilder\Widgets\Registry;
use PrimeBuilder\Widgets\Renderer;

final class Documents
{
    public function __construct(private readonly PluginContext $plugin)
    {
    }

    /** @return array<string, array<string, string|int>> */
    private function widgetDefaults(): array
    {
        $copy = static fn (string $key): string => t('plugin::prime-builder.defaults.' . $key);
        return [
            'heading' => ['text' => $copy('heading')],
            'text' => ['text' => $copy('text')],
            'html' => ['markup' => '<p>' . htmlspecialchars($copy('html'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'],
            'button' => ['text' => $copy('button')],
            'quote' => ['text' => $copy('quote')],
            'alert' => ['text' => $copy('alert')],
            'list' => ['items' => $copy('list')],
            'accordion' => ['title' => $copy('accordion_title'), 'text' => $copy('accordion_text')],
            'card' => ['title' => $copy('card_title'), 'text' => $copy('card_text')],
            'icon_box' => ['icon' => 'star', 'title' => $copy('icon_title'), 'text' => $copy('icon_text')],
            'icon' => ['icon' => 'star'],
            'icon_list' => ['icon' => 'check', 'items' => $copy('list')],
            'image_box' => ['title' => $copy('card_title'), 'text' => $copy('card_text')],
            'testimonial' => ['text' => $copy('testimonial_text'), 'author' => $copy('testimonial_author')],
            'testimonial_carousel' => ['label' => t('plugin::prime-builder.widgets.testimonial_carousel'), 'items' => $copy('testimonials_items')],
            'media_carousel' => ['label' => t('plugin::prime-builder.widgets.media_carousel'), 'items' => $copy('media_carousel_items'), 'caption_language' => str_starts_with(strtolower(current_locale()), 'en') ? 'en' : 'fr'],
            'slideshow' => ['label' => t('plugin::prime-builder.widgets.slideshow'), 'items' => $copy('slideshow_items'), 'level' => 2],
            'portfolio' => ['items' => $copy('portfolio_items'), 'columns' => 3, 'level' => 3],
            'review' => ['title' => t('plugin::prime-builder.widgets.review'), 'level' => 2, 'rating' => 5, 'text' => $copy('testimonial_text'), 'author' => $copy('testimonial_author')],
            'flip_box' => ['front_title' => t('plugin::prime-builder.ui.flip_front_default'), 'front_text' => $copy('card_text'), 'back_title' => t('plugin::prime-builder.ui.flip_back_default'), 'back_text' => $copy('card_text'), 'level' => 2],
            'animated_heading' => ['before' => t('plugin::prime-builder.ui.animated_default_before'), 'highlight' => t('plugin::prime-builder.ui.animated_default_highlight'), 'level' => 2, 'effect' => 'underline'],
            'code' => ['label' => t('plugin::prime-builder.ui.code_default_label'), 'language' => 'text', 'source' => $copy('code')],
            'call_to_action' => ['title' => $copy('cta_title'), 'level' => 2, 'text' => $copy('cta_text'), 'button_text' => $copy('button')],
            'price_table' => ['title' => $copy('price_table_title'), 'level' => 2, 'price' => $copy('price_table_price'), 'period' => $copy('price_table_period'), 'features' => $copy('price_table_features'), 'button_text' => $copy('button')],
            'price_list' => ['title' => $copy('price_list_title'), 'level' => 2, 'items' => $copy('price_list_items')],
            'countdown' => ['title' => t('plugin::prime-builder.widgets.countdown'), 'level' => 2, 'finished_text' => t('plugin::prime-builder.ui.countdown_finished')],
            'search_form' => ['search_label' => t('plugin::prime-builder.ui.search_form_default_label'), 'button_text' => t('plugin::prime-builder.ui.search_form_default_button')],
            'navigation' => ['label' => t('plugin::prime-builder.ui.navigation_default_label')],
            'breadcrumbs' => ['label' => t('plugin::prime-builder.ui.breadcrumb_default_label')],
            'site_title' => ['tag' => 'p', 'link_home' => 'show'],
            'site_logo' => ['link_home' => 'show'],
            'entry_title' => ['tag' => 'h2', 'link' => 'hide'],
            'entry_excerpt' => ['length' => 180],
            'entry_featured_image' => ['size' => 'large'],
            'entry_info' => ['date' => 'show', 'author' => 'show', 'categories' => 'show'],
            'entry_navigation' => ['label' => t('plugin::prime-builder.ui.entry_navigation_default_label'), 'previous' => 'show', 'next' => 'show'],
            'entry_author' => ['avatar' => 'show', 'bio' => 'show', 'link' => 'show'],
            'archive_title' => ['tag' => 'h2'],
            'archive_list' => ['layout' => 'cards', 'columns' => 3, 'show_excerpt' => 'show', 'pagination' => 'show'],
            'toggle' => ['title' => $copy('toggle_title'), 'text' => $copy('accordion_text'), 'initial' => 'closed'],
            'star_rating' => ['value' => 4],
            'counter' => ['title' => $copy('counter')],
            'posts' => ['content_type' => 'post', 'order_by' => 'published_at', 'order_direction' => 'desc', 'limit' => 3, 'pagination' => 'hide', 'layout' => 'cards', 'columns' => 3, 'show_excerpt' => 'hide'],
            'form' => ['title' => $copy('form_title'), 'button_text' => $copy('form_button')],
            'prime_block' => ['block_type' => 'paragraph', 'block_data' => (string) json_encode(['text' => $copy('prime_block')], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)],
            'tabs' => ['label' => $copy('tabs_label'), 'title_1' => $copy('tab_1'), 'text_1' => $copy('tab_text_1'), 'title_2' => $copy('tab_2'), 'text_2' => $copy('tab_text_2')],
        ];
    }

    private function entry(int $id, ?User $user): ?Entry
    {
        $entry = $this->plugin->app()->get(EntryRepository::class)->find($id);
        if (!$entry instanceof Entry || !$user instanceof User || !$user->can('prime_builder.edit')) {
            return null;
        }
        $type = $this->plugin->app()->get(ContentTypeRegistry::class)->get($entry->type);
        if ($type === null || !$this->plugin->app()->get(EntryService::class)->canEdit($user, $entry, $type)) {
            return null;
        }
        return $entry;
    }

    private function sourceHash(Entry $entry): string
    {
        return hash('sha256', $entry->content . "\0" . $entry->contentHtml . "\0" . $entry->status . "\0" . ($entry->publishedAt ?? ''));
    }

    public function edit(Request $request, string $id): Response
    {
        $user = $this->plugin->app()->get(Auth::class)->user($request);
        $entry = $this->entry((int) $id, $user);
        if ($entry === null || !$user instanceof User) {
            return Response::text(t('plugin::prime-builder.ui.not_found'), 404);
        }
        return $this->editorView($request, $entry, $user);
    }

    /** Open a theme model in the same visual editor, without creating a public entry. */
    public function editTheme(Request $request, string $id): Response
    {
        $user = $this->plugin->app()->get(Auth::class)->user($request);
        if (!$user instanceof User || !$user->can('prime_builder.theme_builder')) {
            return Response::text('', 403);
        }
        $model = (new Models($this->plugin->settings(), $this->plugin->app()->get(\Prime\Settings\Settings::class)))->find($id);
        if ($model === null || !is_array($model['elements'] ?? null)) {
            return Response::text(t('plugin::prime-builder.ui.not_found'), 404);
        }
        $layout = (new Partials($this->plugin))->normalize($model['elements'], true, contextual: true);
        if ($layout === null) {
            return Response::text(t('plugin::prime-builder.theme.invalid'), 422);
        }
        $document = ['version' => 1, 'blocks' => [['id' => 'pb_layout', 'type' => 'prime-builder/layout', 'data' => $layout]]];
        $entry = Entry::fromRow([
            'id' => 0, 'type' => 'page', 'status' => 'draft', 'title' => $model['name'],
            'content' => json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '',
        ]);
        return $this->editorView($request, $entry, $user, $model);
    }

    /** @param array<string, mixed>|null $themeModel */
    private function editorView(Request $request, Entry $entry, User $user, ?array $themeModel = null): Response
    {
        $type = $this->plugin->app()->get(ContentTypeRegistry::class)->get($entry->type);
        $service = $this->plugin->app()->get(EntryService::class);
        $registry = new Registry($this->plugin->app()->get(\Prime\Hooks\Hooks::class));
        $definitions = [];
        foreach ($registry->all() as $name => $definition) {
            // Never send server callbacks or non-serializable plugin data to the browser.
            $fields = [];
            foreach ($definition['fields'] as $fieldName => $schema) {
                if (!is_string($fieldName) || !is_array($schema) || ($schema['internal'] ?? false) === true) {
                    continue;
                }
                $field = ['type' => is_string($schema['type'] ?? null) ? $schema['type'] : 'text'];
                foreach (['min', 'max'] as $bound) {
                    if (is_int($schema[$bound] ?? null)) {
                        $field[$bound] = $schema[$bound];
                    }
                }
                if (is_string($schema['label'] ?? null)) {
                    $field['label'] = t($schema['label']);
                }
                if (is_array($schema['values'] ?? null)) {
                    $field['values'] = array_values(array_filter($schema['values'], 'is_string'));
                }
                if (is_array($schema['option_labels'] ?? null)) {
                    $labels = [];
                    foreach ($schema['option_labels'] as $value => $label) {
                        if (is_string($value) && is_string($label)) {
                            $labels[$value] = t($label);
                        }
                    }
                    $field['option_labels'] = $labels;
                }
                if ($name === 'posts' && in_array($fieldName, ['content_type', 'taxonomy'], true)) {
                    // The CMS owns content types and taxonomies; expose only public definitions.
                    // The renderer rechecks these definitions when the document is displayed.
                    $field['type'] = 'enum';
                    $field['values'] = [];
                    $field['option_labels'] = [];
                    try {
                        if ($fieldName === 'content_type') {
                            foreach ($this->plugin->app()->get(ContentTypeRegistry::class)->allPublic() as $type) {
                                $field['values'][] = $type->name;
                                $field['option_labels'][$type->name] = $type->label('plural');
                            }
                        } else {
                            foreach ($this->plugin->app()->get(TaxonomyRegistry::class)->all() as $taxonomy) {
                                if ($taxonomy->public) {
                                    $field['values'][] = $taxonomy->name;
                                    $field['option_labels'][$taxonomy->name] = $taxonomy->label('plural');
                                }
                            }
                        }
                    } catch (\Throwable) {
                        // A faulty third-party registry must not prevent opening the editor.
                    }
                }
                if ($name === 'global_widget' && $fieldName === 'global_id') {
                    $field['type'] = 'enum';
                    $field['values'] = [];
                    $field['option_labels'] = [];
                    foreach ((new Models($this->plugin->settings(), $this->plugin->app()->get(\Prime\Settings\Settings::class)))->all() as $model) {
                        if ($model['kind'] === 'global' && $model['enabled'] === true) {
                            $field['values'][] = $model['id'];
                            $field['option_labels'][$model['id']] = $model['name'];
                        }
                    }
                }
                if ($name === 'navigation' && $fieldName === 'menu_id') {
                    // The CMS owns menus. A removed menu remains a valid saved reference
                    // but only currently available menu IDs are offered in the inspector.
                    $field['type'] = 'enum';
                    $field['values'] = [];
                    $field['option_labels'] = [];
                    try {
                        foreach ($this->plugin->app()->get(\Prime\Site\Menu\MenuRepository::class)->all() as $menu) {
                            $menuId = (string) $menu['id'];
                            $field['values'][] = $menuId;
                            $field['option_labels'][$menuId] = $menu['name'];
                        }
                    } catch (\Throwable) {
                        // A missing or broken menu repository must not prevent editing.
                    }
                }
                if ($name === 'theme_zone' && $fieldName === 'zone') {
                    // Use the actual active theme's declared zones, including its parent.
                    $field['type'] = 'enum';
                    $field['values'] = [];
                    $field['option_labels'] = [];
                    try {
                        foreach ($this->plugin->app()->get(\Prime\Themes\ThemeManager::class)->zones() as $slug => $zone) {
                            $field['values'][] = $slug;
                            $field['option_labels'][$slug] = \Prime\Content\LabelTranslator::resolve($zone['label']);
                        }
                    } catch (\Throwable) {
                        // A missing or broken theme must not prevent opening the editor.
                    }
                }
                $fields[$fieldName] = $field;
            }
            $definitions[$name] = [
                'label' => t(is_string($definition['label'] ?? null) ? $definition['label'] : $name),
                'category' => is_string($definition['category'] ?? null) ? $definition['category'] : 'base',
                'style' => Assets::safeUrl($definition['style'] ?? null, 'css'),
                'fields' => $fields,
                'container' => ($definition['container'] ?? false) === true,
                // The contact widget's endpoints currently require a persisted entry.
                'hidden' => ($definition['hidden'] ?? false) === true || ($name === 'html' && !$user->can('prime_builder.unfiltered_html'))
                    || ($themeModel !== null && $name === 'form')
                    || (($themeModel['kind'] ?? null) === 'global' && !in_array($name, GlobalWidgets::ALLOWED, true)),
                'readOnly' => ($definition['read_only'] ?? false) === true || ($name === 'html' && !$user->can('prime_builder.unfiltered_html')),
            ];
        }
        $ui = [];
        foreach (['conversion_failed', 'original_read_only', 'original_group_warning', 'html_read_only', 'markup', 'unsaved_short', 'confirm_title', 'notice_title', 'cancel', 'continue', 'color_picker_text', 'color_picker_background', 'color_picker_border', 'color_reference_text', 'color_reference_background', 'color_reference_border', 'color_custom', 'kit_primary', 'kit_text', 'unit', 'rename_element', 'rename_save', 'rename_cancel', 'collapse_tree', 'expand_tree', 'context_menu', 'element_toolbar', 'copy', 'paste', 'paste_style', 'reset_style', 'copied', 'pasted', 'widgets', 'search', 'navigator', 'content', 'style', 'advanced', 'add', 'remove', 'duplicate', 'move_up', 'move_down', 'empty', 'select', 'save', 'saving', 'saved', 'save_error', 'unsaved', 'back', 'desktop', 'tablet', 'mobile', 'undo', 'redo', 'preview', 'publish', 'draft', 'text', 'url', 'alt', 'width', 'height', 'level', 'layout', 'direction', 'columns', 'author', 'value', 'anchor', 'widget_classes', 'widget_classes_help', 'widget_classes_error', 'padding', 'padding_sides', 'padding_top', 'padding_right', 'padding_bottom', 'padding_left', 'margin', 'margin_sides', 'margin_sides_help', 'margin_top', 'margin_right', 'margin_bottom', 'margin_left', 'gap', 'font_size', 'border_style', 'border_width', 'border_color', 'border_none', 'border_solid', 'border_dashed', 'border_dotted', 'border_double', 'border_help', 'box_shadow', 'shadow_none', 'shadow_soft', 'shadow_medium', 'shadow_strong', 'shadow_help', 'radius', 'min_height', 'color', 'background', 'align', 'left', 'center', 'right', 'flex', 'grid', 'column', 'row', 'structure', 'base', 'confirm_replace', 'replace', 'select_container', 'responsive_help', 'visibility', 'visibility_warning', 'position', 'static', 'relative', 'absolute', 'fixed', 'sticky', 'offset_top', 'offset_right', 'offset_bottom', 'offset_left', 'z_index', 'auto', 'numeric_value', 'position_mode', 'position_warning', 'value_inherited', 'value_all_sides', 'value_defined', 'value_default', 'history', 'history_initial', 'history_change', 'history_current', 'history_help', 'drop', 'drop_before', 'drop_after', 'drop_inside', 'drop_invalid', 'revisions', 'document_status', 'edit_element', 'invalid', 'connection_error', 'autosave_found', 'restore', 'discard', 'library', 'library_intro', 'insert', 'import', 'export', 'section_template', 'page_template', 'save_template', 'template_saved', 'template_error', 'template_delete', 'template_delete_confirm', 'template_deleted', 'confirm_template', 'invalid_import', 'imported', 'import_help', 'favorites', 'favorite_add', 'favorite_remove', 'move_to', 'root', 'commands', 'command_search', 'command_empty', 'close', 'scheduled', 'schedule_date', 'schedule_invalid', 'pro', 'form_fields', 'form_fields_help', 'form_fields_customize', 'form_fields_legacy', 'form_fields_invalid', 'form_fields_reset', 'form_field', 'form_field_name', 'form_field_email', 'form_field_message', 'form_field_label', 'form_field_type', 'form_field_step', 'form_field_options', 'form_field_value', 'form_field_required', 'form_field_up', 'form_field_down', 'form_field_remove', 'form_field_add', 'form_field_default', 'form_option_default', 'form_type_text', 'form_type_email', 'form_type_phone', 'form_type_number', 'form_type_textarea', 'form_type_select', 'form_type_checkbox', 'form_type_radio', 'form_type_date', 'form_type_acceptance', 'form_type_hidden', 'form_type_file', 'form_file_help', 'items', 'list_type', 'unordered', 'ordered', 'title', 'image_url', 'button_text', 'icon', 'star', 'check', 'heart', 'spark', 'prefix', 'suffix', 'label', 'title_1', 'text_1', 'title_2', 'text_2', 'title_3', 'text_3', 'choose_media', 'choose_images', 'media_row_label', 'media_row_number', 'media_row_empty', 'media_row_overflow', 'media_row_choose', 'media_row_help', 'media_row_help_portrait', 'media_row_updated', 'media_row_error', 'media_row_changed', 'media_list_added', 'media_list_error', 'media_carousel_add_error', 'media_grid', 'media_error', 'accessible_label', 'job_title', 'initial', 'closed', 'open', 'facebook', 'instagram', 'linkedin', 'youtube', 'mastodon', 'images', 'video_title', 'captions', 'caption_language', 'transcript', 'fr', 'en', 'default_option', 'layout_mode', 'flow', 'grid_columns', 'wrap', 'nowrap', 'justify', 'items_align', 'start', 'end', 'space-between', 'stretch', 'limit', 'show_excerpt', 'show', 'hide', 'source', 'excerpt', 'published', 'block_type', 'block_data', 'block_anchor', 'paragraph', 'heading', 'list', 'quote', 'separator', 'code', 'table', 'callout'] as $key) {
            $ui[$key] = t('plugin::prime-builder.ui.' . $key);
        }
        foreach (['rich_visual', 'rich_source', 'rich_toolbar', 'rich_bold', 'rich_italic', 'rich_unordered', 'rich_ordered', 'rich_block', 'rich_paragraph', 'rich_h2', 'rich_h3', 'rich_h4', 'rich_quote', 'rich_pre', 'rich_link', 'rich_link_url', 'rich_link_apply', 'rich_link_remove', 'rich_link_invalid', 'rich_link_select', 'rich_help', 'rich_unsupported', 'rich_limit', 'dynamic_title', 'dynamic_choose', 'dynamic_insert', 'dynamic_help', 'conditions_title', 'conditions_help', 'conditions_auth', 'conditions_role', 'conditions_device', 'conditions_start', 'conditions_end', 'conditions_any', 'conditions_guest', 'conditions_authenticated', 'conditions_invalid'] as $key) {
            $ui[$key] = t('plugin::prime-builder.ui.' . $key);
        }
        $tags = [];
        foreach (\PrimeBuilder\Dynamic\Tags::NAMES as $name) {
            $tags[] = ['value' => '{{' . $name . '}}', 'label' => t('plugin::prime-builder.ui.dynamic_tag_' . str_replace('.', '_', $name))];
        }
        $publicFields = \PrimeBuilder\Dynamic\PublicFields::parse(Configuration::get($this->plugin->settings(), 'public_fields', '')) ?? [];
        foreach ($publicFields as $field) {
            [, $name] = explode('.', $field, 2);
            $tags[] = ['value' => '{{entry.field.' . $name . '}}', 'label' => t('plugin::prime-builder.ui.dynamic_custom') . ' ' . $field];
        }
        $roles = [];
        try {
            foreach ($this->plugin->app()->get(\Prime\Auth\RoleRepository::class)->all() as $role) {
                $roles[] = ['value' => $role->slug, 'label' => $role->label()];
            }
        } catch (\Throwable) {
            // The editor still works when a third-party role provider fails.
        }
        $document = $entry->decodedContent();
        $originals = OriginalContent::fromAppKey($this->plugin->app()->config()->get('app.key'));
        $converted = $themeModel !== null ? $document : (new Converter($this->plugin->app()->get(BlockRegistry::class), $this->plugin->app()->get(\Prime\Security\HtmlSanitizer::class), $registry, $originals))->convert($entry);
        $themeId = $themeModel !== null && is_string($themeModel['id'] ?? null) ? $themeModel['id'] : null;
        $previewUrl = $themeId !== null ? '/admin/prime-builder/theme/' . $themeId . '/preview' : $entry->previewUrl();
        $editUrl = $themeId !== null ? '/admin/prime-builder/theme/' . $themeId : $entry->editUrl();
        $config = [
            'entryId' => $entry->id,
            'documentKey' => $themeId !== null ? 'theme:' . $themeId : (string) $entry->id,
            'themeKind' => $themeId !== null ? ($themeModel['kind'] ?? null) : null,
            'apiBase' => $themeId !== null ? '/api/prime-builder/v1/theme-models/' . $themeId : '/api/prime-builder/v1/documents/' . $entry->id,
            'userId' => $user->id,
            'sourceHash' => $themeModel !== null && is_array($themeModel['elements'] ?? null) ? Models::sourceHash($themeModel['elements']) : $this->sourceHash($entry),
            'document' => $converted ?? $document,
            'conversionFailed' => $converted === null,
            'converted' => $themeModel === null && $converted !== null && ($document['blocks'] !== [] || $entry->contentHtml !== '') && $converted !== $document,
            'previewUrl' => $previewUrl,
            'publicCssUrl' => $this->plugin->url('css/public.css'),
            'kitCssUrl' => '/prime-builder/kit/' . substr(hash('sha256', Kit::fromSettings($this->plugin->settings())), 0, 16) . '.css',
            'kitColors' => ['primary' => Configuration::get($this->plugin->settings(), 'kit_primary', '#c9441f'), 'text' => Configuration::get($this->plugin->settings(), 'kit_text', '#1a1d29')],
            'mediaCssUrl' => asset('admin/css/admin.css'),
            'mediaMessages' => array_filter($this->plugin->app()->get(Translator::class)->messages(), static fn (string $key): bool => str_starts_with($key, 'admin.js.media.'), ARRAY_FILTER_USE_KEY),
            'tabletBreakpoint' => max(600, min(2000, (int) Configuration::get($this->plugin->settings(), 'breakpoint_tablet', 1024))),
            'mobileBreakpoint' => max(320, min(1600, (int) Configuration::get($this->plugin->settings(), 'breakpoint_mobile', 767))),
            'editUrl' => $editUrl,
            'revisionsUrl' => '/admin/content/' . rawurlencode($entry->type) . '/' . $entry->id . '/revisions',
            'status' => $entry->status,
            'publishedAt' => $entry->publishedAt,
            'canPublish' => $themeModel === null && $type !== null && $service->canPublish($user, $type),
            'widgets' => $definitions,
            'dynamicTags' => $tags,
            'roles' => $roles,
            'widgetDefaults' => $this->widgetDefaults(),
            'canTemplates' => $user->can('prime_builder.edit') && $user->can('prime_builder.templates'),
            'canHtml' => $user->can('prime_builder.unfiltered_html'),
            // Editors may insert shared templates; only managers may save, import or delete them.
            'library' => (new Templates($this->plugin))->all(),
            'ui' => $ui,
        ];
        $html = $this->plugin->views()->render('prime-builder::admin/builder', [
            'entry' => $entry,
            'previewUrl' => $previewUrl,
            'editUrl' => $editUrl,
            'themeModel' => $themeModel !== null,
            'canTemplates' => $config['canTemplates'],
            'conversionFailed' => $config['conversionFailed'],
            'configJson' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
            'cssUrl' => $this->plugin->url('css/editor.css'),
            'editorCss' => EditorCss::compile(Configuration::get($this->plugin->settings(), 'editor_css', '')),
            'jsUrl' => $this->plugin->url('js/editor.js'),
        ]);
        return Response::html($html)->withHeader('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, string $id): Response
    {
        $user = $request->attribute('api.user');
        if (!$user instanceof User || !$user->can('prime_builder.edit')) {
            return ApiError::response('forbidden', 403, $request);
        }
        $entry = $this->entry((int) $id, $user);
        if ($entry === null) {
            return ApiError::response('not_found', 404, $request);
        }
        $body = $request->attribute('api.body');
        if (!is_array($body) || !is_array($body['content'] ?? null) || strlen($request->rawBody()) > Validator::MAX_BYTES + 8192) {
            return ApiError::response('validation_failed', 422, $request);
        }
        if (array_key_exists('source_hash', $body) && (!is_string($body['source_hash']) || !hash_equals($this->sourceHash($entry), $body['source_hash']))) {
            return ApiError::response('conflict', 409, $request);
        }
        // Require the builder's canonical single-block document; never accept arbitrary privileged core blocks.
        $blocks = $body['content']['blocks'] ?? null;
        if (($body['content']['version'] ?? null) !== 1 || !is_array($blocks) || count($blocks) !== 1 || !is_array($blocks[0] ?? null) || ($blocks[0]['type'] ?? null) !== 'prime-builder/layout') {
            return ApiError::response('validation_failed', 422, $request);
        }
        $data = $blocks[0]['data'] ?? null;
        if (!is_array($data)) {
            return ApiError::response('validation_failed', 422, $request);
        }
        $registry = new Registry($this->plugin->app()->get(\Prime\Hooks\Hooks::class));
        $sanitizer = $this->plugin->app()->get(\Prime\Security\HtmlSanitizer::class);
        $coreBlocks = $this->plugin->app()->get(BlockRegistry::class);
        $originals = OriginalContent::fromAppKey($this->plugin->app()->config()->get('app.key'));
        try {
            $editable = (new Converter($coreBlocks, $sanitizer, $registry, $originals))->convert($entry) !== null;
        } catch (\Throwable) {
            $editable = false;
        }
        if (!$editable) {
            // Enforce the editor's fail-closed state for stale/custom clients as
            // well: a valid empty replacement must not silently erase bad data.
            $message = t('plugin::prime-builder.ui.conversion_failed', [], ApiError::locale($request));
            return ApiError::response('validation_failed', 422, $request, ['fields' => ['content' => [$message]]]);
        }
        // The CMS block validator has no entry context; enforce entry binding here too.
        $validator = new Validator($registry, $sanitizer, $coreBlocks, $originals, $entry->id);
        $validated = $validator->validate($data);
        if ($validated['errors'] !== []) {
            return ApiError::response('validation_failed', 422, $request, ['fields' => ['content' => $validated['errors']]]);
        }
        $layout = $validated['data'];
        if (HtmlContent::contains($layout)) {
            $htmlContent = HtmlContent::fromAppKey($this->plugin->app()->config()->get('app.key'));
            if ($htmlContent === null) {
                return ApiError::response('validation_failed', 422, $request);
            }
            if (!$htmlContent->allows($layout, $entry->decodedContent(), $entry->id, $user->can('prime_builder.unfiltered_html'))) {
                return ApiError::response('forbidden', 403, $request);
            }
            $layout = $htmlContent->seal($layout, $entry->id);
        }
        $document = $body['content'];
        $document['blocks'][0]['data'] = $layout;
        $input = ['content' => $document];
        if (is_string($body['status'] ?? null)) {
            $input['status'] = $body['status'];
        }
        if (is_string($body['published_at'] ?? null)) {
            $input['published_at'] = $body['published_at'];
        }
        try {
            $saved = $this->plugin->app()->get(EntryService::class)->update($entry->id, $input, $user);
            return Response::json(['data' => ['id' => $saved->id, 'status' => $saved->status, 'published_at' => $saved->publishedAt, 'content' => $saved->decodedContent(), 'updated_at' => $saved->updatedAt, 'source_hash' => $this->sourceHash($saved)]]);
        } catch (ContentValidationException $exception) {
            return ApiError::response('validation_failed', 422, $request, ['fields' => $exception->errors()]);
        } catch (ContentLockedException) {
            return ApiError::response('conflict', 409, $request);
        } catch (ContentForbiddenException) {
            return ApiError::response('forbidden', 403, $request);
        } catch (ContentException) {
            return ApiError::response('validation_failed', 422, $request);
        }
    }

    public function render(Request $request, string $id): Response
    {
        $user = $request->attribute('api.user');
        if (!$user instanceof User || !$user->can('prime_builder.edit')) {
            return ApiError::response('forbidden', 403, $request);
        }
        $entry = $this->entry((int) $id, $user);
        if ($entry === null) {
            return ApiError::response('not_found', 404, $request);
        }
        $body = $request->attribute('api.body');
        if (!is_array($body) || !is_array($body['data'] ?? null) || strlen($request->rawBody()) > Validator::MAX_BYTES + 8192) {
            return ApiError::response('validation_failed', 422, $request);
        }
        $originals = OriginalContent::fromAppKey($this->plugin->app()->config()->get('app.key'));
        $validator = new Validator(new Registry($this->plugin->app()->get(\Prime\Hooks\Hooks::class)), $this->plugin->app()->get(\Prime\Security\HtmlSanitizer::class), $this->plugin->app()->get(BlockRegistry::class), $originals, $entry->id);
        $result = $validator->validate($body['data']);
        if ($result['errors'] !== []) {
            return ApiError::response('validation_failed', 422, $request, ['fields' => ['content' => $result['errors']]]);
        }
        $htmlContent = HtmlContent::fromAppKey($this->plugin->app()->config()->get('app.key'));
        if (HtmlContent::contains($result['data'])) {
            if ($htmlContent === null) {
                return ApiError::response('validation_failed', 422, $request);
            }
            if (!$htmlContent->allows($result['data'], $entry->decodedContent(), $entry->id, $user->can('prime_builder.unfiltered_html'))) {
                return ApiError::response('forbidden', 403, $request);
            }
        }
        $renderer = new Renderer(new Registry($this->plugin->app()->get(\Prime\Hooks\Hooks::class)), $this->plugin->app()->get(\Prime\Security\HtmlSanitizer::class), $this->plugin->logger(), $this->plugin->app()->get(Content::class), $entry, true, $this->plugin->app()->get(BlockRegistry::class), $originals, $htmlContent, true, mediaReferences: $this->plugin->app()->get(MediaReferences::class), dynamicPlugin: $this->plugin, request: $request);
        $expanded = $this->plugin->app()->get(GlobalWidgets::class)->expand($result['data']);
        if (array_key_exists('target', $body)) {
            $target = $body['target'];
            $elements = is_array($expanded['elements'] ?? null) ? $expanded['elements'] : [];
            // Targets must belong to the editable document, not generated children.
            $original = is_array($result['data']['elements'] ?? null) ? $result['data']['elements'] : [];
            $element = is_string($target) && $this->previewElement($original, $target) !== null ? $this->previewElement($elements, $target) : null;
            if ($element === null) {
                return ApiError::response('validation_failed', 422, $request);
            }
            return Response::json(['data' => ['target' => $target, 'html' => $renderer->render(['version' => 1, 'elements' => [$element]], $expanded), 'preview' => $expanded]]);
        }
        return Response::json(['data' => ['html' => $renderer->render($expanded), 'layout' => $result['data'], 'preview' => $expanded]]);
    }

    /** Import uses the same entry-bound validation as preview, with an additional capability. */
    public function import(Request $request, string $id): Response
    {
        $user = $request->attribute('api.user');
        if (!$user instanceof User || !$user->can('prime_builder.templates')) {
            return ApiError::response('forbidden', 403, $request);
        }
        $body = $request->attribute('api.body');
        if (!$user->can('prime_builder.unfiltered_html') && is_array($body) && is_array($body['data'] ?? null) && HtmlContent::contains($body['data'])) {
            return ApiError::response('forbidden', 403, $request);
        }
        return $this->render($request, $id);
    }

    /**
     * Find a normalized subtree only after validating the complete document.
     *
     * @param array<mixed> $elements
     * @return array<mixed>|null
     */
    private function previewElement(array $elements, string $target): ?array
    {
        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }
            if (($element['id'] ?? null) === $target) {
                return $element;
            }
            if (is_array($element['children'] ?? null)) {
                $found = $this->previewElement($element['children'], $target);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    public function live(Request $request, string $id): Response
    {
        $entry = $this->plugin->app()->get(EntryRepository::class)->find((int) $id);
        if (!$entry instanceof Entry) {
            return ApiError::response('not_found', 404, $request);
        }
        $content = $this->plugin->app()->get(Content::class);
        // An individually published page may still be unreachable because a
        // parent is private, missing or cyclic. Treat it like a private entry.
        $public = $content->publiclyAccessibleEntry($entry);
        $apiUser = $request->attribute('api.user');
        $user = $apiUser instanceof User ? $apiUser : $this->plugin->app()->get(Auth::class)->user($request);
        if (!$public && $this->entry($entry->id, $user) === null) {
            return ApiError::response('not_found', 404, $request);
        }
        $data = Layout::fromDocument($entry->decodedContent());
        if ($data === null) {
            return ApiError::response('not_found', 404, $request);
        }
        $registry = new Registry($this->plugin->app()->get(\Prime\Hooks\Hooks::class));
        $sanitizer = $this->plugin->app()->get(\Prime\Security\HtmlSanitizer::class);
        $originals = OriginalContent::fromAppKey($this->plugin->app()->config()->get('app.key'));
        $result = (new Validator($registry, $sanitizer, $this->plugin->app()->get(BlockRegistry::class), $originals, $entry->id))->validate($data);
        if ($result['errors'] !== []) {
            return ApiError::response('not_found', 404, $request);
        }
        $renderer = new Renderer($registry, $sanitizer, $this->plugin->logger(), $content, $entry, true, $this->plugin->app()->get(BlockRegistry::class), $originals, trustedPreview: !$public, dynamicPlugin: $this->plugin, request: $request);
        $query = $request->query();
        return Response::json(['data' => ['widgets' => $renderer->liveWidgets($result['data'], is_array($query) ? $query : [])]])
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }

    public function kitStylesheet(Request $request, string $file): Response
    {
        if (preg_match('/^[a-f0-9]{16}\.css$/D', $file) !== 1) {
            return Response::text('', 404);
        }
        $snapshots = new Snapshots($this->plugin->app()->storagePath('prime-builder/styles'));
        $css = $snapshots->read('kit-' . $file);
        $persisted = $css !== null;
        if ($css === null) {
            $css = Kit::fromSettings($this->plugin->settings());
            if (!hash_equals(substr(hash('sha256', $css), 0, 16) . '.css', $file)) {
                return Response::text('', 404);
            }
            $persisted = $snapshots->remember('kit-' . $file, $css);
        }
        return new Response($css, 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => $persisted ? 'public, max-age=31536000, immutable' : 'no-store',
        ]);
    }

    public function stylesheet(Request $request, string $file): Response
    {
        if (preg_match('/^([1-9][0-9]*)-([a-f0-9]{16})\.css$/', $file, $matches) !== 1) {
            return Response::text('', 404);
        }
        $entry = $this->plugin->app()->get(EntryRepository::class)->find((int) $matches[1]);
        if (!$entry instanceof Entry) {
            return Response::text('', 404);
        }
        $type = $this->plugin->app()->get(ContentTypeRegistry::class)->get($entry->type);
        $public = $entry->status === Entry::STATUS_PUBLISHED && $type !== null && $type->public && ($entry->publishedAt === null || $entry->publishedAt <= gmdate('Y-m-d H:i:s'));
        $user = $this->plugin->app()->get(Auth::class)->user($request);
        if (!$public && $this->entry($entry->id, $user) === null) {
            return Response::text('', 404);
        }
        $snapshots = new Snapshots($this->plugin->app()->storagePath('prime-builder/styles'));
        $css = $public ? $snapshots->read($file) : null;
        $persisted = $css !== null;
        if ($css === null) {
            $data = Layout::fromDocument($entry->decodedContent());
            if ($data === null) {
                return Response::text('', 404);
            }
            $data = $this->plugin->app()->get(GlobalWidgets::class)->expand($data);
            $css = (new Compiler((int) Configuration::get($this->plugin->settings(), 'breakpoint_tablet', 1024), (int) Configuration::get($this->plugin->settings(), 'breakpoint_mobile', 767)))->compile($data);
            if (!hash_equals(substr(hash('sha256', $css), 0, 16), $matches[2])) {
                return Response::text('', 404);
            }
            $persisted = $public && $snapshots->remember($file, $css);
        }
        return new Response($css, 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => $public && $persisted ? 'public, max-age=31536000, immutable' : 'private, no-store',
        ]);
    }
}
