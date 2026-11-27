<?php

declare(strict_types=1);

namespace PrimeBuilder\Controller;

use Prime\Admin\AdminContext;
use Prime\Auth\Auth;
use Prime\Auth\User;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Plugins\PluginContext;
use Prime\Settings\Settings;
use Prime\Site\PageContext;
use Prime\Site\Zone\ZoneRepository;
use Prime\Themes\ThemeManager;
use PrimeBuilder\Configuration;
use PrimeBuilder\Document\HtmlContent;
use PrimeBuilder\Document\SavedTemplates;
use PrimeBuilder\Styles\Compiler;
use PrimeBuilder\Styles\Snapshots;
use PrimeBuilder\Styles\ZoneLayouts;
use PrimeBuilder\Theme\Models;
use PrimeBuilder\Theme\Partials;

/** Manage historical header/footer copies and shared theme stylesheets. */
final class ThemeBuilder
{
    private readonly Partials $partials;
    private readonly SavedTemplates $templates;

    public function __construct(private readonly PluginContext $plugin)
    {
        $this->partials = new Partials($plugin);
        $this->templates = new SavedTemplates($plugin->settings(), $plugin->app()->get(Settings::class));
    }

    public function invalidate(): void
    {
        $this->partials->invalidate();
    }

    public function show(Request $request): Response
    {
        $stored = $this->partials->stored();
        $values = [
            'header' => $stored['header']['id'] ?? '',
            'footer' => $stored['footer']['id'] ?? '',
            'exclude' => implode(', ', $stored['exclude']),
        ];
        return $this->form($request, $values);
    }

    public function save(Request $request): Response
    {
        $user = $this->plugin->app()->get(Auth::class)->user($request);
        if (!$user instanceof User || !$user->can('prime_builder.theme_builder')) {
            return Response::text('', 403);
        }
        $stored = $this->partials->stored();
        $values = [];
        $invalid = false;
        foreach (['header', 'footer', 'exclude'] as $name) {
            $value = $request->body($name);
            if (!is_string($value)) {
                $invalid = true;
            }
            $values[$name] = is_string($value) ? trim($value) : '';
        }
        $exclude = [];
        if (strlen($values['exclude']) > 2100) {
            $invalid = true;
        } elseif ($values['exclude'] !== '') {
            $parts = explode(',', $values['exclude']);
            if (count($parts) > 100) {
                $invalid = true;
            }
            foreach ($parts as $part) {
                $id = trim($part);
                if (preg_match('/^[1-9][0-9]{0,18}$/D', $id) !== 1 || (string) (int) $id !== $id) {
                    $invalid = true;
                    continue;
                }
                if (!in_array((int) $id, $exclude, true)) {
                    $exclude[] = (int) $id;
                }
            }
        }
        $available = $this->templates->all();
        $selected = ['header' => null, 'footer' => null];
        $needsHtml = false;
        foreach (['header', 'footer'] as $slot) {
            $id = $values[$slot];
            if ($id === '') {
                continue;
            }
            if (preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
                $invalid = true;
                continue;
            }
            $item = null;
            if (($stored[$slot]['id'] ?? null) === $id) {
                $item = $stored[$slot];
            } else {
                foreach ($available as $candidate) {
                    if ($candidate['id'] === $id) {
                        $item = $candidate;
                        break;
                    }
                }
            }
            if (!is_array($item) || !is_array($item['elements'] ?? null) || !is_string($item['name'] ?? null)) {
                $invalid = true;
                continue;
            }
            $layout = $this->partials->normalize($item['elements']);
            if ($layout === null) {
                $invalid = true;
                continue;
            }
            if (HtmlContent::contains($layout) && !$user->can('prime_builder.unfiltered_html')) {
                $needsHtml = true;
                continue;
            }
            $selected[$slot] = ['id' => $id, 'name' => $item['name'], 'elements' => $layout['elements']];
        }
        if ($needsHtml) {
            return $this->form($request, $values, 'needs_html', 403);
        }
        if ($invalid) {
            return $this->form($request, $values, 'invalid', 422);
        }
        // Copy the validated snapshots: later changes to the reusable library cannot
        // alter the site's theme without the theme capability.
        $this->plugin->settings()->set(Partials::SETTING, $selected + ['exclude' => $exclude], false);
        $this->plugin->app()->get(AdminContext::class)->flashToast(t('plugin::prime-builder.theme.saved'), 'success');
        return Response::redirect('/admin/prime-builder/theme');
    }

    /** A content-addressed public CSS snapshot contains styles only, never template text. */
    public function stylesheet(Request $request, string $file): Response
    {
        if (preg_match('/^[a-f0-9]{16}\.css$/D', $file) !== 1) {
            return Response::text('', 404);
        }
        $snapshots = new Snapshots($this->plugin->app()->storagePath('prime-builder/styles'));
        $css = $snapshots->read('theme-' . $file);
        $persisted = $css !== null;
        if ($css === null) {
            $page = new PageContext(type: PageContext::TYPE_NOT_FOUND);
            foreach (['header', 'footer'] as $slot) {
                $layout = $this->partials->layout($slot, $page);
                if ($layout === null) {
                    continue;
                }
                $candidate = (new Compiler((int) Configuration::get($this->plugin->settings(), 'breakpoint_tablet', 1024), (int) Configuration::get($this->plugin->settings(), 'breakpoint_mobile', 767)))->compile($layout, legacy: false);
                if (hash_equals(substr(hash('sha256', $candidate), 0, 16) . '.css', $file)) {
                    $css = $candidate;
                    $persisted = $snapshots->remember('theme-' . $file, $css);
                    break;
                }
            }
        }
        if ($css === null) {
            $compiler = new Compiler((int) Configuration::get($this->plugin->settings(), 'breakpoint_tablet', 1024), (int) Configuration::get($this->plugin->settings(), 'breakpoint_mobile', 767));
            foreach ($this->partials->modelLayouts() as $layout) {
                $candidate = $compiler->compile($layout, legacy: false);
                if (hash_equals(substr(hash('sha256', $candidate), 0, 16) . '.css', $file)) {
                    $css = $candidate;
                    $persisted = $snapshots->remember('theme-' . $file, $css);
                    break;
                }
            }
        }
        if ($css === null) {
            // If snapshot storage is unavailable, rebuild only a currently
            // declared zone whose content-addressed CSS matches the request.
            $zones = new ZoneLayouts($this->plugin->app()->get(ThemeManager::class), $this->plugin->app()->get(ZoneRepository::class));
            $compiler = new Compiler((int) Configuration::get($this->plugin->settings(), 'breakpoint_tablet', 1024), (int) Configuration::get($this->plugin->settings(), 'breakpoint_mobile', 767));
            foreach ($zones->all() as $layout) {
                $layout = $this->plugin->app()->get(\PrimeBuilder\Theme\GlobalWidgets::class)->expand($layout);
                $candidate = $compiler->compile($layout, legacy: false);
                if (hash_equals(substr(hash('sha256', $candidate), 0, 16) . '.css', $file)) {
                    $css = $candidate;
                    $persisted = $snapshots->remember('theme-' . $file, $css);
                    break;
                }
            }
        }
        if ($css === null) {
            return Response::text('', 404);
        }
        return new Response($css, 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => $persisted ? 'public, max-age=31536000, immutable' : 'no-store',
        ]);
    }

    /**
     * @param array<string, string> $values
     */
    private function form(Request $request, array $values, ?string $error = null, int $status = 200): Response
    {
        $choices = $this->templates->all();
        // A selected snapshot keeps working if its original library entry is deleted.
        foreach (['header', 'footer'] as $slot) {
            $item = $this->partials->stored()[$slot];
            if (!is_array($item)) {
                continue;
            }
            $exists = false;
            foreach ($choices as $choice) {
                if ($choice['id'] === $item['id']) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $choices[] = $item + ['kind' => 'section'];
            }
        }
        $filter = $request->query('kind', 'all');
        $filter = is_string($filter) && in_array($filter, ['all', ...Models::KINDS], true) ? $filter : 'all';
        $models = (new Models($this->plugin->settings(), $this->plugin->app()->get(Settings::class), $this->plugin))->all();
        if ($filter !== 'all') {
            $models = array_values(array_filter($models, static fn (array $model): bool => $model['kind'] === $filter));
        }
        $title = t('plugin::prime-builder.theme.title');
        $data = $this->plugin->app()->get(AdminContext::class)->build($request, $title, [
            'pageHeading' => $title,
            'breadcrumbs' => [
                ['label' => t('admin.nav.dashboard'), 'url' => '/admin'],
                ['label' => $title, 'url' => null],
            ],
            'values' => $values,
            'choices' => $choices,
            'sourceChoices' => $this->templates->all(),
            'models' => $models,
            'modelFilter' => $filter,
            'error' => $error,
        ]);
        if (is_array($data['adminStyles'] ?? null)) {
            $data['adminStyles'][] = $this->plugin->url('css/dashboard.css');
        }
        return Response::html($this->plugin->views()->render('prime-builder::admin/theme', $data), $status)
            ->withHeader('Cache-Control', 'no-store, private');
    }
}
