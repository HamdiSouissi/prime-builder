<?php

declare(strict_types=1);

namespace PrimeBuilder\Controller;

use Prime\Admin\AdminContext;
use Prime\Cache\CacheManager;
use Prime\Content\ContentType;
use Prime\Http\Request;
use Prime\Http\Response;
use Prime\Plugins\PluginContext;
use PrimeBuilder\Configuration;
use PrimeBuilder\Forms\Submissions;
use Throwable;

/** The dedicated settings page is protected by the plugin's settings capability. */
final class Settings
{
    private const DEFAULTS = [
        'pages' => true,
        'posts' => false,
        'other_types' => '',
        'breakpoint_tablet' => 1024,
        'breakpoint_mobile' => 767,
        'kit_primary' => '#c9441f',
        'kit_text' => '#1a1d29',
        'kit_font_size' => 16,
        'submission_retention_days' => Submissions::RETENTION_DAYS,
    ];

    public function __construct(private readonly PluginContext $plugin)
    {
    }

    public function show(Request $request): Response
    {
        $values = [];
        foreach (self::DEFAULTS as $name => $default) {
            $value = Configuration::get($this->plugin->settings(), $name, $default);
            $values[$name] = $name === 'submission_retention_days'
                ? Submissions::retentionDays($value)
                : (is_scalar($value) ? $value : $default);
        }
        return $this->form($request, $values);
    }

    public function save(Request $request): Response
    {
        $values = [];
        $errors = [];
        foreach (self::DEFAULTS as $name => $default) {
            $raw = $request->body($name);
            if ($name === 'submission_retention_days' && $raw === null) {
                // A form opened before the upgrade has no retention field. Do not
                // overwrite an existing choice when that older form is submitted.
                $raw = (string) Submissions::retentionDays(Configuration::get($this->plugin->settings(), $name, $default));
            }
            if (is_bool($default)) {
                if ($raw !== null && $raw !== '1') {
                    $errors[$name] = true;
                }
                $values[$name] = $raw === '1';
            } else {
                if (!is_string($raw)) {
                    $errors[$name] = true;
                }
                $values[$name] = is_string($raw) ? trim($raw) : '';
            }
        }

        $other = $values['other_types'];
        if (strlen($other) > 500) {
            $errors['other_types'] = true;
        } elseif ($other !== '') {
            $names = array_map('trim', explode(',', $other));
            foreach ($names as $name) {
                if (preg_match(ContentType::NAME_PATTERN, $name) !== 1) {
                    $errors['other_types'] = true;
                }
            }
            $values['other_types'] = implode(', ', array_unique($names));
        }

        foreach (['breakpoint_tablet' => [600, 2000], 'breakpoint_mobile' => [320, 1600], 'kit_font_size' => [12, 28], 'submission_retention_days' => [1, Submissions::MAX_RETENTION_DAYS]] as $name => [$min, $max]) {
            $value = $values[$name];
            if (!is_string($value) || preg_match('/^[0-9]{1,4}$/D', $value) !== 1 || (int) $value < $min || (int) $value > $max) {
                $errors[$name] = true;
            } else {
                $values[$name] = (int) $value;
            }
        }
        if (!isset($errors['breakpoint_mobile']) && !isset($errors['breakpoint_tablet']) && is_int($values['breakpoint_mobile']) && is_int($values['breakpoint_tablet']) && $values['breakpoint_mobile'] >= $values['breakpoint_tablet']) {
            $errors['breakpoint_mobile'] = true;
        }
        foreach (['kit_primary', 'kit_text'] as $name) {
            $color = $values[$name];
            if (preg_match('/^#[a-f0-9]{3}(?:[a-f0-9]{3})?$/iD', $color) !== 1) {
                $errors[$name] = true;
            } else {
                $values[$name] = strtolower($color);
            }
        }
        if ($errors !== []) {
            return $this->form($request, $values, $errors);
        }

        // One CMS setting avoids a series of pre-commit cache mutations and hook emissions.
        Configuration::save($this->plugin->settings(), $values);
        try {
            $this->plugin->app()->get(CacheManager::class)->clearPages();
        } catch (Throwable) {
            // Settings are saved; caches may also be purged manually if storage is unavailable.
        }
        $this->plugin->app()->get(AdminContext::class)->flashToast(t('plugin::prime-builder.settings.saved'), 'success');
        return Response::redirect('/admin/prime-builder/settings');
    }

    /**
     * @param array<string, bool|int|float|string> $values
     * @param array<string, bool>                  $errors
     */
    private function form(Request $request, array $values, array $errors = []): Response
    {
        $title = t('plugin::prime-builder.settings.page_title');
        $data = $this->plugin->app()->get(AdminContext::class)->build($request, $title, [
            'pageHeading' => $title,
            'breadcrumbs' => [
                ['label' => t('admin.nav.dashboard'), 'url' => '/admin'],
                ['label' => t('plugin::prime-builder.menu'), 'url' => '/admin/prime-builder'],
                ['label' => $title, 'url' => null],
            ],
            'values' => $values,
            'errors' => $errors,
        ]);
        if (is_array($data['adminStyles'] ?? null)) {
            $data['adminStyles'][] = $this->plugin->url('css/dashboard.css');
        }
        return Response::html($this->plugin->views()->render('prime-builder::admin/settings', $data), $errors === [] ? 200 : 422)
            ->withHeader('Cache-Control', 'no-store, private');
    }
}
