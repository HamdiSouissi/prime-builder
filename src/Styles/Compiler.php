<?php

declare(strict_types=1);

namespace PrimeBuilder\Styles;

/** Compile only a small allowlist of CSS properties; never concatenate arbitrary CSS. */
final class Compiler
{
    /** @param array<string, mixed> $data */
    public function compile(array $data): string
    {
        $modes = ['desktop' => '', 'tablet' => '', 'mobile' => ''];
        $this->walk(is_array($data['elements'] ?? null) ? $data['elements'] : [], $modes);
        return $modes['desktop'] . ($modes['tablet'] !== '' ? '@media(max-width:1024px){' . $modes['tablet'] . '}' : '') . ($modes['mobile'] !== '' ? '@media(max-width:767px){' . $modes['mobile'] . '}' : '');
    }

    /** @param array<mixed> $elements @param array<string, string> $modes */
    private function walk(array $elements, array &$modes): void
    {
        foreach ($elements as $element) {
            if (!is_array($element) || !is_string($element['id'] ?? null) || preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $element['id']) !== 1) {
                continue;
            }
            $selector = '.pb-element[data-pb-id="' . $element['id'] . '"]';
            $settings = is_array($element['settings'] ?? null) ? $element['settings'] : [];
            if (($element['type'] ?? '') === 'container') {
                $layout = ($settings['layout'] ?? '') === 'grid' ? 'grid' : 'flex';
                $direction = ($settings['direction'] ?? '') === 'row' ? 'row' : 'column';
                $columns = max(1, min(6, (int) ($settings['columns'] ?? 1)));
                $modes['desktop'] .= $selector . '{display:' . $layout . ';' . ($layout === 'grid' ? 'grid-template-columns:repeat(' . $columns . ',minmax(0,1fr));' : 'flex-direction:' . $direction . ';') . '}';
            }
            $styles = is_array($element['styles'] ?? null) ? $element['styles'] : [];
            foreach ($modes as $mode => $_) {
                $values = is_array($styles[$mode] ?? null) ? $styles[$mode] : [];
                $declarations = '';
                foreach ($values as $key => $value) {
                    $declarations .= $this->declaration((string) $key, $value);
                }
                if ($declarations !== '') {
                    $modes[$mode] .= $selector . '{' . $declarations . '}';
                }
            }
            $this->walk(is_array($element['children'] ?? null) ? $element['children'] : [], $modes);
        }
    }

    private function declaration(string $key, mixed $value): string
    {
        $numeric = ['padding' => 'padding', 'margin' => 'margin', 'gap' => 'gap', 'font_size' => 'font-size', 'radius' => 'border-radius', 'min_height' => 'min-height'];
        if (isset($numeric[$key]) && is_int($value) && $value >= 0 && $value <= 2000) {
            return $numeric[$key] . ':' . $value . 'px;';
        }
        if (in_array($key, ['color', 'background'], true) && is_string($value) && preg_match('/^#[0-9a-fA-F]{3}(?:[0-9a-fA-F]{3})?$/', $value) === 1) {
            return ($key === 'background' ? 'background-color' : 'color') . ':' . $value . ';';
        }
        if ($key === 'align' && is_string($value) && in_array($value, ['left', 'center', 'right'], true)) {
            return 'text-align:' . $value . ';';
        }
        if ($key === 'width' && is_string($value) && preg_match('/^(?:100|[0-9]{1,2})(?:%|px)$/', $value) === 1) {
            return 'width:' . $value . ';';
        }
        return '';
    }
}
