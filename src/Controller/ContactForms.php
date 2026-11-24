<?php

declare(strict_types=1);

namespace PrimeBuilder\Controller;

use Prime\Admin\AdminContext;
use Prime\Api\ApiError;
use Prime\Auth\Auth;
use Prime\Auth\User;
use Prime\Content\Entry;
use Prime\Content\EntryRepository;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Plugins\PluginContext;
use PrimeBuilder\Document\Layout;
use PrimeBuilder\Forms\RateLimit;
use PrimeBuilder\Forms\Submissions;
use PrimeBuilder\Forms\Tokens;
use PrimeBuilder\Widgets\Content;

/** A first, deliberately fixed contact form and its protected submission inbox. */
final class ContactForms
{
    private readonly Submissions $submissions;

    public function __construct(private readonly PluginContext $plugin)
    {
        $this->submissions = new Submissions($plugin->db());
    }

    public function index(Request $request): Response
    {
        if (!$this->canManage($request)) {
            return Response::text('', 403);
        }
        $title = t('plugin::prime-builder.forms.inbox');
        $data = $this->plugin->app()->get(AdminContext::class)->build($request, $title, [
            'pageHeading' => $title,
            'breadcrumbs' => [
                ['label' => t('admin.nav.dashboard'), 'url' => '/admin'],
                ['label' => $title, 'url' => null],
            ],
            'submissions' => $this->submissions->latest(),
        ]);
        if (is_array($data['adminStyles'] ?? null)) {
            $data['adminStyles'][] = $this->plugin->url('css/dashboard.css');
        }
        return Response::html($this->plugin->views()->render('prime-builder::admin/submissions', $data))
            ->withHeader('Cache-Control', 'no-store, private');
    }

    public function delete(Request $request, string $id): Response
    {
        if (!$this->canManage($request)) {
            return Response::text('', 403);
        }
        $id = self::positiveId($id);
        if ($id === null || !$this->submissions->delete($id)) {
            return Response::text('', 404)->withHeader('Cache-Control', 'no-store, private');
        }
        $this->plugin->app()->get(AdminContext::class)->flashToast(t('plugin::prime-builder.forms.deleted'), 'success');
        return Response::redirect('/admin/prime-builder/submissions')->withHeader('Cache-Control', 'no-store, private');
    }

    public function start(Request $request, string $id): Response
    {
        $node = $request->query('node');
        $entryId = self::positiveId($id);
        if ($entryId === null || !is_string($node) || !$this->publicForm($entryId, $node)) {
            return $this->error($request, 'not_found', 404);
        }
        $tokens = Tokens::fromAppKey($this->plugin->app()->config()->get('app.key'));
        if ($tokens === null) {
            return $this->error($request, 'server_error', 500);
        }
        $now = time();
        if (!(new RateLimit($this->plugin->db(), $tokens))->allow('start', $request->ip(), 30, 60, $now)) {
            return $this->error($request, 'rate_limited', 429)->withHeader('Retry-After', (string) (60 - $now % 60));
        }
        return Response::json(['data' => ['token' => $tokens->issue($entryId, $node, $request->ip(), $now)]])
            ->withHeader('Cache-Control', 'no-store, private');
    }

    public function submit(Request $request, string $id): Response
    {
        $body = $request->attribute('api.body');
        $entryId = self::positiveId($id);
        $node = is_array($body) && is_string($body['node'] ?? null) ? $body['node'] : '';
        if ($entryId === null || !$this->publicForm($entryId, $node)) {
            return $this->error($request, 'not_found', 404);
        }
        $tokens = Tokens::fromAppKey($this->plugin->app()->config()->get('app.key'));
        if ($tokens === null) {
            return $this->error($request, 'server_error', 500);
        }
        $now = time();
        if (!(new RateLimit($this->plugin->db(), $tokens))->allow('submit', $request->ip(), 5, 300, $now)) {
            return $this->error($request, 'rate_limited', 429)->withHeader('Retry-After', (string) (300 - $now % 300));
        }
        if (!is_array($body) || strlen($request->rawBody()) > 24000) {
            return $this->error($request, 'validation_failed', 422);
        }
        foreach (array_keys($body) as $key) {
            if (!is_string($key) || !in_array($key, ['node', 'name', 'email', 'message', 'website', 'token'], true)) {
                return $this->error($request, 'validation_failed', 422);
            }
        }
        foreach (['name', 'email', 'message', 'website', 'token'] as $key) {
            if (!is_string($body[$key] ?? null)) {
                return $this->error($request, 'validation_failed', 422);
            }
        }
        if ($body['website'] !== '') {
            // Do not help automated fillers distinguish the trap; no data is stored.
            return Response::json(['data' => ['accepted' => true]], 201)->withHeader('Cache-Control', 'no-store, private');
        }
        if (!$tokens->valid($body['token'], $entryId, $node, $request->ip(), $now)) {
            return $this->error($request, 'validation_failed', 422);
        }
        $name = trim($body['name']);
        $email = trim($body['email']);
        $message = trim(str_replace(["\r\n", "\r"], "\n", $body['message']));
        if ($name === '' || $email === '' || $message === ''
            || !mb_check_encoding($name . $email . $message, 'UTF-8')
            || mb_strlen($name, 'UTF-8') > 160 || strlen($name) > 640
            || strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false
            || mb_strlen($message, 'UTF-8') > 5000 || strlen($message) > 20000
            || preg_match('/[\x00-\x1f\x7f]/', $name . $email) === 1
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $message) === 1) {
            return $this->error($request, 'validation_failed', 422);
        }
        $created = $this->submissions->create($entryId, $node, ['name' => $name, 'email' => $email, 'message' => $message], $tokens->usedHash($body['token']), $now);
        if (!$created) {
            return $this->error($request, 'conflict', 409);
        }
        return Response::json(['data' => ['accepted' => true]], 201)->withHeader('Cache-Control', 'no-store, private');
    }

    private function canManage(Request $request): bool
    {
        $user = $this->plugin->app()->get(Auth::class)->user($request);
        return $user instanceof User && $user->can('prime_builder.forms');
    }

    private static function positiveId(string $id): ?int
    {
        return preg_match('/^[1-9][0-9]{0,18}$/D', $id) === 1 && (string) (int) $id === $id ? (int) $id : null;
    }

    private function publicForm(int $entryId, string $node): bool
    {
        if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $node) !== 1) {
            return false;
        }
        $entry = $this->plugin->app()->get(EntryRepository::class)->find($entryId, false);
        // A published page can still be unreachable below a draft, future,
        // missing or cyclic ancestor. Check again on both start and submit.
        if (!$entry instanceof Entry || !$this->plugin->app()->get(Content::class)->publiclyAccessibleEntry($entry)) {
            return false;
        }
        $layout = Layout::fromDocument($entry->decodedContent());
        return is_array($layout['elements'] ?? null) && self::containsVisibleForm($layout['elements'], $node);
    }

    /** @param array<mixed> $elements */
    private static function containsVisibleForm(array $elements, string $node): bool
    {
        foreach ($elements as $element) {
            if (!is_array($element) || ($element['hidden'] ?? false) === true) {
                continue;
            }
            if (($element['id'] ?? null) === $node && ($element['type'] ?? null) === 'form') {
                return true;
            }
            if (is_array($element['children'] ?? null) && self::containsVisibleForm($element['children'], $node)) {
                return true;
            }
        }
        return false;
    }

    private function error(Request $request, string $code, int $status): Response
    {
        return ApiError::response($code, $status, $request)->withHeader('Cache-Control', 'no-store, private');
    }
}
