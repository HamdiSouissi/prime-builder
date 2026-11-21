<?php

declare(strict_types=1);

namespace PrimeBuilder\Widgets;

use Prime\Log\Logger;
use Prime\Security\HtmlSanitizer;
use Throwable;

/** Render safe, useful static HTML so saved pages remain readable without this plugin. */
final class Renderer
{
    public function __construct(private readonly Registry $registry, private readonly HtmlSanitizer $sanitizer, private readonly ?Logger $logger = null)
    {
    }

    /** @param array<string, mixed> $data */
    public function render(array $data): string
    {
        $elements = is_array($data['elements'] ?? null) ? $data['elements'] : [];
        return '<div class="pb-layout">' . $this->renderElements($elements, $this->registry->all()) . '</div>';
    }

    /** @param array<mixed> $elements @param array<string, array<string, mixed>> $definitions */
    private function renderElements(array $elements, array $definitions): string
    {
        $html = '';
        foreach ($elements as $element) {
            if (!is_array($element)) {
                continue;
            }
            $type = is_string($element['type'] ?? null) ? $element['type'] : '';
            $id = is_string($element['id'] ?? null) ? $element['id'] : '';
            $settings = is_array($element['settings'] ?? null) ? $element['settings'] : [];
            $children = is_array($element['children'] ?? null) ? $element['children'] : [];
            if (!isset($definitions[$type]) || preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $id) !== 1) {
                continue;
            }
            try {
                $content = $this->renderWidget($type, $settings, $definitions[$type]);
                if (($definitions[$type]['container'] ?? false) === true) {
                    $content .= $this->renderElements($children, $definitions);
                }
                $html .= '<div class="pb-element pb-' . self::escape($type) . '" data-pb-id="' . self::escape($id) . '">' . $content . '</div>';
            } catch (Throwable $exception) {
                $this->logger?->error('Prime Builder widget failed', ['widget' => $type, 'message' => $exception->getMessage()]);
            }
        }
        return $html;
    }

    /** @param array<string, mixed> $settings @param array<string, mixed> $definition */
    private function renderWidget(string $type, array $settings, array $definition): string
    {
        $text = self::escape(is_string($settings['text'] ?? null) ? $settings['text'] : '');
        $rich = $this->sanitizer->sanitizeBasic(is_string($settings['text'] ?? null) ? $settings['text'] : '');
        $url = is_string($settings['url'] ?? null) ? $settings['url'] : '';
        $href = $this->sanitizer->sanitizeHref($url) ?? '';
        $resource = $this->sanitizer->sanitizeResourceUrl($url) ?? '';
        $level = max(1, min(6, (int) ($settings['level'] ?? 2)));
        $width = max(1, min(10000, (int) ($settings['width'] ?? 800)));
        $height = max(1, min(10000, (int) ($settings['height'] ?? 600)));
        $value = max(0, min(100, (int) ($settings['value'] ?? 0)));
        $callback = $definition['render'] ?? null;
        if (is_callable($callback)) {
            // Extension code is trusted, but its returned user HTML still goes through the sanitizer.
            $result = $callback($settings, $this->sanitizer);
            return is_string($result) ? $this->sanitizer->sanitizeBasic($result) : '';
        }
        return match ($type) {
            'container', 'spacer' => '',
            'heading' => '<h' . $level . '>' . $text . '</h' . $level . '>',
            'text' => $rich,
            'image' => $resource !== '' ? '<img src="' . self::escape($resource) . '" alt="' . self::escape((string) ($settings['alt'] ?? '')) . '" width="' . $width . '" height="' . $height . '" loading="lazy" decoding="async">' : '',
            'button' => $href !== '' ? '<a class="pb-button-link" href="' . self::escape($href) . '">' . $text . '</a>' : '',
            'separator' => '<hr>',
            'quote' => '<blockquote>' . $rich . (isset($settings['author']) && $settings['author'] !== '' ? '<cite>' . self::escape((string) $settings['author']) . '</cite>' : '') . '</blockquote>',
            'alert' => '<div class="pb-alert-content">' . $rich . '</div>',
            'progress' => '<label>' . $text . ' <progress max="100" value="' . $value . '">' . $value . '%</progress></label>',
            'anchor' => isset($settings['anchor']) && preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/', (string) $settings['anchor']) === 1 ? '<span id="' . self::escape((string) $settings['anchor']) . '"></span>' : '',
            default => '',
        };
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }
}
