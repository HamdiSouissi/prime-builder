<?php

declare(strict_types=1);

namespace PrimeBuilder\Widgets;

final class WidgetRegistry
{
    /** @var array<string, WidgetDefinition> */
    private array $widgets = [];

    public function register(WidgetDefinition $widget): void
    {
        $this->widgets[$widget->type] = $widget;
    }

    public function get(string $type): ?WidgetDefinition
    {
        return $this->widgets[$type] ?? null;
    }

    public function has(string $type): bool
    {
        return isset($this->widgets[$type]);
    }

    /**
     * @return list<string>
     */
    public function types(): array
    {
        return array_keys($this->widgets);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function metaList(): array
    {
        $list = [];
        foreach ($this->widgets as $widget) {
            $list[] = $widget->toMeta();
        }

        return $list;
    }
}
