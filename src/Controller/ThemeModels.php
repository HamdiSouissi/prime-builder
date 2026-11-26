<?php

declare(strict_types=1);

namespace PrimeBuilder\Controller;

use Prime\Admin\AdminContext;
use Prime\Api\ApiError;
use Prime\Auth\Auth;
use Prime\Auth\User;
use Prime\Content\ContentType;
use Prime\Content\ContentTypeRegistry;
use Prime\Content\Entry;
use Prime\Content\EntryRepository;
use Prime\Content\EntryService;
use Prime\Content\TaxonomyRegistry;
use Prime\Content\TermRepository;
use Prime\Editor\BlockRegistry;
use Prime\Hooks\Hooks;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Plugins\PluginContext;
use Prime\Security\HtmlSanitizer;
use Prime\Settings\Settings;
use PrimeBuilder\Document\HtmlContent;
use PrimeBuilder\Document\MediaReferences;
use PrimeBuilder\Document\SavedTemplates;
use PrimeBuilder\Document\Validator;
use PrimeBuilder\Popup\Options as PopupOptions;
use PrimeBuilder\Theme\Conditions;
use PrimeBuilder\Theme\Models;
use PrimeBuilder\Theme\Partials;
use PrimeBuilder\Widgets\Content;
use PrimeBuilder\Widgets\OriginalContent;
use PrimeBuilder\Widgets\Registry;
use PrimeBuilder\Widgets\Renderer;

/** Theme metadata and independently editable visual layouts. */
final class ThemeModels
{
    private readonly Models $models;
    private readonly Partials $partials;

    public function __construct(private readonly PluginContext $plugin)
    {
        $this->models = new Models($plugin->settings(), $plugin->app()->get(Settings::class));
        $this->partials = new Partials($plugin);
    }

    public function invalidate(): void
    {
        $this->models->invalidate();
    }

    public function create(Request $request): Response
    {
        $user = $this->adminUser($request);
        if ($user === null) {
            return Response::text('', 403);
        }
        $name = $this->cleanName($request->body('name'));
        $kind = $request->body('kind');
        $source = $request->body('source', '');
        if ($name === null || !is_string($kind) || !in_array($kind, Models::KINDS, true) || !is_string($source)) {
            return Response::text(t('plugin::prime-builder.theme.invalid'), 422);
        }
        $elements = [];
        if ($source !== '') {
            $templates = new SavedTemplates($this->plugin->settings(), $this->plugin->app()->get(Settings::class));
            $selected = null;
            foreach ($templates->all() as $template) {
                if ($template['id'] === $source) {
                    $selected = $template;
                    break;
                }
            }
            $layout = $selected !== null ? $this->partials->normalize($selected['elements'], contextual: true) : null;
            if ($layout === null) {
                return Response::text(t('plugin::prime-builder.theme.invalid'), 422);
            }
            if (HtmlContent::contains($layout) && !$user->can('prime_builder.unfiltered_html')) {
                return Response::text(t('plugin::prime-builder.theme.needs_html'), 403);
            }
            $elements = $layout['elements'];
        }
        $model = $this->models->create($name, $kind, $elements);
        if ($model === null) {
            return Response::text(t('plugin::prime-builder.theme.invalid'), 422);
        }
        return Response::redirect('/admin/prime-builder/theme/' . $model['id']);
    }

    public function show(Request $request, string $id): Response
    {
        $user = $this->adminUser($request);
        if ($user === null) {
            return Response::text('', 403);
        }
        $model = $this->models->find($id);
        if ($model === null) {
            return Response::text('', 404);
        }
        return $this->metadataForm($request, $user, $model);
    }

    public function save(Request $request, string $id): Response
    {
        $user = $this->adminUser($request);
        if ($user === null) {
            return Response::text('', 403);
        }
        $model = $this->models->find($id);
        if ($model === null) {
            return Response::text('', 404);
        }
        $name = $this->cleanName($request->body('name'));
        $priority = $request->body('priority');
        $enabled = $request->body('enabled');
        $include = $this->submittedRules($request, 'include');
        $exclude = $this->submittedRules($request, 'exclude');
        $popup = null;
        if ($model['kind'] === 'popup') {
            $popup = PopupOptions::fromForm([
                'trigger' => $request->body('popup_trigger'), 'delay' => $request->body('popup_delay'),
                'scroll' => $request->body('popup_scroll'), 'idle' => $request->body('popup_idle'),
                'frequency' => $request->body('popup_frequency'), 'days' => $request->body('popup_days'),
            ]);
        }
        $priorityValid = is_string($priority) && preg_match('/^-?(0|[1-9][0-9]{0,3})$/D', $priority) === 1 && (int) $priority >= -1000 && (int) $priority <= 1000;
        if ($name === null || !$priorityValid || !in_array($enabled, [null, '1'], true) || $include === null || $include === [] || $exclude === null
            || ($model['kind'] === 'popup' && $popup === null)
            || !$this->referencesValid($include, $user) || !$this->referencesValid($exclude, $user)) {
            return $this->metadataForm($request, $user, $model, 'invalid', 422);
        }
        // Revoking HTML permission must not prevent a theme manager from
        // disabling an already active model. Enabling or retargeting an active
        // model still requires the dedicated HTML capability.
        if ($enabled === '1' && HtmlContent::contains(['elements' => $model['elements']]) && !$user->can('prime_builder.unfiltered_html')) {
            return $this->metadataForm($request, $user, $model, 'needs_html', 403);
        }
        $model['name'] = $name;
        $model['priority'] = (int) $priority;
        $model['enabled'] = $enabled === '1';
        $model['include'] = $include;
        $model['exclude'] = $exclude;
        if ($popup !== null) {
            $model['popup'] = $popup;
        }
        if (!$this->models->save($model)) {
            return $this->metadataForm($request, $user, $model, 'invalid', 422);
        }
        $this->plugin->app()->get(AdminContext::class)->flashToast(t('plugin::prime-builder.theme.saved'), 'success');
        return Response::redirect('/admin/prime-builder/theme/' . $id);
    }

    public function delete(Request $request, string $id): Response
    {
        if ($this->adminUser($request) === null) {
            return Response::text('', 403);
        }
        if (!$this->models->delete($id)) {
            return Response::text('', 404);
        }
        return Response::redirect('/admin/prime-builder/theme');
    }

    /** A private canvas in the active theme; editable markup comes from the render API. */
    public function preview(Request $request, string $id): Response
    {
        if ($this->adminUser($request) === null) {
            return Response::text('', 403);
        }
        $model = $this->models->find($id);
        if ($model === null) {
            return Response::text('', 404);
        }
        $page = new \Prime\Site\PageContext(
            type: \Prime\Site\PageContext::TYPE_PLUGIN,
            path: $request->path(),
            isPreview: true,
            isCustomizerPreview: true,
            extra: ['title' => $model['name'], 'html' => '<div data-pb-editor-content></div>', 'pb_theme_preview' => $model['kind']],
        );
        try {
            $html = $this->plugin->app()->get(\Prime\Site\SiteRenderer::class)->render($page);
        } catch (\Throwable $exception) {
            // A broken theme must not prevent edits. The editor uses the neutral
            // canvas and keeps its existing warning for unrecognizable locations.
            $this->plugin->logger()->error('Prime Builder theme preview failed', ['message' => $exception->getMessage()]);
            $html = '<!doctype html><html lang="' . htmlspecialchars(current_locale(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head><body><main data-pb-editor-content></main></body></html>';
        }
        return Response::html($html)->withHeader('Cache-Control', 'no-store, private')->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    /** Search titles only and never reveal an entry that the caller cannot preview. */
    public function search(Request $request): Response
    {
        $user = $this->adminUser($request);
        if ($user === null) {
            return Response::text('', 403);
        }
        $query = $request->query('q', '');
        if (!is_string($query) || preg_match('//u', $query) !== 1 || mb_strlen(trim($query), 'UTF-8') < 2 || mb_strlen($query, 'UTF-8') > 100) {
            return Response::json(['items' => []])->withHeader('Cache-Control', 'no-store, private');
        }
        $types = $this->plugin->app()->get(ContentTypeRegistry::class)->allPublic();
        if ($types === []) {
            return Response::json(['items' => []])->withHeader('Cache-Control', 'no-store, private');
        }
        $rows = $this->plugin->db()->table('entries')->whereIn('type', array_keys($types))
            ->where('status', '!=', Entry::STATUS_TRASH)->where('title', 'LIKE', '%' . trim($query) . '%')
            ->orderBy('title', 'ASC')->limit(50)->get();
        $items = [];
        foreach ($rows as $row) {
            $entry = Entry::fromRow($row);
            $type = $types[$entry->type] ?? null;
            if ($type !== null && mb_stripos($entry->title, trim($query), 0, 'UTF-8') !== false && $this->canReference($user, $entry, $type)) {
                $items[] = ['id' => $entry->id, 'title' => $entry->title, 'type' => $type->label('singular')];
                if (count($items) === 20) {
                    break;
                }
            }
        }
        return Response::json(['items' => $items])->withHeader('Cache-Control', 'no-store, private');
    }

    public function updateLayout(Request $request, string $id): Response
    {
        $user = $this->apiUser($request);
        if ($user === null) {
            return ApiError::response('forbidden', 403, $request);
        }
        $model = $this->models->find($id);
        if ($model === null || !is_array($model['elements'] ?? null)) {
            return ApiError::response('not_found', 404, $request);
        }
        $body = $request->attribute('api.body');
        if (!is_array($body) || strlen($request->rawBody()) > Validator::MAX_BYTES + 8192
            || !is_array($body['content'] ?? null) || ($body['content']['version'] ?? null) !== 1
            || !is_array($body['content']['blocks'] ?? null) || count($body['content']['blocks']) !== 1
            || !is_array($body['content']['blocks'][0] ?? null) || ($body['content']['blocks'][0]['type'] ?? null) !== 'prime-builder/layout') {
            return ApiError::response('validation_failed', 422, $request);
        }
        if (!is_string($body['source_hash'] ?? null) || !hash_equals(Models::sourceHash($model['elements']), $body['source_hash'])) {
            return ApiError::response('conflict', 409, $request);
        }
        $layout = $this->validatedLayout($body['content']['blocks'][0]['data'] ?? null);
        if ($layout === null) {
            return ApiError::response('validation_failed', 422, $request);
        }
        if (HtmlContent::contains($layout) && !$user->can('prime_builder.unfiltered_html')) {
            return ApiError::response('forbidden', 403, $request);
        }
        $model['elements'] = $layout['elements'];
        if (!$this->models->save($model)) {
            return ApiError::response('validation_failed', 422, $request);
        }
        $document = ['version' => 1, 'blocks' => [['id' => 'pb_layout', 'type' => 'prime-builder/layout', 'data' => $layout]]];
        return Response::json(['data' => [
            'id' => $id, 'status' => 'draft', 'published_at' => null, 'content' => $document,
            'source_hash' => Models::sourceHash($layout['elements']),
        ]])->withHeader('Cache-Control', 'no-store, private');
    }

    public function render(Request $request, string $id): Response
    {
        $user = $this->apiUser($request);
        if ($user === null) {
            return ApiError::response('forbidden', 403, $request);
        }
        if ($this->models->find($id) === null) {
            return ApiError::response('not_found', 404, $request);
        }
        $body = $request->attribute('api.body');
        if (!is_array($body) || strlen($request->rawBody()) > Validator::MAX_BYTES + 8192) {
            return ApiError::response('validation_failed', 422, $request);
        }
        $layout = $this->validatedLayout($body['data'] ?? null);
        if ($layout === null) {
            return ApiError::response('validation_failed', 422, $request);
        }
        if (HtmlContent::contains($layout) && !$user->can('prime_builder.unfiltered_html')) {
            return ApiError::response('forbidden', 403, $request);
        }
        $renderer = new Renderer(
            new Registry($this->plugin->app()->get(Hooks::class)),
            $this->plugin->app()->get(HtmlSanitizer::class),
            $this->plugin->logger(),
            $this->plugin->app()->get(Content::class),
            null,
            true,
            $this->plugin->app()->get(BlockRegistry::class),
            OriginalContent::fromAppKey($this->plugin->app()->config()->get('app.key')),
            HtmlContent::fromAppKey($this->plugin->app()->config()->get('app.key')),
            true,
            mediaReferences: $this->plugin->app()->get(MediaReferences::class),
        );
        if (array_key_exists('target', $body)) {
            $target = $body['target'];
            $element = is_string($target) ? $this->findElement($layout['elements'], $target) : null;
            if ($element === null) {
                return ApiError::response('validation_failed', 422, $request);
            }
            return Response::json(['data' => ['target' => $target, 'html' => $renderer->render(['version' => 1, 'elements' => [$element]], $layout)]]);
        }
        return Response::json(['data' => ['html' => $renderer->render($layout), 'layout' => $layout]]);
    }

    public function import(Request $request, string $id): Response
    {
        $user = $this->apiUser($request);
        if ($user === null || !$user->can('prime_builder.edit') || !$user->can('prime_builder.templates')) {
            return ApiError::response('forbidden', 403, $request);
        }
        return $this->render($request, $id);
    }

    private function adminUser(Request $request): ?User
    {
        $user = $this->plugin->app()->get(Auth::class)->user($request);
        return $user instanceof User && $user->can('prime_builder.theme_builder') ? $user : null;
    }

    private function apiUser(Request $request): ?User
    {
        $user = $request->attribute('api.user');
        return $user instanceof User && $user->can('prime_builder.theme_builder') ? $user : null;
    }

    private function cleanName(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $name = trim(strip_tags($raw));
        return $name !== '' && strlen($name) <= 320 && preg_match('//u', $name) === 1
            && mb_strlen($name, 'UTF-8') <= 100 && preg_match('/[\x00-\x1F\x7F]/', $name) !== 1 ? $name : null;
    }

    /** @return list<array{type: string, value: string}>|null */
    private function submittedRules(Request $request, string $prefix): ?array
    {
        $types = $request->body($prefix . '_type', []);
        $values = $request->body($prefix . '_value', []);
        if (!is_array($types) || !array_is_list($types) || !is_array($values) || !array_is_list($values) || count($types) !== count($values) || count($types) > Conditions::MAX_RULES) {
            return null;
        }
        $rules = [];
        foreach ($types as $index => $type) {
            $rules[] = ['type' => $type, 'value' => $values[$index]];
        }
        return Conditions::normalize($rules);
    }

    /** @param list<array{type: string, value: string}> $rules */
    private function referencesValid(array $rules, User $user): bool
    {
        $types = $this->plugin->app()->get(ContentTypeRegistry::class);
        $taxonomies = $this->plugin->app()->get(TaxonomyRegistry::class);
        foreach ($rules as $rule) {
            $value = $rule['value'];
            if ($rule['type'] === 'content_type' && $types->get($value)?->public !== true) {
                return false;
            }
            if ($rule['type'] === 'taxonomy' && $taxonomies->get($value)?->public !== true) {
                return false;
            }
            if ($rule['type'] === 'term') {
                [$taxonomy, $id] = explode(':', $value, 2);
                $term = $this->plugin->app()->get(TermRepository::class)->find((int) $id);
                if ($taxonomies->get($taxonomy)?->public !== true || $term === null || $term->taxonomy !== $taxonomy) {
                    return false;
                }
            }
            if ($rule['type'] === 'entry') {
                $entry = $this->plugin->app()->get(EntryRepository::class)->find((int) $value, false);
                $type = $entry !== null ? $types->get($entry->type) : null;
                if ($entry === null || $type === null || !$this->canReference($user, $entry, $type)) {
                    return false;
                }
            }
        }
        return true;
    }

    private function canReference(User $user, Entry $entry, ContentType $type): bool
    {
        if (!$type->public || $entry->status === Entry::STATUS_TRASH || !$this->plugin->app()->get(EntryService::class)->canPreview($user, $entry, $type)) {
            return false;
        }
        if ($this->plugin->app()->get(Content::class)->publiclyAccessibleEntry($entry)) {
            return true;
        }
        // Published children below private ancestors and future entries are not
        // public. Only an editor of that type or the owner may reference them.
        return $user->can($type->capability('edit_any'))
            || ($entry->authorId === $user->id && $user->can($type->capability('edit_own')));
    }

    /** @return array<string, mixed>|null */
    private function validatedLayout(mixed $data): ?array
    {
        if (!is_array($data) || ($data['version'] ?? null) !== 1 || !is_array($data['elements'] ?? null) || !array_is_list($data['elements'])) {
            return null;
        }
        return $this->partials->normalize($data['elements'], true, contextual: true);
    }

    /** @param array<mixed> $elements
     * @return array<mixed>|null
     */
    private function findElement(array $elements, string $target): ?array
    {
        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }
            if (($element['id'] ?? null) === $target) {
                return $element;
            }
            if (is_array($element['children'] ?? null)) {
                $found = $this->findElement($element['children'], $target);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    /** @param array<string, mixed> $model */
    private function metadataForm(Request $request, User $user, array $model, ?string $error = null, int $status = 200): Response
    {
        $types = [];
        foreach ($this->plugin->app()->get(ContentTypeRegistry::class)->allPublic() as $type) {
            $types[] = ['value' => $type->name, 'label' => $type->label('plural')];
        }
        $taxonomies = [];
        $terms = [];
        foreach ($this->plugin->app()->get(TaxonomyRegistry::class)->all() as $taxonomy) {
            if (!$taxonomy->public) {
                continue;
            }
            $taxonomies[] = ['value' => $taxonomy->name, 'label' => $taxonomy->label('plural')];
            foreach ($this->plugin->app()->get(TermRepository::class)->allForTaxonomy($taxonomy->name) as $term) {
                $terms[] = ['value' => $taxonomy->name . ':' . $term->id, 'label' => $taxonomy->label('singular') . ' — ' . $term->name];
            }
        }
        $entries = [];
        foreach (['include', 'exclude'] as $group) {
            foreach ($model[$group] as $rule) {
                if ($rule['type'] !== 'entry') {
                    continue;
                }
                $entry = $this->plugin->app()->get(EntryRepository::class)->find((int) $rule['value'], false);
                $type = $entry !== null ? $this->plugin->app()->get(ContentTypeRegistry::class)->get($entry->type) : null;
                if ($entry !== null && $type !== null && $this->canReference($user, $entry, $type)) {
                    $entries[$rule['value']] = $entry->title;
                }
            }
        }
        $config = [
            'types' => $types, 'taxonomies' => $taxonomies, 'terms' => $terms, 'entries' => $entries,
            'labels' => [
                'choose' => t('plugin::prime-builder.theme.choose'), 'search' => t('plugin::prime-builder.theme.search_content'),
                'remove' => t('plugin::prime-builder.theme.remove_content'), 'results' => t('plugin::prime-builder.theme.results'),
                'no_results' => t('plugin::prime-builder.theme.no_results'), 'search_error' => t('plugin::prime-builder.theme.search_error'),
            ],
        ];
        $title = t('plugin::prime-builder.theme.conditions_title');
        $data = $this->plugin->app()->get(AdminContext::class)->build($request, $title, [
            'pageHeading' => $title,
            'breadcrumbs' => [
                ['label' => t('admin.nav.dashboard'), 'url' => '/admin'],
                ['label' => t('plugin::prime-builder.theme.menu'), 'url' => '/admin/prime-builder/theme'],
                ['label' => (string) $model['name'], 'url' => null],
            ],
            'model' => $model, 'error' => $error,
            'themeConfig' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
        ]);
        if (is_array($data['adminStyles'] ?? null)) {
            $data['adminStyles'][] = $this->plugin->url('css/dashboard.css');
        }
        if (is_array($data['adminScripts'] ?? null)) {
            $data['adminScripts'][] = $this->plugin->url('js/theme.js');
        }
        return Response::html($this->plugin->views()->render('prime-builder::admin/theme-model', $data), $status)
            ->withHeader('Cache-Control', 'no-store, private');
    }
}
