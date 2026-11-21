<?php

declare(strict_types=1);

/** @var \Prime\Content\Entry $entry */
/** @var string $configJson */
/** @var string $cssUrl */
/** @var string $jsUrl */
?>
<!doctype html>
<html lang="<?= $this->e(current_locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= $this->e(csrf_token()) ?>">
    <title><?= $this->e(t('plugin::prime-builder.ui.title')) ?> — <?= $this->e($entry->title) ?></title>
    <link rel="stylesheet" href="<?= $this->e($cssUrl) ?>">
    <script type="module" src="<?= $this->e($jsUrl) ?>"></script>
</head>
<body>
<a class="pb-skip" href="#pb-canvas"><?= $this->e(t('plugin::prime-builder.ui.skip')) ?></a>
<div class="pb-app" data-pb-app>
    <textarea hidden data-pb-config><?= $this->e($configJson) ?></textarea>
    <header class="pb-topbar">
        <div class="pb-brand"><span class="pb-brand-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M4 5h16v4H4zm0 7h7v7H4zm10 0h6v3h-6zm0 5h6v2h-6z" fill="currentColor"/></svg></span><span><strong><?= $this->e(t('plugin::prime-builder.menu')) ?></strong><small><?= $this->e($entry->title) ?></small></span></div>
        <div class="pb-top-actions"><span class="pb-save-state" data-pb-save-state aria-live="polite"></span><a class="pb-button pb-button-quiet" href="<?= $this->e($entry->editUrl()) ?>"><?= $this->e(t('plugin::prime-builder.ui.back')) ?></a><button type="button" class="pb-button pb-button-primary" data-pb-save><?= $this->e(t('plugin::prime-builder.ui.save')) ?></button></div>
    </header>
    <div class="pb-workspace">
        <aside class="pb-sidebar" aria-label="<?= $this->e(t('plugin::prime-builder.ui.sidebar')) ?>">
            <div class="pb-panel-head"><div><span class="pb-eyebrow"><?= $this->e(t('plugin::prime-builder.ui.eyebrow')) ?></span><h1><?= $this->e(t('plugin::prime-builder.ui.title')) ?></h1></div><span class="pb-panel-dot" aria-hidden="true"></span></div>
            <nav class="pb-panel-nav" aria-label="<?= $this->e(t('plugin::prime-builder.ui.panels')) ?>"><button type="button" class="pb-nav-active" data-pb-panel="widgets" aria-pressed="true"><?= $this->e(t('plugin::prime-builder.ui.widgets')) ?></button><button type="button" data-pb-panel="navigator" aria-pressed="false"><?= $this->e(t('plugin::prime-builder.ui.navigator')) ?></button><button type="button" data-pb-panel="library" aria-pressed="false"><?= $this->e(t('plugin::prime-builder.ui.library')) ?></button></nav>
            <section class="pb-panel-content" data-pb-widget-panel>
                <label class="pb-search"><span aria-hidden="true">⌕</span><span class="pb-sr-only"><?= $this->e(t('plugin::prime-builder.ui.search')) ?></span><input type="search" data-pb-search placeholder="<?= $this->e(t('plugin::prime-builder.ui.search')) ?>"></label>
                <p class="pb-intro"><?= $this->e(t('plugin::prime-builder.ui.intro')) ?></p>
                <div data-pb-widget-list></div>
            </section>
            <section class="pb-panel-content" data-pb-navigator-panel hidden>
                <h2><?= $this->e(t('plugin::prime-builder.ui.navigator')) ?></h2>
                <p class="pb-intro"><?= $this->e(t('plugin::prime-builder.ui.navigator_help')) ?></p>
                <div data-pb-tree></div>
            </section>
            <section class="pb-panel-content" data-pb-library-panel hidden>
                <h2><?= $this->e(t('plugin::prime-builder.ui.library')) ?></h2>
                <p class="pb-intro"><?= $this->e(t('plugin::prime-builder.ui.library_intro')) ?></p>
                <div class="pb-library-actions"><label class="pb-library-import"><?= $this->e(t('plugin::prime-builder.ui.import')) ?><input type="file" accept="application/json,.json" data-pb-import></label><button type="button" data-pb-export><?= $this->e(t('plugin::prime-builder.ui.export')) ?></button></div>
                <p class="pb-intro"><?= $this->e(t('plugin::prime-builder.ui.import_help')) ?></p>
                <div data-pb-library-list></div>
            </section>
            <section class="pb-panel-content" data-pb-settings-panel hidden>
                <div class="pb-selected-heading"><div><span class="pb-eyebrow"><?= $this->e(t('plugin::prime-builder.ui.edit_element')) ?></span><h2 data-pb-selected-title></h2></div><button type="button" class="pb-icon-button" data-pb-close-settings aria-label="<?= $this->e(t('plugin::prime-builder.ui.widgets')) ?>">×</button></div>
                <div class="pb-settings-tabs" role="group" aria-label="<?= $this->e(t('plugin::prime-builder.ui.panels')) ?>"><button type="button" data-pb-tab="content" aria-pressed="true"><?= $this->e(t('plugin::prime-builder.ui.content')) ?></button><button type="button" data-pb-tab="style" aria-pressed="false"><?= $this->e(t('plugin::prime-builder.ui.style')) ?></button><button type="button" data-pb-tab="advanced" aria-pressed="false"><?= $this->e(t('plugin::prime-builder.ui.advanced')) ?></button></div>
                <div data-pb-fields></div>
                <div class="pb-element-actions"><button type="button" data-pb-action="move_up"><?= $this->e(t('plugin::prime-builder.ui.move_up')) ?></button><button type="button" data-pb-action="move_down"><?= $this->e(t('plugin::prime-builder.ui.move_down')) ?></button><button type="button" data-pb-action="duplicate"><?= $this->e(t('plugin::prime-builder.ui.duplicate')) ?></button><button type="button" class="pb-danger" data-pb-action="remove"><?= $this->e(t('plugin::prime-builder.ui.remove')) ?></button></div>
            </section>
            <div class="pb-side-foot"><div class="pb-side-foot-icon" aria-hidden="true">✦</div><p><?= $this->e(t('plugin::prime-builder.ui.tip')) ?></p></div>
        </aside>
        <main class="pb-stage" id="pb-canvas">
            <div class="pb-stage-toolbar"><div><span class="pb-stage-title"><?= $this->e(t('plugin::prime-builder.ui.preview')) ?></span><span class="pb-stage-separator" aria-hidden="true"></span><span class="pb-stage-subtitle"><?= $this->e(t('plugin::prime-builder.ui.preview_hint')) ?></span></div><div class="pb-devices" role="group" aria-label="<?= $this->e(t('plugin::prime-builder.ui.responsive')) ?>"><button type="button" data-pb-device="desktop" aria-pressed="true"><?= $this->e(t('plugin::prime-builder.ui.desktop')) ?></button><button type="button" data-pb-device="tablet" aria-pressed="false"><?= $this->e(t('plugin::prime-builder.ui.tablet')) ?></button><button type="button" data-pb-device="mobile" aria-pressed="false"><?= $this->e(t('plugin::prime-builder.ui.mobile')) ?></button></div></div>
            <div class="pb-canvas-scroll"><div class="pb-canvas-frame pb-device-desktop" data-pb-frame-wrap><div class="pb-canvas-chrome"><span></span><span></span><span></span><div><?= $this->e($entry->title) ?></div></div><iframe data-pb-frame src="<?= $this->e($entry->previewUrl()) ?>" title="<?= $this->e(t('plugin::prime-builder.ui.preview')) ?>"></iframe></div></div>
        </main>
    </div>
    <footer class="pb-bottombar"><div><button type="button" class="pb-icon-button" data-pb-undo title="<?= $this->e(t('plugin::prime-builder.ui.undo')) ?>" aria-label="<?= $this->e(t('plugin::prime-builder.ui.undo')) ?>">↶</button><button type="button" class="pb-icon-button" data-pb-redo title="<?= $this->e(t('plugin::prime-builder.ui.redo')) ?>" aria-label="<?= $this->e(t('plugin::prime-builder.ui.redo')) ?>">↷</button><a href="/admin/content/<?= $this->e(rawurlencode($entry->type)) ?>/<?= $this->e((string) $entry->id) ?>/revisions"><?= $this->e(t('plugin::prime-builder.ui.revisions')) ?></a></div><div><label for="pb-status"><?= $this->e(t('plugin::prime-builder.ui.document_status')) ?></label><select id="pb-status" data-pb-status></select><span class="pb-status-indicator" aria-hidden="true"></span></div></footer>
</div>
</body>
</html>
