<?php

declare(strict_types=1);

namespace PrimeBuilder\Api;

use Prime\Content\EntryRepository;
use Prime\Content\EntryService;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Jobs\JobQueue;
use Prime\Plugins\PluginContext;
use Prime\Security\HtmlSanitizer;
use PrimeBuilder\Document\DocumentValidator;
use PrimeBuilder\Forms\SubmissionRepository;
use PrimeBuilder\Plugin;
use PrimeBuilder\Templates\TemplateRepository;
use PrimeBuilder\Widgets\WidgetRegistry;

final class BuilderApi
{
    public function __construct(
        private readonly PluginContext $context,
        private readonly WidgetRegistry $widgets,
        private readonly TemplateRepository $templates,
        private readonly SubmissionRepository $submissions,
    ) {
    }

    public function saveDocument(Request $request): Response
    {
        $id = (int) $request->routeParam('id', 0);
        if ($id <= 0) {
            return Response::json(['error' => 'Invalid entry ID'], 400);
        }

        $entries = $this->context->app()->get(EntryRepository::class);
        $entry = $entries->find($id);
        if ($entry === null) {
            return Response::json(['error' => 'Entry not found'], 404);
        }

        $payload = $request->json();
        $doc = is_array($payload['document'] ?? null) ? $payload['document'] : $payload;
        if (!is_array($doc)) {
            return Response::json(['error' => 'Invalid payload'], 422);
        }

        $sanitizer = $this->context->app()->get(HtmlSanitizer::class);
        $validator = new DocumentValidator($this->widgets->types());
        $validated = $validator->validate($doc, $sanitizer);

        $primeDoc = [
            'version' => 1,
            'blocks' => [
                [
                    'id' => 'pb_root_' . $id,
                    'type' => Plugin::BLOCK_LAYOUT,
                    'data' => $validated['data'],
                ],
            ],
        ];

        $service = $this->context->app()->get(EntryService::class);
        $update = [
            'content' => json_encode($primeDoc, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];
        if (is_string($payload['status'] ?? null) && in_array($payload['status'], ['draft', 'published', 'scheduled'], true)) {
            $update['status'] = $payload['status'];
        }

        $service->update($id, $update);

        return Response::json([
            'ok' => true,
            'errors' => $validated['errors'],
            'document' => $validated['data'],
        ]);
    }

    public function listTemplates(Request $request): Response
    {
        $type = $request->query('type');

        return Response::json([
            'templates' => $this->templates->all(is_string($type) && $type !== '' ? $type : null),
        ]);
    }

    public function submitForm(Request $request): Response
    {
        $formId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) $request->routeParam('id', 'contact')) ?? 'contact';
        $data = $request->json();
        if (!is_array($data) || $data === []) {
            $data = $request->all();
        }

        // Honeypot check
        if (trim((string) ($data['_pb_hp'] ?? '')) !== '') {
            return Response::json(['error' => t('plugin::prime-builder.forms.spam_detected')], 422);
        }

        // Minimum delay check (2 seconds)
        $ts = (int) ($data['_pb_ts'] ?? 0);
        if ($ts > 0 && (time() - $ts) < 2) {
            return Response::json(['error' => t('plugin::prime-builder.forms.spam_detected')], 422);
        }

        // Rate limit by IP hash (max 5 per minute)
        $ip = (string) ($request->server('REMOTE_ADDR') ?? '127.0.0.1');
        $ipHash = hash('sha256', $ip);
        if ($this->submissions->countRecentByIp($ipHash, 60) >= 5) {
            return Response::json(['error' => t('plugin::prime-builder.forms.rate_limited')], 429);
        }

        $cleanData = [];
        foreach ($data as $k => $v) {
            if (!is_string($k) || str_starts_with($k, '_pb_')) {
                continue;
            }
            $cleanKey = preg_replace('/[^a-zA-Z0-9_-]/', '', $k) ?? '';
            if ($cleanKey !== '' && is_scalar($v)) {
                $cleanData[$cleanKey] = mb_substr(trim((string) $v), 0, 5000);
            }
        }

        if ($cleanData === []) {
            return Response::json(['error' => t('plugin::prime-builder.forms.submit_error')], 422);
        }

        $submissionId = $this->submissions->create($formId, $cleanData, [
            'user_agent' => mb_substr((string) ($request->header('User-Agent') ?? ''), 0, 255),
        ], $ipHash);

        $adminEmail = trim((string) $this->context->settings()->get('form_admin_email', ''));
        if ($adminEmail !== '') {
            try {
                $this->context->app()->get(JobQueue::class)->dispatch(Plugin::JOB_FORM_EMAIL, [
                    'to' => $adminEmail,
                    'template' => 'prime-builder::mail/form-admin',
                    'subject' => 'Nouvel envoi : ' . $formId,
                    'data' => ['formId' => $formId, 'fields' => $cleanData],
                ]);
            } catch (\Throwable) {
                // Queue optional
            }
        }

        return Response::json([
            'ok' => true,
            'id' => $submissionId,
            'message' => t('plugin::prime-builder.forms.submit_success'),
        ], 201);
    }
}
