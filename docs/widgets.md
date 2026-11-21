# Guide développeur — Ajouter un widget à Prime Builder

Un plugin tiers peut enregistrer ses propres widgets via le filtre `prime_builder.widgets` :

```php
use PrimeBuilder\Widgets\WidgetDefinition;
use PrimeBuilder\Widgets\WidgetRegistry;

$context->hooks()->filter('prime_builder.widgets', static function (mixed $registry): mixed {
    if ($registry instanceof WidgetRegistry) {
        $registry->register(new WidgetDefinition(
            type: 'mon-plugin/badge',
            title: 'Badge',
            category: 'basic',
            icon: 'tag',
            defaults: ['text' => 'Nouveau'],
            renderer: static fn (array $s): string => '<span class="pb-badge">' . htmlspecialchars((string) ($s['text'] ?? ''), ENT_QUOTES, 'UTF-8') . '</span>',
        ));
    }

    return $registry;
});
```
