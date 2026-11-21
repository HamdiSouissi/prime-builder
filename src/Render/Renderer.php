<?php

declare(strict_types=1);

namespace PrimeBuilder\Render;

use PrimeBuilder\Dynamic\DisplayConditions;
use PrimeBuilder\Widgets\WidgetRegistry;
use Throwable;

final class Renderer
{
    /** @var list<string> */
    private array $requiredScripts = [];

    public function __construct(
        private readonly WidgetRegistry $registry,
    ) {
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $context
     */
    public function render(array $document, array $context = []): string
    {
        $this->requiredScripts = [];
        $elements = is_array($document['elements'] ?? null) ? $document['elements'] : [];

        return '<div class="pb-root">' . $this->renderElements($elements, $context) . '</div>';
    }

    /**
     * @return list<string>
     */
    public function requiredScripts(): array
    {
        return array_values(array_unique($this->requiredScripts));
    }

    /**
     * @param array<mixed> $elements
     * @param array<string, mixed> $context
     */
    private function renderElements(array $elements, array $context): string
    {
        $html = '';
        foreach ($elements as $el) {
            if (!is_array($el)) {
                continue;
            }
            $settings = is_array($el['settings'] ?? null) ? $el['settings'] : [];
            if (!DisplayConditions::shouldRender($settings, $context)) {
                continue;
            }

            $id = htmlspecialchars((string) ($el['id'] ?? ''), ENT_QUOTES, 'UTF-8');
            $elType = (string) ($el['elType'] ?? 'widget');
            $customClass = htmlspecialchars(preg_replace('/[^a-zA-Z0-9 _-]/', '', (string) ($settings['css_classes'] ?? '')) ?? '', ENT_QUOTES, 'UTF-8');
            $customId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($settings['css_id'] ?? '')) ?? '';
            $idAttr = $customId !== '' ? ' id="' . htmlspecialchars($customId, ENT_QUOTES, 'UTF-8') . '"' : '';

            if ($elType === 'container') {
                $layout = ($settings['layout_type'] ?? 'flex') === 'grid' ? 'pb-container--grid' : 'pb-container--flex';
                $boxed = ($settings['content_width'] ?? 'boxed') === 'full' ? 'pb-container--full' : 'pb-container--boxed';
                $children = is_array($el['elements'] ?? null) ? $this->renderElements($el['elements'], $context) : '';
                $html .= "<div{$idAttr} class=\"pb-container {$layout} {$boxed} {$customClass}\" data-pb-id=\"{$id}\">{$children}</div>";
                continue;
            }

            $widgetType = (string) ($el['widgetType'] ?? '');
            $widget = $this->registry->get($widgetType);
            if ($widget === null) {
                continue;
            }

            foreach ($widget->scripts as $script) {
                $this->requiredScripts[] = $script;
            }

            try {
                $inner = ($widget->renderer)($settings, $context);
            } catch (Throwable) {
                $inner = '';
            }

            $html .= "<div{$idAttr} class=\"pb-element pb-element--{$widgetType} {$customClass}\" data-pb-id=\"{$id}\">{$inner}</div>";
        }

        return $html;
    }
}
