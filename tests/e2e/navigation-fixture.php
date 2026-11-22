<?php

declare(strict_types=1);

// Test-only fixture for the disposable CMS: use the real menu repository and
// leave the theme's menu location untouched. The referenced entry is a draft.
use Prime\Site\Menu\MenuRepository;

$draft = $argv[1] ?? '';
if (!ctype_digit($draft) || (int) $draft < 1) {
    throw new RuntimeException('Invalid draft entry ID.');
}
$app = require getcwd() . '/bootstrap/app.php';
$menus = $app->get(MenuRepository::class);
$id = $menus->create('Navigation démonstration');
$menus->replaceItems($id, [
    ['type' => 'url', 'url' => '/', 'label' => 'Accueil'],
    ['type' => 'url', 'url' => '/decouvrir', 'label' => 'Découvrir', 'children' => [
        ['type' => 'url', 'url' => '/services', 'label' => 'Services', 'children' => [
            ['type' => 'url', 'url' => '/details', 'label' => 'Détails'],
        ]],
        ['type' => 'url', 'url' => '/test-prime-builder', 'label' => 'Page actuelle'],
    ]],
    ['type' => 'entry', 'target_id' => (int) $draft, 'label' => 'Brouillon confidentiel'],
    ['type' => 'url', 'url' => 'https://example.test/', 'label' => 'Externe', 'target' => '_blank'],
]);
echo "Navigation fixture prepared.\n";
