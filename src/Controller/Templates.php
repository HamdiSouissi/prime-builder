<?php

declare(strict_types=1);

namespace PrimeBuilder\Controller;

use Prime\Api\ApiError;
use Prime\Auth\User;
use Prime\Editor\BlockRegistry;
use Prime\Hooks\Hooks;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Plugins\PluginContext;
use Prime\Security\HtmlSanitizer;
use Prime\Settings\Settings;
use PrimeBuilder\Document\Library;
use PrimeBuilder\Document\SavedTemplates;
use PrimeBuilder\Document\Validator;
use PrimeBuilder\Widgets\Registry;

final class Templates
{
    private readonly SavedTemplates $saved;

    public function __construct(private readonly PluginContext $plugin)
    {
        $this->saved = new SavedTemplates($plugin->settings(), $plugin->app()->get(Settings::class));
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return array_merge((new Library($this->plugin->path('templates')))->all(), $this->saved->all());
    }

    public function index(Request $request): Response
    {
        $user = $request->attribute('api.user');
        if (!$user instanceof User || !$user->can('prime_builder.edit')) {
            return ApiError::response('forbidden', 403, $request);
        }
        return Response::json(['data' => $this->all()])->withHeader('Cache-Control', 'no-store, private');
    }

    public function create(Request $request): Response
    {
        $user = $request->attribute('api.user');
        if (!$user instanceof User || !$user->can('prime_builder.edit') || !$user->can('prime_builder.templates')) {
            return ApiError::response('forbidden', 403, $request);
        }
        $body = $request->attribute('api.body');
        if (!is_array($body) || strlen($request->rawBody()) > SavedTemplates::MAX_BYTES + 2048
            || !is_string($body['name'] ?? null) || !is_array($body['data'] ?? null)
            || !in_array($body['kind'] ?? null, ['section', 'page'], true)) {
            return ApiError::response('validation_failed', 422, $request);
        }
        $name = trim(strip_tags($body['name']));
        if ($name === '' || strlen($name) > 320 || preg_match('//u', $name) !== 1
            || mb_strlen($name, 'UTF-8') > 100 || preg_match('/[\x00-\x1F\x7F]/', $name) === 1) {
            return ApiError::response('validation_failed', 422, $request);
        }
        // A protected historical fragment belongs to its source entry, not to the shared library.
        // Without an OriginalContent instance the validator rejects it, even nested in a container.
        $validator = new Validator(new Registry($this->plugin->app()->get(Hooks::class)), $this->plugin->app()->get(HtmlSanitizer::class), $this->plugin->app()->get(BlockRegistry::class));
        $result = $validator->validate($body['data']);
        $elements = $result['data']['elements'] ?? null;
        if ($result['errors'] !== [] || !is_array($elements) || $elements === []) {
            return ApiError::response('validation_failed', 422, $request, ['fields' => ['content' => $result['errors']]]);
        }
        $saved = $this->saved->create($name, $body['kind'], $elements);
        if ($saved === null) {
            return ApiError::response('validation_failed', 422, $request);
        }
        return Response::json(['data' => $saved], 201)->withHeader('Cache-Control', 'no-store, private');
    }

    public function delete(Request $request, string $id): Response
    {
        $user = $request->attribute('api.user');
        if (!$user instanceof User || !$user->can('prime_builder.edit') || !$user->can('prime_builder.templates')) {
            return ApiError::response('forbidden', 403, $request);
        }
        if (!$this->saved->delete($id)) {
            return ApiError::response('not_found', 404, $request);
        }
        return Response::json(['data' => ['deleted' => true]])->withHeader('Cache-Control', 'no-store, private');
    }
}
