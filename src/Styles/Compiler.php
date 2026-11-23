<?php

declare(strict_types=1);

namespace PrimeBuilder\Styles;

/** Compile only a small allowlist of CSS properties; never concatenate arbitrary CSS. */
final class Compiler
{
    private readonly int $tablet;
    private readonly int $mobile;

    public function __construct(int $tablet = 1024, int $mobile = 767)
    {
        $this->tablet = max(600, min(2000, $tablet));
        $this->mobile = self::mobileBreakpoint($this->tablet, $mobile);
    }

    /** Match the responsive CSS and the navigation's JavaScript threshold. */
    public static function mobileBreakpoint(int $tablet, int $mobile): int
    {
        $tablet = max(600, min(2000, $tablet));
        return max(320, min($tablet - 1, $mobile));
    }

    /** @param array<string, mixed> $data */
    public function compile(array $data): string
    {
        $modes = ['desktop' => '', 'tablet' => '', 'mobile' => ''];
        $hidden = ['desktop' => '', 'tablet' => '', 'mobile' => ''];
        $this->walk(is_array($data['elements'] ?? null) ? $data['elements'] : [], $modes, $hidden);
        // Non-overlapping ranges preserve each element's original display type:
        // an explicit "show" does not have to guess whether it was flex or grid.
        // Negating max-width also covers fractional viewport sizes at zoom.
        return $modes['desktop']
            . ($modes['tablet'] !== '' ? '@media(max-width:' . $this->tablet . 'px){' . $modes['tablet'] . '}' : '')
            . ($modes['mobile'] !== '' ? '@media(max-width:' . $this->mobile . 'px){' . $modes['mobile'] . '}' : '')
            . ($hidden['desktop'] !== '' ? '@media not all and (max-width:' . $this->tablet . 'px){' . $hidden['desktop'] . '}' : '')
            . ($hidden['tablet'] !== '' ? '@media(max-width:' . $this->tablet . 'px){@media not all and (max-width:' . $this->mobile . 'px){' . $hidden['tablet'] . '}}' : '')
            . ($hidden['mobile'] !== '' ? '@media(max-width:' . $this->mobile . 'px){' . $hidden['mobile'] . '}' : '');
    }

    /**
     * @param array<mixed> $elements
     * @param array<string, string> $modes
     * @param array<string, string> $hidden
     */
    private function walk(array $elements, array &$modes, array &$hidden): void
    {
        foreach ($elements as $element) {
            if (!is_array($element) || !is_string($element['id'] ?? null) || preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $element['id']) !== 1) {
                continue;
            }
            $selector = '.pb-element[data-pb-id="' . $element['id'] . '"]';
            $settings = is_array($element['settings'] ?? null) ? $element['settings'] : [];
            if (($element['type'] ?? '') === 'navigation') {
                // The exact same bounded breakpoint is passed to matchMedia.
                // Without JS, the server-rendered links remain visible in this layout.
                $nav = $selector . ' > .pb-nav';
                $modes['mobile'] .= $nav . ' > .pb-nav__list{display:block;margin-top:.5rem}'
                    . $nav . ' .pb-nav__item{width:100%}';
            }
            if (($element['type'] ?? '') === 'container') {
                $layout = ($settings['layout'] ?? '') === 'grid' ? 'grid' : 'flex';
                $direction = ($settings['direction'] ?? '') === 'row' ? 'row' : 'column';
                $columns = max(1, min(6, (int) ($settings['columns'] ?? 1)));
                $modes['desktop'] .= $selector . '{display:' . $layout . ';' . ($layout === 'grid' ? 'grid-template-columns:repeat(' . $columns . ',minmax(0,1fr));' : 'flex-direction:' . $direction . ';') . '}';
            }
            $styles = is_array($element['styles'] ?? null) ? $element['styles'] : [];
            $visibility = 'show';
            foreach ($modes as $mode => $_) {
                $values = is_array($styles[$mode] ?? null) ? $styles[$mode] : [];
                if (in_array($values['visibility'] ?? null, ['show', 'hide'], true)) {
                    $visibility = $values['visibility'];
                }
                if ($visibility === 'hide') {
                    $hidden[$mode] .= $selector . '{display:none!important}';
                }
                $declarations = '';
                foreach ($values as $key => $value) {
                    $declarations .= $this->declaration((string) $key, $value);
                }
                if ($declarations !== '') {
                    $modes[$mode] .= $selector . '{' . $declarations . '}';
                    $inherit = Values::color($values['color'] ?? null) !== null ? 'color:inherit;' : '';
                    $inherit .= Values::length($values['font_size'] ?? null) !== null ? 'font-size:inherit;' : '';
                    if ($inherit !== '') {
                        $modes[$mode] .= $selector . ' :where(h1,h2,h3,h4,h5,h6,p,a,span,li,blockquote,cite,summary){' . $inherit . '}';
                    }
                }
            }
            $this->walk(is_array($element['children'] ?? null) ? $element['children'] : [], $modes, $hidden);
        }
    }

    private function declaration(string $key, mixed $value): string
    {
        $numeric = ['padding' => 'padding', 'margin' => 'margin', 'gap' => 'gap', 'font_size' => 'font-size', 'radius' => 'border-radius', 'min_height' => 'min-height'];
        if (isset($numeric[$key])) {
            $length = Values::length($value);
            return $length !== null ? $numeric[$key] . ':' . $length . ';' : '';
        }
        if (in_array($key, ['color', 'background'], true)) {
            $color = Values::color($value);
            return $color !== null ? ($key === 'background' ? 'background-color' : 'color') . ':' . $color . ';' : '';
        }
        if ($key === 'align' && is_string($value) && in_array($value, ['left', 'center', 'right'], true)) {
            return 'text-align:' . $value . ';';
        }
        if ($key === 'width') {
            $width = Values::length($value, true);
            return $width !== null ? 'width:' . $width . ';' : '';
        }
        if ($key === 'position' && is_string($value) && in_array($value, ['static', 'relative', 'absolute', 'fixed', 'sticky'], true)) {
            return 'position:' . $value . ';';
        }
        $offsets = ['offset_top' => 'top', 'offset_right' => 'right', 'offset_bottom' => 'bottom', 'offset_left' => 'left'];
        if (isset($offsets[$key])) {
            $offset = Values::offset($value);
            return $offset !== null ? $offsets[$key] . ':' . $offset . ';' : '';
        }
        if ($key === 'z_index') {
            $zIndex = Values::zIndex($value);
            return $zIndex !== null ? 'z-index:' . $zIndex . ';' : '';
        }
        if ($key === 'layout_mode' && is_string($value) && in_array($value, ['flex', 'grid'], true)) {
            return 'display:' . $value . ';';
        }
        if ($key === 'flow' && is_string($value) && in_array($value, ['row', 'column'], true)) {
            return 'flex-direction:' . $value . ';';
        }
        if ($key === 'grid_columns' && is_int($value) && $value >= 1 && $value <= 6) {
            return 'grid-template-columns:repeat(' . $value . ',minmax(0,1fr));';
        }
        if ($key === 'wrap' && is_string($value) && in_array($value, ['wrap', 'nowrap'], true)) {
            return 'flex-wrap:' . $value . ';';
        }
        if (in_array($key, ['justify', 'items_align'], true) && is_string($value)) {
            $allowed = $key === 'justify' ? ['start', 'center', 'end', 'space-between'] : ['start', 'center', 'end', 'stretch'];
            if (in_array($value, $allowed, true)) {
                $normalized = match ($value) {
                    'start' => 'flex-start', 'end' => 'flex-end', default => $value
                };
                return ($key === 'justify' ? 'justify-content:' : 'align-items:') . $normalized . ';';
            }
        }
        return '';
    }
}
