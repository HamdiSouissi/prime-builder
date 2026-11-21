<?php

declare(strict_types=1);

namespace PrimeBuilder\Controller;

use Prime\Admin\AdminContext;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Plugins\PluginContext;
use PrimeBuilder\Document\DocumentValidator;
use PrimeBuilder\Forms\SubmissionRepository;
use PrimeBuilder\Templates\TemplateRepository;
use PrimeBuilder\Widgets\WidgetRegistry;

final class AdminController
{
    public function __construct(
        private readonly PluginContext $context,
        private readonly AdminContext $admin,
        private readonly TemplateRepository $templates,
        private readonly SubmissionRepository $submissions,
        private readonly WidgetRegistry $widgets,
    ) {
    }

    public function templates(Request $request): Response
    {
        $type = $request->query('type');
        $typeFilter = is_string($type) && $type !== '' ? $type : null;

        return $this->renderAdmin('prime-builder::admin/templates', [
            'title' => t('plugin::prime-builder.templates.title'),
            'templates' => $this->templates->all($typeFilter),
            'activeType' => $typeFilter,
        ]);
    }

    public function createTemplate(Request $request): Response
    {
        $title = trim((string) $request->input('title', 'Nouveau modèle'));
        $type = (string) $request->input('type', 'section');
        $allowedTypes = ['page', 'section', 'widget', 'popup', 'header', 'footer', 'single', 'archive', 'search', '404'];
        if (!in_array($type, $allowedTypes, true)) {
            $type = 'section';
        }

        $this->templates->create([
            'title' => $title !== '' ? $title : 'Nouveau modèle',
            'type' => $type,
            'document_json' => '{"version":1,"elements":[]}',
        ]);

        return Response::redirect('/admin/prime-builder');
    }

    public function deleteTemplate(Request $request): Response
    {
        $id = (int) $request->routeParam('id', 0);
        if ($id > 0) {
            $this->templates->delete($id);
        }

        return Response::redirect('/admin/prime-builder');
    }

    public function importTemplate(Request $request): Response
    {
        $raw = trim((string) $request->input('json', ''));
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return Response::redirect('/admin/prime-builder');
        }

        $doc = is_array($decoded['document'] ?? null) ? $decoded['document'] : $decoded;
        $validator = new DocumentValidator($this->widgets->types());
        $validated = $validator->validate($doc, $this->context->app()->get(\Prime\Security\HtmlSanitizer::class));

        $this->templates->create([
            'title' => trim((string) ($decoded['title'] ?? 'Modèle importé')),
            'type' => (string) ($decoded['type'] ?? 'section'),
            'document_json' => json_encode($validated['data'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ]);

        return Response::redirect('/admin/prime-builder');
    }

    public function exportTemplate(Request $request): Response
    {
        $id = (int) $request->routeParam('id', 0);
        $tpl = $this->templates->find($id);
        if ($tpl === null) {
            return Response::json(['error' => 'Not found'], 404);
        }

        $payload = [
            'title' => $tpl['title'],
            'type' => $tpl['type'],
            'document' => json_decode((string) $tpl['document_json'], true),
        ];

        return Response::json($payload, 200, [
            'Content-Disposition' => 'attachment; filename="prime-builder-template-' . $id . '.json"',
        ]);
    }

    public function submissions(Request $request): Response
    {
        $formId = $request->query('form_id');
        $rows = $this->submissions->all(is_string($formId) && $formId !== '' ? $formId : null);

        return $this->renderAdmin('prime-builder::admin/submissions', [
            'title' => t('plugin::prime-builder.menu.submissions'),
            'submissions' => $rows,
        ]);
    }

    public function exportSubmissions(Request $request): Response
    {
        $formId = $request->query('form_id');
        $rows = $this->submissions->all(is_string($formId) && $formId !== '' ? $formId : null, 1000);
        $csv = SubmissionRepository::toCsv($rows);

        return new Response($csv, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="prime-builder-submissions.csv"',
        ]);
    }

    public function deleteSubmission(Request $request): Response
    {
        $id = (int) $request->routeParam('id', 0);
        if ($id > 0) {
            $this->submissions->delete($id);
        }

        return Response::redirect('/admin/prime-builder/submissions');
    }

    public function kit(Request $request): Response
    {
        $kitJson = (string) $this->context->settings()->get('kit', '{}');
        $kit = json_decode($kitJson, true);
        if (!is_array($kit)) {
            $kit = [];
        }

        return $this->renderAdmin('prime-builder::admin/kit', [
            'title' => t('plugin::prime-builder.menu.kit'),
            'kit' => $kit,
        ]);
    }

    public function saveKit(Request $request): Response
    {
        $kit = [
            'colors' => [
                'primary' => (string) $request->input('primary_color', '#ED6647'),
                'secondary' => (string) $request->input('secondary_color', '#1E293B'),
                'text' => (string) $request->input('text_color', '#334155'),
                'accent' => (string) $request->input('accent_color', '#C9441F'),
                'background' => (string) $request->input('background_color', '#FFFFFF'),
            ],
            'typography' => [
                'heading_font' => (string) $request->input('heading_font', 'Plus Jakarta Sans, sans-serif'),
                'body_font' => (string) $request->input('body_font', 'Inter, sans-serif'),
            ],
            'layout' => [
                'content_width' => max(600, min(1920, (int) $request->input('content_width', 1200))),
                'container_gap' => max(0, min(120, (int) $request->input('container_gap', 20))),
            ],
        ];

        $this->context->settings()->set('kit', json_encode($kit, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return Response::redirect('/admin/prime-builder/kit');
    }

    public function settings(Request $request): Response
    {
        return $this->renderAdmin('prime-builder::admin/settings', [
            'title' => t('plugin::prime-builder.settings.title'),
            'settings' => [
                'content_types' => (string) $this->context->settings()->get('content_types', 'page'),
                'breakpoint_tablet' => (int) $this->context->settings()->get('breakpoint_tablet', 1024),
                'breakpoint_mobile' => (int) $this->context->settings()->get('breakpoint_mobile', 767),
                'submission_retention_days' => (int) $this->context->settings()->get('submission_retention_days', 90),
            ],
        ]);
    }

    public function saveSettings(Request $request): Response
    {
        $this->context->settings()->set('content_types', (string) $request->input('content_types', 'page'));
        $this->context->settings()->set('breakpoint_tablet', max(480, min(1440, (int) $request->input('breakpoint_tablet', 1024))));
        $this->context->settings()->set('breakpoint_mobile', max(320, min(900, (int) $request->input('breakpoint_mobile', 767))));
        $this->context->settings()->set('submission_retention_days', max(1, min(3650, (int) $request->input('submission_retention_days', 90))));

        return Response::redirect('/admin/prime-builder/settings');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderAdmin(string $view, array $data): Response
    {
        $html = $this->context->views()->render($view, array_merge([
            'admin' => $this->admin,
            'pluginUrl' => $this->context->url(''),
        ], $data));

        return new Response($html);
    }
}
