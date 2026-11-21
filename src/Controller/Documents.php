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
use Prime\Editor\Renderer as CoreRenderer;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Plugins\PluginContext;
use PrimeBuilder\Document\Layout;
use PrimeBuilder\Document\Validator;
use PrimeBuilder\Styles\Compiler;
use PrimeBuilder\Widgets\Registry;
use PrimeBuilder\Widgets\Renderer;

final class Documents
{
    public function __construct(private readonly PluginContext $plugin)
    {
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

    public function edit(Request $request, string $id): Response
    {
        $user = $this->plugin->app()->get(Auth::class)->user($request);
        $entry = $this->entry((int) $id, $user);
        if ($entry === null) {
            return Response::text(t('plugin::prime-builder.ui.not_found'), 404);
        }
        $type = $this->plugin->app()->get(ContentTypeRegistry::class)->get($entry->type);
        $service = $this->plugin->app()->get(EntryService::class);
        $registry = new Registry($this->plugin->app()->get(\Prime\Hooks\Hooks::class));
        $definitions = [];
        foreach ($registry->all() as $name => $definition) {
            // Never send server callbacks or non-serializable plugin data to the browser.
            $definitions[$name] = [
                'label' => t(is_string($definition['label'] ?? null) ? $definition['label'] : $name),
                'category' => is_string($definition['category'] ?? null) ? $definition['category'] : 'base',
                'fields' => $definition['fields'],
                'container' => ($definition['container'] ?? false) === true,
            ];
        }
        $ui = [];
        foreach (['widgets', 'search', 'navigator', 'content', 'style', 'advanced', 'add', 'remove', 'duplicate', 'move_up', 'move_down', 'empty', 'select', 'save', 'saving', 'saved', 'save_error', 'unsaved', 'back', 'desktop', 'tablet', 'mobile', 'undo', 'redo', 'preview', 'publish', 'draft', 'text', 'url', 'alt', 'width', 'height', 'level', 'layout', 'direction', 'columns', 'author', 'value', 'anchor', 'padding', 'margin', 'gap', 'font_size', 'radius', 'min_height', 'color', 'background', 'align', 'left', 'center', 'right', 'flex', 'grid', 'column', 'row', 'structure', 'base', 'confirm_replace', 'replace', 'select_container', 'responsive_help', 'history', 'drop', 'revisions', 'document_status', 'edit_element', 'invalid', 'connection_error', 'autosave_found', 'restore', 'discard'] as $key) {
            $ui[$key] = t('plugin::prime-builder.ui.' . $key);
        }
        $config = [
            'entryId' => $entry->id,
            'document' => $entry->decodedContent(),
            'previewUrl' => $entry->previewUrl(),
            'publicCssUrl' => $this->plugin->url('css/public.css'),
            'editUrl' => $entry->editUrl(),
            'revisionsUrl' => '/admin/content/' . rawurlencode($entry->type) . '/' . $entry->id . '/revisions',
            'status' => $entry->status,
            'canPublish' => $user instanceof User && $type !== null && $service->canPublish($user, $type),
            'widgets' => $definitions,
            'ui' => $ui,
        ];
        $html = $this->plugin->views()->render('prime-builder::admin/builder', [
            'entry' => $entry,
            'configJson' => json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) ?: '{}',
            'cssUrl' => $this->plugin->url('css/editor.css'),
            'jsUrl' => $this->plugin->url('js/editor.js'),
        ]);
        return Response::html($html)->withHeader('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, string $id): Response
    {
        $user = $request->attribute('api.user');
        $entry = $this->entry((int) $id, $user instanceof User ? $user : null);
        if ($entry === null || !$user instanceof User) {
            return ApiError::response('not_found', 404, $request);
        }
        $body = $request->attribute('api.body');
        if (!is_array($body) || !is_array($body['content'] ?? null) || strlen($request->rawBody()) > Validator::MAX_BYTES + 8192) {
            return ApiError::response('validation_failed', 422, $request);
        }
        // Require the builder's canonical single-block document; never accept arbitrary privileged core blocks.
        $blocks = $body['content']['blocks'] ?? null;
        if (($body['content']['version'] ?? null) !== 1 || !is_array($blocks) || count($blocks) !== 1 || !is_array($blocks[0] ?? null) || ($blocks[0]['type'] ?? null) !== 'prime-builder/layout') {
            return ApiError::response('validation_failed', 422, $request);
        }
        $input = ['content' => $body['content']];
        if (is_string($body['status'] ?? null)) {
            $input['status'] = $body['status'];
        }
        if (is_string($body['published_at'] ?? null)) {
            $input['published_at'] = $body['published_at'];
        }
        try {
            $saved = $this->plugin->app()->get(EntryService::class)->update($entry->id, $input, $user);
            return Response::json(['data' => ['id' => $saved->id, 'status' => $saved->status, 'content' => $saved->decodedContent(), 'updated_at' => $saved->updatedAt]]);
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
        $entry = $this->entry((int) $id, $user instanceof User ? $user : null);
        if ($entry === null) {
            return ApiError::response('not_found', 404, $request);
        }
        $body = $request->attribute('api.body');
        if (!is_array($body) || !is_array($body['data'] ?? null) || strlen($request->rawBody()) > Validator::MAX_BYTES + 8192) {
            return ApiError::response('validation_failed', 422, $request);
        }
        $validator = new Validator(new Registry($this->plugin->app()->get(\Prime\Hooks\Hooks::class)), $this->plugin->app()->get(\Prime\Security\HtmlSanitizer::class));
        $result = $validator->validate($body['data']);
        if ($result['errors'] !== []) {
            return ApiError::response('validation_failed', 422, $request, ['fields' => ['content' => $result['errors']]]);
        }
        $renderer = new Renderer(new Registry($this->plugin->app()->get(\Prime\Hooks\Hooks::class)), $this->plugin->app()->get(\Prime\Security\HtmlSanitizer::class), $this->plugin->logger());
        return Response::json(['data' => ['html' => $renderer->render($result['data'])]]);
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
        $data = Layout::fromDocument($entry->decodedContent());
        if ($data === null) {
            return Response::text('', 404);
        }
        $css = (new Compiler())->compile($data);
        if (!hash_equals(substr(hash('sha256', $css), 0, 16), $matches[2])) {
            return Response::text('', 404);
        }
        return new Response($css, 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => $public ? 'public, max-age=31536000, immutable' : 'private, no-store',
        ]);
    }
}
