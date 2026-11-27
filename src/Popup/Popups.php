<?php

declare(strict_types=1);

namespace PrimeBuilder\Popup;

use Prime\Auth\Auth;
use Prime\Plugins\PluginContext;
use Prime\Settings\Settings;
use Prime\Site\PageContext;
use PrimeBuilder\Theme\GlobalWidgets;
use PrimeBuilder\Theme\Models;
use PrimeBuilder\Theme\PageRequest;
use PrimeBuilder\Theme\Partials;
use PrimeBuilder\Widgets\Renderer;

/** Resolve separately editable popup models without substituting theme content. */
final class Popups
{
    private readonly Models $models;
    private readonly Partials $partials;

    public function __construct(private readonly PluginContext $plugin)
    {
        $this->models = new Models($plugin->settings(), $plugin->app()->get(Settings::class));
        $this->partials = new Partials($plugin);
    }

    public function invalidate(): void
    {
        $this->models->invalidate();
    }

    /**
     * Never expose popups on private preview canvases. Conditions and exclusions
     * are shared with theme models; every matching popup is independently shown.
     *
     * @return list<array{model: array<string, mixed>, layout: array<string, mixed>}>
     */
    public function matching(PageContext $page): array
    {
        if ($page->isPreview || $page->isCustomizerPreview || $page->isRedirect()) {
            return [];
        }
        $result = [];
        foreach ($this->models->matching('popup', $page) as $model) {
            $options = Options::normalize($model['popup'] ?? null);
            if ($options === null) {
                continue;
            }
            if ($options['hide_logged_in']) {
                $app = $this->plugin->app();
                $request = $app->get(PageRequest::class)->request($page);
                $auth = $app->get(Auth::class);
                $session = $app->get(\Prime\Http\Session::class);
                // Do not start a new session for a cookie-free visitor: that
                // would make an otherwise anonymous page uncacheable.
                $mayBeAuthenticated = $request === null || $session->isStarted()
                    || ($request->cookie($session->name()) ?? '') !== ''
                    || ($request->cookie($auth->rememberCookieName()) ?? '') !== ''
                    || $request->header('authorization') !== null;
                if ($mayBeAuthenticated) {
                    $user = $auth->user($request);
                    if ($user !== null && $user->isActive()) {
                        continue;
                    }
                }
            }
            $elements = $model['elements'] ?? null;
            if (!is_array($elements)) {
                continue;
            }
            $layout = $this->partials->normalize($elements, contextual: true);
            if ($layout !== null) {
                $result[] = ['model' => $model, 'layout' => $this->plugin->app()->get(GlobalWidgets::class)->expand(Partials::namespaceLayout($layout, Partials::modelSlot($model)))];
            }
        }
        return $result;
    }

    /**
     * Dialogs are initially closed; the browser script opens automatic triggers
     * directly using the native modal API.
     *
     * @param array{model: array<string, mixed>, layout: array<string, mixed>} $popup
     */
    public function render(array $popup, Renderer $renderer): string
    {
        $model = $popup['model'];
        $id = (string) $model['id'];
        $name = (string) $model['name'];
        $options = Options::normalize($model['popup'] ?? null);
        if ($options === null || preg_match('/^[a-f0-9]{32}$/D', $id) !== 1) {
            return '';
        }
        $title = 'pb-popup-title-' . $id;
        $dialog = 'pb-popup-' . $id;
        $blocked = t('plugin::prime-builder.popup.frequency_blocked');
        $html = '<div class="pb-popup-root" data-pb-popup="' . $id . '"'
            . ' data-pb-trigger="' . self::escape($options['trigger']) . '" data-pb-delay="' . $options['delay'] . '"'
            . ' data-pb-scroll="' . $options['scroll'] . '" data-pb-idle="' . $options['idle'] . '"'
            . ' data-pb-frequency="' . self::escape($options['frequency']) . '" data-pb-days="' . $options['days'] . '"'
            . ' data-pb-blocked="' . self::escape($blocked) . '">'
            . '<span class="pb-popup-sr" id="pb-popup-blocked-' . $id . '">' . self::escape($blocked) . '</span>'
            . '<p class="pb-popup-sr" role="status" aria-live="polite" data-pb-popup-status></p>';
        $html .= '<dialog class="pb-popup-dialog" id="' . $dialog . '" aria-modal="true" aria-labelledby="' . $title . '">'
            . '<div class="pb-popup-dialog__header"><h2 id="' . $title . '">' . self::escape($name) . '</h2>'
            . '<button type="button" data-pb-popup-close aria-label="' . self::escape(t('plugin::prime-builder.popup.close') . ' : ' . $name) . '">' . self::escape(t('plugin::prime-builder.popup.close')) . '</button></div>'
            . $renderer->render($popup['layout']) . '</dialog></div>';
        return $html;
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
