<?php

declare(strict_types=1);

namespace PrimeBuilder\Widgets;

use Closure;

final class WidgetDefinition
{
    /**
     * @param list<string> $scripts
     * @param array<string, mixed> $defaults
     * @param Closure(array<string, mixed>, array<string, mixed>): string $renderer
     */
    public function __construct(
        public readonly string $type,
        public readonly string $title,
        public readonly string $category,
        public readonly string $icon,
        public readonly Closure $renderer,
        public readonly array $defaults = [],
        public readonly array $scripts = [],
        public readonly bool $isPro = false,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toMeta(): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'category' => $this->category,
            'icon' => $this->icon,
            'defaults' => $this->defaults,
            'scripts' => $this->scripts,
            'isPro' => $this->isPro,
        ];
    }
}
