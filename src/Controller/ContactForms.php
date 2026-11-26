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
use PrimeBuilder\Configuration;
use PrimeBuilder\Document\Layout;
use PrimeBuilder\Forms\CsvExport;
use PrimeBuilder\Forms\Fields;
use PrimeBuilder\Forms\FileInput;
use PrimeBuilder\Forms\Notifications;
use PrimeBuilder\Forms\NotificationSettings;
use PrimeBuilder\Forms\RateLimit;
use PrimeBuilder\Forms\Submissions;
use PrimeBuilder\Forms\Success;
use PrimeBuilder\Forms\Tokens;
use PrimeBuilder\Widgets\Content;

/** Public, cache-compatible forms and their protected submission inbox. */
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
        $search = self::searchQuery($request);
        $rawPage = $request->query('page', '1');
        if ($search === null || !is_string($rawPage) || preg_match('/^[1-9][0-9]{0,5}$/D', $rawPage) !== 1) {
            return Response::text(t('plugin::prime-builder.forms.search_invalid'), 422)->withHeader('Cache-Control', 'no-store, private');
        }
        $page = (int) $rawPage;
        $results = $this->submissions->search($search, Submissions::PAGE_SIZE + 1, ($page - 1) * Submissions::PAGE_SIZE);
        $hasNext = count($results) > Submissions::PAGE_SIZE;
        $results = array_slice($results, 0, Submissions::PAGE_SIZE);
        $title = t('plugin::prime-builder.forms.inbox');
        $data = $this->plugin->app()->get(AdminContext::class)->build($request, $title, [
            'pageHeading' => $title,
            'breadcrumbs' => [
                ['label' => t('admin.nav.dashboard'), 'url' => '/admin'],
                ['label' => $title, 'url' => null],
            ],
            'submissions' => $results,
            'files' => $this->submissions->fileMetadata(array_column($results, 'id')),
            'search' => $search,
            'page' => $page,
            'hasNext' => $hasNext && $page < 999999,
            'retentionDays' => Submissions::retentionDays(Configuration::get($this->plugin->settings(), 'submission_retention_days', Submissions::RETENTION_DAYS)),
        ]);
        if (is_array($data['adminStyles'] ?? null)) {
            $data['adminStyles'][] = $this->plugin->url('css/dashboard.css');
        }
        return Response::html($this->plugin->views()->render('prime-builder::admin/submissions', $data))
            ->withHeader('Cache-Control', 'no-store, private')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }

    public function export(Request $request): Response
    {
        if (!$this->canManage($request)) {
            return Response::text('', 403)->withHeader('Cache-Control', 'no-store, private');
        }
        $search = self::searchQuery($request);
        $rawPage = $request->query('page');
        if ($search === null || ($rawPage !== null && (!is_string($rawPage) || preg_match('/^[1-9][0-9]{0,5}$/D', $rawPage) !== 1))) {
            return Response::text(t('plugin::prime-builder.forms.search_invalid'), 422)->withHeader('Cache-Control', 'no-store, private');
        }
        $csv = CsvExport::render($this->submissions, $search, $rawPage === null ? null : (int) $rawPage);
        if ($csv === null) {
            return Response::text(t('plugin::prime-builder.forms.export_too_large'), 413)->withHeader('Cache-Control', 'no-store, private');
        }
        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="prime-builder-submissions.csv"',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    private static function searchQuery(Request $request): ?string
    {
        $search = $request->query('q', '');
        if (!is_string($search) || strlen($search) > 640 || !mb_check_encoding($search, 'UTF-8')
            || mb_strlen($search, 'UTF-8') > 160 || preg_match('/[\x00-\x1f\x7f]/', $search) === 1) {
            return null;
        }
        return trim($search);
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

    public function download(Request $request, string $id, string $file): Response
    {
        if (!$this->canManage($request)) {
            return Response::text('', 403)->withHeader('Cache-Control', 'no-store, private');
        }
        $submissionId = self::positiveId($id);
        $fileId = self::positiveId($file);
        $stored = $submissionId !== null && $fileId !== null ? $this->submissions->file($submissionId, $fileId) : null;
        $checked = $stored !== null ? FileInput::validate(['name' => $stored['filename'] ?? null, 'content' => $stored['content_base64'] ?? null]) : null;
        if ($checked === null || $checked['bytes'] !== (int) ($stored['bytes'] ?? 0) || $checked['mime'] !== ($stored['mime'] ?? null)) {
            return Response::text('', 404)->withHeader('Cache-Control', 'no-store, private');
        }
        $contents = base64_decode($checked['content_base64'], true);
        if (!is_string($contents)) {
            return Response::text('', 404)->withHeader('Cache-Control', 'no-store, private');
        }
        return new Response($contents, 200, [
            'Content-Type' => 'application/octet-stream',
            'Content-Disposition' => 'attachment; filename="attachment"; filename*=UTF-8\'\'' . rawurlencode($checked['filename']),
            'Content-Length' => (string) $checked['bytes'],
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    public function start(Request $request, string $id): Response
    {
        $node = $request->query('node');
        $entryId = self::positiveId($id);
        if ($entryId === null || !is_string($node) || $this->publicForm($entryId, $node) === null) {
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
        $settings = $entryId !== null ? $this->publicForm($entryId, $node) : null;
        if ($entryId === null || $settings === null) {
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
        $custom = array_key_exists('form_fields', $settings);
        $fields = $custom ? Fields::parse((string) $settings['form_fields']) : null;
        $hasFile = $fields !== null && in_array('file', array_column($fields, 'type'), true);
        if (!is_array($body) || strlen($request->rawBody()) > ($hasFile ? FileInput::MAX_BODY_BYTES : 24000)) {
            return $this->error($request, 'validation_failed', 422);
        }
        $allowed = $custom ? ['node', 'fields', 'website', 'token'] : ['node', 'name', 'email', 'message', 'website', 'token'];
        foreach (array_keys($body) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                return $this->error($request, 'validation_failed', 422);
            }
        }
        foreach ($custom ? ['website', 'token'] : ['name', 'email', 'message', 'website', 'token'] as $key) {
            if (!is_string($body[$key] ?? null)) {
                return $this->error($request, 'validation_failed', 422);
            }
        }
        if ($custom && !is_array($body['fields'] ?? null)) {
            return $this->error($request, 'validation_failed', 422);
        }
        $success = Success::fromSettings($settings);
        if ($success === null) {
            return $this->error($request, 'not_found', 404);
        }
        $response = ['accepted' => true, 'message' => $success['message'] !== '' ? $success['message'] : t('plugin::prime-builder.forms.sent'), 'redirect' => $success['redirect']];
        if ($body['website'] !== '') {
            // Do not help automated fillers distinguish the trap; no data is stored.
            return Response::json(['data' => $response], 201)->withHeader('Cache-Control', 'no-store, private');
        }
        if (!$tokens->valid($body['token'], $entryId, $node, $request->ip(), $now)) {
            return $this->error($request, 'validation_failed', 422);
        }
        if ($custom) {
            // Never accept the definition supplied by the visitor. Use the current,
            // visible, published widget again, including after a start token was issued.
            $values = $fields !== null ? Fields::submitted($fields, $body['fields']) : null;
            if ($values === null) {
                return $this->error($request, 'validation_failed', 422);
            }
        } else {
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
            $values = ['name' => $name, 'email' => $email, 'message' => $message];
        }
        $notificationSettings = NotificationSettings::fromSettings($settings);
        if ($notificationSettings === null) {
            return $this->error($request, 'not_found', 404);
        }
        $adminAddress = NotificationSettings::address(Configuration::get($this->plugin->settings(), 'notification_email', ''));
        if ($notificationSettings['admin'] && ($adminAddress === null || $adminAddress === '')) {
            // Fail closed: never silently accept a submission while dropping a configured action.
            return $this->error($request, 'server_error', 503);
        }
        $receiptAddress = NotificationSettings::address($values['email']);
        if ($notificationSettings['receipt'] && $values['email'] !== '' && ($receiptAddress === null || $receiptAddress === '')) {
            return $this->error($request, 'validation_failed', 422);
        }
        $notifications = new Notifications($this->plugin);
        $created = $this->submissions->create(
            $entryId,
            $node,
            $values,
            $tokens->usedHash($body['token']),
            $now,
            static function (int $submissionId) use ($notifications, $notificationSettings, $adminAddress, $receiptAddress): void {
                $notifications->enqueue($submissionId, $notificationSettings, $adminAddress ?? '', $receiptAddress ?? '');
            },
        );
        if (!$created) {
            return $this->error($request, 'conflict', 409);
        }
        return Response::json(['data' => $response], 201)->withHeader('Cache-Control', 'no-store, private');
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

    /** @return array<string, mixed>|null */
    private function publicForm(int $entryId, string $node): ?array
    {
        if (preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $node) !== 1) {
            return null;
        }
        $entry = $this->plugin->app()->get(EntryRepository::class)->find($entryId, false);
        // A published page can still be unreachable below a draft, future,
        // missing or cyclic ancestor. Check again on both start and submit.
        if (!$entry instanceof Entry || !$this->plugin->app()->get(Content::class)->publiclyAccessibleEntry($entry)) {
            return null;
        }
        $layout = Layout::fromDocument($entry->decodedContent());
        $settings = is_array($layout['elements'] ?? null) ? self::visibleForm($layout['elements'], $node) : null;
        if ($settings !== null && (Success::fromSettings($settings) === null || NotificationSettings::fromSettings($settings) === null
            || (array_key_exists('form_fields', $settings)
                && (!is_string($settings['form_fields']) || Fields::parse($settings['form_fields']) === null)))) {
            return null;
        }
        return $settings;
    }

    /**
     * @param array<mixed> $elements
     * @return array<string, mixed>|null
     */
    private static function visibleForm(array $elements, string $node): ?array
    {
        foreach ($elements as $element) {
            if (!is_array($element) || ($element['hidden'] ?? false) === true) {
                continue;
            }
            if (($element['id'] ?? null) === $node && ($element['type'] ?? null) === 'form') {
                // Older documents may omit settings altogether; they still render
                // the original fixed form and must remain submittable.
                $settings = $element['settings'] ?? [];
                return is_array($settings) ? $settings : null;
            }
            if (is_array($element['children'] ?? null)) {
                $found = self::visibleForm($element['children'], $node);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    private function error(Request $request, string $code, int $status): Response
    {
        return ApiError::response($code, $status, $request)->withHeader('Cache-Control', 'no-store, private');
    }
}
