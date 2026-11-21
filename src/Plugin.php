<?php

declare(strict_types=1);

namespace PrimeBuilder;

use Prime\Admin\AdminContext;
use Prime\Admin\Menu\AdminMenu;
use Prime\Admin\Menu\MenuItem;
use Prime\Admin\Notice;
use Prime\Content\ContentType;
use Prime\Editor\BlockDefinition;
use Prime\Http\Router;
use Prime\Jobs\JobHandlerRegistry;
use Prime\Plugins\AbstractPlugin;
use Prime\Plugins\PluginContext;
use Prime\Scheduler\Schedule;
use Prime\Security\HtmlSanitizer;
use Prime\Themes\ThemeAssetRegistry;
use PrimeBuilder\Api\BuilderApi;
use PrimeBuilder\Controller\AdminController;
use PrimeBuilder\Controller\EditorController;
use PrimeBuilder\Controller\PublicController;
use PrimeBuilder\Document\DocumentValidator;
use PrimeBuilder\Forms\SubmissionRepository;
use PrimeBuilder\Jobs\FormEmailJob;
use PrimeBuilder\Render\Renderer;
use PrimeBuilder\Style\CssCompiler;
use PrimeBuilder\Templates\TemplateRepository;
use PrimeBuilder\Widgets\BuiltinWidgets;
use PrimeBuilder\Widgets\WidgetRegistry;

final class Plugin extends AbstractPlugin
{
    public const SLUG = 'prime-builder';
    public const EDITOR = 'prime-builder';
    public const BLOCK_LAYOUT = 'prime-builder/layout';

    public const CAP_EDIT = 'prime_builder.edit';
    public const CAP_TEMPLATES = 'prime_builder.templates';
    public const CAP_THEME_BUILDER = 'prime_builder.theme_builder';
    public const CAP_FORMS = 'prime_builder.forms';
    public const CAP_SETTINGS = 'prime_builder.settings';
    public const CAP_UNFILTERED_HTML = 'prime_builder.unfiltered_html';

    public const JOB_FORM_EMAIL = 'prime-builder.form-email';
    public const TASK_PURGE_SUBMISSIONS = 'prime-builder.purge-submissions';

    public function register(PluginContext $context): void
    {
        // 1. Capabilities
        $context->capabilities()->register(self::CAP_EDIT, 'plugin::prime-builder.capabilities.edit');
        $context->capabilities()->register(self::CAP_TEMPLATES, 'plugin::prime-builder.capabilities.templates');
        $context->capabilities()->register(self::CAP_THEME_BUILDER, 'plugin::prime-builder.capabilities.theme_builder');
        $context->capabilities()->register(self::CAP_FORMS, 'plugin::prime-builder.capabilities.forms');
        $context->capabilities()->register(self::CAP_SETTINGS, 'plugin::prime-builder.capabilities.settings');
        $context->capabilities()->register(self::CAP_UNFILTERED_HTML, 'plugin::prime-builder.capabilities.unfiltered_html');

        // 2. Widget registry + third-party filter
        $widgets = new WidgetRegistry();
        BuiltinWidgets::registerAll($widgets);
        $filteredWidgets = $context->hooks()->apply('prime_builder.widgets', $widgets);
        if ($filteredWidgets instanceof WidgetRegistry) {
            $widgets = $filteredWidgets;
        }

        // 3. Container services
        $container = $context->app()->container();
        $container->singleton(WidgetRegistry::class, static fn (): WidgetRegistry => $widgets);
        $container->singleton(TemplateRepository::class, static fn (): TemplateRepository => new TemplateRepository($context->db()));
        $container->singleton(SubmissionRepository::class, static fn (): SubmissionRepository => new SubmissionRepository($context->db()));
        $container->singleton(AdminController::class, static fn (): AdminController => new AdminController(
            $context,
            $context->app()->get(AdminContext::class),
            $context->app()->get(TemplateRepository::class),
            $context->app()->get(SubmissionRepository::class),
            $widgets,
        ));
        $container->singleton(EditorController::class, static fn (): EditorController => new EditorController($context, $widgets));
        $container->singleton(PublicController::class, static fn (): PublicController => new PublicController($context));
        $container->singleton(BuilderApi::class, static fn (): BuilderApi => new BuilderApi(
            $context,
            $widgets,
            $context->app()->get(TemplateRepository::class),
            $context->app()->get(SubmissionRepository::class),
        ));

        // 4. Admin menu & assets
        $context->hooks()->filter('admin.menu', static function (mixed $menu): mixed {
            if ($menu instanceof AdminMenu) {
                $menu->add(new MenuItem(
                    id: 'plugin.prime-builder',
                    label: 'plugin::prime-builder.menu.title',
                    icon: 'layout',
                    url: '/admin/prime-builder',
                    position: 55,
                    accent: 'brand',
                    capability: self::CAP_TEMPLATES,
                    group: 'admin.menu.groups.content',
                ));
            }

            return $menu;
        });

        $context->hooks()->filter('admin.assets', static function (mixed $assets) use ($context): mixed {
            if (is_array($assets) && isset($assets['css']) && is_array($assets['css'])) {
                $assets['css'][] = $context->url('css/admin.css');
            }

            return $assets;
        });

        // 5. Admin routes
        $context->router()->adminGroup(static function (Router $r): void {
            $r->get('/', [AdminController::class, 'templates'])->name('templates');
            $r->post('/templates', [AdminController::class, 'createTemplate'])->name('templates.create');
            $r->post('/templates/{id:\d+}/delete', [AdminController::class, 'deleteTemplate'])->name('templates.delete');
            $r->post('/templates/import', [AdminController::class, 'importTemplate'])->name('templates.import');
            $r->get('/templates/{id:\d+}/export', [AdminController::class, 'exportTemplate'])->name('templates.export');
            $r->get('/submissions', [AdminController::class, 'submissions'])->name('submissions');
            $r->get('/submissions/export', [AdminController::class, 'exportSubmissions'])->name('submissions.export');
            $r->post('/submissions/{id:\d+}/delete', [AdminController::class, 'deleteSubmission'])->name('submissions.delete');
            $r->get('/kit', [AdminController::class, 'kit'])->name('kit');
            $r->post('/kit', [AdminController::class, 'saveKit'])->name('kit.save');
            $r->get('/settings', [AdminController::class, 'settings'])->name('settings');
            $r->post('/settings', [AdminController::class, 'saveSettings'])->name('settings.save');
            $r->get('/edit/{id:\d+}', [EditorController::class, 'edit'])->name('edit');
        }, capability: self::CAP_EDIT);
        $context->setSettingsUrl('/admin/prime-builder/settings');

        // 6. Public CSS route
        $context->router()->frontGroup(static function (Router $r): void {
            $r->get('/prime-builder/css/{file}', [PublicController::class, 'css'])->name('css');
        });

        $context->hooks()->on('site.assets', static function (mixed $assets) use ($context): void {
            if ($assets instanceof ThemeAssetRegistry) {
                $compiler = new CssCompiler();
                $kit = json_decode((string) $context->settings()->get('kit', '{}'), true);
                $hash = $compiler->hash($compiler->compileKit(is_array($kit) ? $kit : []));
                $assets->style('prime-builder-public', $context->url('css/public.css'));
                $assets->style('prime-builder-kit', '/prime-builder/css/kit-' . $hash . '.css');
            }
        });

        // 7. REST API
        $context->api()->patch('/documents/{id:\d+}', [BuilderApi::class, 'saveDocument'], permission: self::CAP_EDIT);
        $context->api()->get('/templates', [BuilderApi::class, 'listTemplates'], permission: self::CAP_TEMPLATES);
        $context->api()->post('/forms/{id}/submit', [BuilderApi::class, 'submitForm'], public: true);

        // 8. Prime Builder layout block
        $context->blocks()->register(new BlockDefinition(
            type: self::BLOCK_LAYOUT,
            title: 'plugin::prime-builder.blocks.layout.title',
            description: 'plugin::prime-builder.blocks.layout.description',
            icon: 'layout',
            category: 'layout',
            validator: static function (array $data, HtmlSanitizer $sanitizer) use ($widgets): array {
                $validator = new DocumentValidator($widgets->types());

                return $validator->validate($data, $sanitizer);
            },
            renderer: static function (array $data) use ($widgets): string {
                $renderer = new Renderer($widgets);

                return $renderer->render($data);
            },
            textExtractor: static function (array $data): string {
                $json = json_encode($data, JSON_UNESCAPED_UNICODE);

                return is_string($json) ? trim(strip_tags($json)) : '';
            },
            keywords: ['prime-builder', 'layout', 'page'],
        ));

        // 9. Alternative editor
        $context->editors()->register(
            name: self::EDITOR,
            label: 'plugin::prime-builder.editor.label',
            render: static fn (array $state): string => $context->views()->render('prime-builder::editor/bridge', [
                'entry' => $state['entry'] ?? null,
                'contentJson' => $state['contentJson'],
            ]),
            scripts: [],
            styles: ['css/admin.css'],
        );

        $context->hooks()->filter('content.editor', static function (mixed $editor, mixed $type = null) use ($context): mixed {
            $raw = (string) $context->settings()->get('content_types', 'page');
            $allowed = array_filter(array_map('trim', explode(',', $raw)));
            if ($type instanceof ContentType && in_array($type->name, $allowed, true)) {
                return self::EDITOR;
            }

            return $editor;
        });

        // 10. Jobs & Scheduler
        $context->hooks()->filter('jobs.register_handlers', static function (mixed $registry) use ($context): mixed {
            if ($registry instanceof JobHandlerRegistry) {
                $registry->register(self::JOB_FORM_EMAIL, new FormEmailJob($context));
            }

            return $registry;
        });

        $context->hooks()->filter('scheduler.register', static function (mixed $schedule) use ($context): mixed {
            if ($schedule instanceof Schedule) {
                $schedule->call(
                    name: self::TASK_PURGE_SUBMISSIONS,
                    action: static function () use ($context): void {
                        $days = max(1, (int) $context->settings()->get('submission_retention_days', 90));
                        $context->app()->get(SubmissionRepository::class)->purgeOlderThanDays($days);
                    },
                    expression: '0 3 * * *',
                    description: 'Purge expired Prime Builder form submissions',
                    owner: self::SLUG,
                );
            }

            return $schedule;
        });

        // 11. System health check
        $context->hooks()->filter('system.checks', static function (mixed $checks) use ($widgets): mixed {
            if (is_array($checks)) {
                $checks[] = [
                    'label' => t('plugin::prime-builder.system.check_label'),
                    'status' => 'ok',
                    'detail' => t('plugin::prime-builder.system.check_ok', ['count' => count($widgets->types())]),
                ];
            }

            return $checks;
        });
    }

    public function activate(PluginContext $context): void
    {
        $context->notices()->add(
            id: 'welcome',
            type: 'info',
            title: 'plugin::prime-builder.menu.title',
            message: 'plugin::prime-builder.notices.welcome',
        );
    }
}
