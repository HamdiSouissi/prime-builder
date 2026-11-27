<?php

declare(strict_types=1);

namespace PrimeBuilder\Controller;

use Prime\Admin\AdminContext;
use Prime\Api\ApiError;
use Prime\Auth\Auth;
use Prime\Auth\User;
use Prime\Content\Entry;
use Prime\Content\EntryRepository;
use Prime\Hooks\Hooks;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Plugins\PluginContext;
use Prime\Security\Csrf;
use Prime\Settings\Settings;
use Prime\Site\PageContext;
use Prime\Site\UrlResolver;
use PrimeBuilder\Configuration;
use PrimeBuilder\Document\Layout;
use PrimeBuilder\Dynamic\Context as DynamicContext;
use PrimeBuilder\Dynamic\Layout as DynamicLayout;
use PrimeBuilder\Forms\CsvExport;
use PrimeBuilder\Forms\Fields;
use PrimeBuilder\Forms\FileInput;
use PrimeBuilder\Forms\Notifications;
use PrimeBuilder\Forms\NotificationSettings;
use PrimeBuilder\Forms\RateLimit;
use PrimeBuilder\Forms\Submissions;
use PrimeBuilder\Forms\Success;
use PrimeBuilder\Forms\Tokens;
use PrimeBuilder\Popup\Options as PopupOptions;
use PrimeBuilder\Theme\Models;
use PrimeBuilder\Widgets\Content;
use PrimeBuilder\Widgets\Registry;

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
        return $this->startForm($request, $id, false);
    }

    public function startModel(Request $request, string $id): Response
    {
        return $this->startForm($request, $id, true);
    }

    private function startForm(Request $request, string $id, bool $model): Response
    {
        $node = $request->query('node');
        $page = $request->query('page');
        $pageType = $request->query('page_type');
        $proof = $request->query('proof');
        $entryId = self::positiveId($id);
        $form = $model
            ? $this->publicModelForm($request, $id, $node, $page, $pageType, $proof)
            : ($entryId !== null && is_string($node) && $this->publicForm($request, $entryId, $node) !== null ? ['entry_id' => $entryId] : null);
        if ($form === null || !is_string($node)) {
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
        $token = $model && is_string($page) && is_string($pageType)
            ? $tokens->issueModel($id, $node, $page, $pageType, $request->ip(), $now)
            : $tokens->issue((int) $form['entry_id'], $node, $request->ip(), $now);
        $data = ['token' => $token];
        if ($request->attribute('api.user') instanceof User && $request->attribute('api.token_id') === null) {
            // A logged-in browser needs the CMS's CSRF header for its POST.
            // Deliver it only through this non-cacheable, same-origin response.
            $data['csrf'] = $this->plugin->app()->get(Csrf::class)->token();
        }
        return Response::json(['data' => $data])->withHeader('Cache-Control', 'no-store, private');
    }

    public function submit(Request $request, string $id): Response
    {
        return $this->submitForm($request, $id, false);
    }

    public function submitModel(Request $request, string $id): Response
    {
        return $this->submitForm($request, $id, true);
    }

    private function submitForm(Request $request, string $id, bool $model): Response
    {
        $body = $request->attribute('api.body');
        $entryId = self::positiveId($id);
        $node = is_array($body) && is_string($body['node'] ?? null) ? $body['node'] : '';
        $page = is_array($body) ? ($body['page'] ?? null) : null;
        $pageType = is_array($body) ? ($body['page_type'] ?? null) : null;
        $proof = is_array($body) ? ($body['proof'] ?? null) : null;
        if ($model) {
            $form = $this->publicModelForm($request, $id, $node, $page, $pageType, $proof);
            $settings = $form['settings'] ?? null;
            $entryId = $form['entry_id'] ?? null;
        } else {
            $settings = $entryId !== null ? $this->publicForm($request, $entryId, $node) : null;
        }
        if ($entryId === null || !is_array($settings)) {
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
        if ($model) {
            $allowed = [...$allowed, 'page', 'page_type', 'proof'];
        }
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
        $validToken = $model && is_string($page) && is_string($pageType)
            ? $tokens->validModel($body['token'], $id, $node, $page, $pageType, $request->ip(), $now)
            : $tokens->valid($body['token'], $entryId, $node, $request->ip(), $now);
        if (!$validToken) {
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
            $model ? $id : null,
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
    private function publicForm(Request $request, int $entryId, string $node): ?array
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
        $elements = is_array($layout['elements'] ?? null) ? $layout['elements'] : [];
        return $this->visibleSettings($request, $elements, $node, null, $entry);
    }

    /**
     * Resolve the current public context and current winning model on both start
     * and submit. The proof is cache-safe, is not a submission token, and only
     * attests that this node was rendered at this path; it grants no access to
     * a disabled, retargeted, hidden or superseded model.
     *
     * @return array{entry_id: int, settings: array<string, mixed>}|null
     */
    private function publicModelForm(Request $request, string $id, mixed $node, mixed $path, mixed $pageType, mixed $proof): ?array
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1 || !is_string($node) || preg_match('/^[a-zA-Z0-9_-]{1,64}$/D', $node) !== 1
            || !is_string($path) || strlen($path) > 2048 || !str_starts_with($path, '/') || str_starts_with($path, '//')
            || preg_match('/[\\x00-\\x20\\x7f\\\\?#]/', $path) === 1
            || !is_string($pageType) || !in_array($pageType, ['site', 'plugin'], true)
            || !is_string($proof) || preg_match('/^[a-f0-9]{64}$/D', $proof) !== 1) {
            return null;
        }
        $tokens = Tokens::fromAppKey($this->plugin->app()->config()->get('app.key'));
        if ($tokens === null || !hash_equals($tokens->modelProof($id, $node, $path, $pageType), $proof)) {
            return null;
        }
        $app = $this->plugin->app();
        // PluginContext::renderPage() does not necessarily register a URL with
        // UrlResolver. Its signed public rendering context has no entry/term.
        $page = $pageType === 'plugin'
            ? PageContext::plugin($path, '', '')
            : $app->get(UrlResolver::class)->resolve(Request::create('GET', $path));
        if ($page->path !== $path || $page->isRedirect() || $page->isPreview || $page->isCustomizerPreview
            || ($page->isPlugin() !== ($pageType === 'plugin'))
            || ($page->httpStatus !== 200 && !($page->isNotFound() && $page->httpStatus === 404))
            || ($page->entry !== null && !$app->get(Content::class)->publiclyAccessibleEntry($page->entry))) {
            return null;
        }
        $models = new Models($this->plugin->settings(), $app->get(Settings::class), $this->plugin);
        $target = $models->find($id);
        if ($target === null || $target['enabled'] !== true || !is_string($target['kind'] ?? null)) {
            return null;
        }
        $kind = $target['kind'];
        foreach ($models->matching($kind, $page) as $candidate) {
            $layout = $models->validatedLayout($candidate);
            if ($layout === null) {
                continue;
            }
            if (($candidate['id'] ?? null) !== $id) {
                // Unlike popups, only the first valid theme model is rendered.
                if ($kind !== 'popup') {
                    return null;
                }
                continue;
            }
            $dynamic = new DynamicContext($this->plugin, $page, null, $request);
            if ($kind === 'popup') {
                $options = PopupOptions::normalize($candidate['popup'] ?? null);
                if ($options === null || ($options['hide_logged_in'] && $dynamic->user() !== null)) {
                    return null;
                }
            }
            $elements = is_array($layout['elements'] ?? null) ? $layout['elements'] : [];
            $settings = $this->visibleSettings($request, $elements, $node, $page, null);
            return $settings === null ? null : ['entry_id' => $page->entry->id ?? 0, 'settings' => $settings];
        }
        return null;
    }

    /** @param array<mixed> $elements
     * @return array<string, mixed>|null
     */
    private function visibleSettings(Request $request, array $elements, string $node, ?PageContext $page, ?Entry $entry): ?array
    {
        $app = $this->plugin->app();
        $dynamic = new DynamicContext($this->plugin, $page, $entry, $request);
        $elements = DynamicLayout::prepare($elements, (new Registry($app->get(Hooks::class)))->all(), true, $dynamic->user(), $dynamic->value(...));
        $settings = self::visibleForm($elements, $node);
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
