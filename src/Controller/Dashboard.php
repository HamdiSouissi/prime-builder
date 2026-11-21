<?php

declare(strict_types=1);

namespace PrimeBuilder\Controller;

use Prime\Admin\AdminContext;
use Prime\Auth\Auth;
use Prime\Content\ContentTypeRegistry;
use Prime\Hooks\Hooks;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Plugins\PluginContext;
use PrimeBuilder\Document\Library;
use PrimeBuilder\Widgets\Registry;

/** Administrative landing page; the CMS still owns its settings and content screens. */
final class Dashboard
{
    public function __construct(private readonly PluginContext $plugin)
    {
    }

    public function index(Request $request): Response
    {
        $user = $this->plugin->app()->get(Auth::class)->user($request);
        $types = [];
        foreach ($this->plugin->app()->get(ContentTypeRegistry::class)->all() as $type) {
            if ($user === null || !$user->can($type->capability('view'))) {
                continue;
            }
            $selected = $this->plugin->app()->get(Hooks::class)->apply('content.editor', $type->editor, $type);
            if ($selected === 'prime-builder') {
                $types[] = ['label' => $type->label('plural'), 'url' => $type->adminListUrl()];
            }
        }
        $title = t('plugin::prime-builder.menu');
        $data = $this->plugin->app()->get(AdminContext::class)->build($request, $title, [
            'pageHeading' => $title,
            'breadcrumbs' => [['label' => t('admin.nav.dashboard'), 'url' => '/admin'], ['label' => $title, 'url' => null]],
            'contentTypes' => $types,
            'canManageSettings' => $user !== null && $user->can('prime_builder.settings'),
            'canViewSystem' => $user !== null && $user->can('settings.manage'),
            'version' => $this->plugin->version(),
            'cmsVersion' => $this->plugin->app()->version(),
            'widgetCount' => count((new Registry($this->plugin->app()->get(Hooks::class)))->all()),
            'templateCount' => count((new Library($this->plugin->path('templates')))->all()),
        ]);
        if (is_array($data['adminStyles'] ?? null)) {
            $data['adminStyles'][] = $this->plugin->url('css/dashboard.css');
        }
        return Response::html($this->plugin->views()->render('prime-builder::admin/dashboard', $data))
            ->withHeader('Cache-Control', 'no-store, private');
    }
}
