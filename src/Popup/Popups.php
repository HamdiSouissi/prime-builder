<?php

declare(strict_types=1);

namespace PrimeBuilder\Popup;

use Prime\Plugins\PluginContext;
use Prime\Settings\Settings;
use Prime\Site\PageContext;
use PrimeBuilder\Theme\Models;
use PrimeBuilder\Theme\Partials;
use PrimeBuilder\Widgets\Renderer;

/** Resolve separately editable popup models without substituting theme content. */
final class Popups
{
    private readonly Models $models;
    private readonly Partials $partials;

    public function __construct(PluginContext $plugin)
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
            $elements = $model['elements'] ?? null;
            if (!is_array($elements)) {
                continue;
            }
            $layout = $this->partials->normalize($elements, contextual: true);
            if ($layout !== null) {
                $result[] = ['model' => $model, 'layout' => Partials::namespaceLayout($layout, Partials::modelSlot($model))];
            }
        }
        return $result;
    }

    /**
     * A dialog is closed in the HTML. Automatic triggers offer a non-modal,
     * dismissible invitation unless the visitor explicitly opted in for the tab.
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
        $notice = t('plugin::prime-builder.popup.available') . ' : ' . $name;
        $blocked = t('plugin::prime-builder.popup.frequency_blocked');
        $html = '<div class="pb-popup-root" data-pb-popup="' . $id . '"'
            . ' data-pb-trigger="' . self::escape($options['trigger']) . '" data-pb-delay="' . $options['delay'] . '"'
            . ' data-pb-scroll="' . $options['scroll'] . '" data-pb-idle="' . $options['idle'] . '"'
            . ' data-pb-frequency="' . self::escape($options['frequency']) . '" data-pb-days="' . $options['days'] . '"'
            . ' data-pb-available="' . self::escape($notice) . '" data-pb-blocked="' . self::escape($blocked) . '">'
            . '<span class="pb-popup-sr" id="pb-popup-blocked-' . $id . '">' . self::escape($blocked) . '</span>';
        $automaticChoice = '<label class="pb-popup-consent"><input type="checkbox" data-pb-popup-auto> ' . self::escape(t('plugin::prime-builder.popup.auto_opt_in')) . '</label>';
        if ($options['trigger'] !== 'click') {
            $html .= '<p class="pb-popup-sr" role="status" aria-live="polite" data-pb-popup-status></p>'
                . '<aside class="pb-popup-offer" aria-label="' . self::escape($notice) . '" data-pb-popup-offer hidden>'
                . '<p>' . self::escape($notice) . '</p>' . $automaticChoice . '<div class="pb-popup-offer__actions">'
                . '<button type="button" data-pb-popup-launch aria-haspopup="dialog" aria-controls="' . $dialog . '" aria-label="' . self::escape(t('plugin::prime-builder.popup.open') . ' : ' . $name) . '">' . self::escape(t('plugin::prime-builder.popup.open')) . '</button>'
                . '<button type="button" data-pb-popup-dismiss aria-label="' . self::escape(t('plugin::prime-builder.popup.dismiss') . ' : ' . $name) . '">' . self::escape(t('plugin::prime-builder.popup.dismiss')) . '</button></div></aside>';
        }
        $html .= '<dialog class="pb-popup-dialog" id="' . $dialog . '" aria-modal="true" aria-labelledby="' . $title . '">'
            . '<div class="pb-popup-dialog__header"><h2 id="' . $title . '">' . self::escape($name) . '</h2>'
            . '<button type="button" data-pb-popup-close aria-label="' . self::escape(t('plugin::prime-builder.popup.close') . ' : ' . $name) . '">' . self::escape(t('plugin::prime-builder.popup.close')) . '</button></div>'
            . $renderer->render($popup['layout']) . ($options['trigger'] !== 'click' ? $automaticChoice : '') . '</dialog></div>';
        return $html;
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
