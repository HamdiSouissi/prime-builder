<?php

declare(strict_types=1);

$entryId = is_object($entry ?? null) && isset($entry->id) ? (int) $entry->id : 0;
$entryTitle = is_object($entry ?? null) && isset($entry->title) ? (string) $entry->title : 'Sans titre';
$docJson = json_encode($document ?? ['version' => 1, 'elements' => []], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$widgetsJson = json_encode($widgets ?? [], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
$csrf = htmlspecialchars((string) ($csrfToken ?? ''), ENT_QUOTES, 'UTF-8');
$preview = htmlspecialchars((string) ($previewUrl ?? '/'), ENT_QUOTES, 'UTF-8');
$baseUrl = rtrim((string) ($pluginUrl ?? ''), '/');
?><!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Prime Builder — <?= htmlspecialchars($entryTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/css/editor.css">
</head>
<body class="pb-editor" data-pb-entry-id="<?= $entryId ?>" data-pb-csrf="<?= $csrf ?>">
    <header class="pb-topbar">
        <div class="pb-topbar__left">
            <a href="/admin/content" class="pb-btn pb-btn--ghost" data-pb-exit><?= htmlspecialchars(t('plugin::prime-builder.editor.exit'), ENT_QUOTES, 'UTF-8') ?></a>
            <span class="pb-topbar__title"><?= htmlspecialchars($entryTitle, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="pb-topbar__center" role="group" aria-label="Modes responsive">
            <button type="button" class="pb-btn pb-btn--icon is-active" data-pb-device="desktop" aria-pressed="true"><?= htmlspecialchars(t('plugin::prime-builder.editor.responsive_desktop'), ENT_QUOTES, 'UTF-8') ?></button>
            <button type="button" class="pb-btn pb-btn--icon" data-pb-device="tablet" aria-pressed="false"><?= htmlspecialchars(t('plugin::prime-builder.editor.responsive_tablet'), ENT_QUOTES, 'UTF-8') ?></button>
            <button type="button" class="pb-btn pb-btn--icon" data-pb-device="mobile" aria-pressed="false"><?= htmlspecialchars(t('plugin::prime-builder.editor.responsive_mobile'), ENT_QUOTES, 'UTF-8') ?></button>
        </div>
        <div class="pb-topbar__right">
            <button type="button" class="pb-btn pb-btn--ghost" data-pb-undo title="Ctrl+Z"><?= htmlspecialchars(t('plugin::prime-builder.editor.undo'), ENT_QUOTES, 'UTF-8') ?></button>
            <button type="button" class="pb-btn pb-btn--ghost" data-pb-redo title="Ctrl+Shift+Z"><?= htmlspecialchars(t('plugin::prime-builder.editor.redo'), ENT_QUOTES, 'UTF-8') ?></button>
            <a href="<?= $preview ?>" target="_blank" rel="noopener" class="pb-btn pb-btn--secondary"><?= htmlspecialchars(t('plugin::prime-builder.editor.preview'), ENT_QUOTES, 'UTF-8') ?></a>
            <button type="button" class="pb-btn pb-btn--primary" data-pb-save><?= htmlspecialchars(t('plugin::prime-builder.editor.save'), ENT_QUOTES, 'UTF-8') ?></button>
        </div>
    </header>

    <main class="pb-workspace">
        <aside class="pb-panel" aria-label="Panneau des widgets et réglages">
            <div class="pb-panel__tabs" role="tablist">
                <button type="button" role="tab" aria-selected="true" class="pb-panel__tab is-active" data-pb-panel-tab="widgets"><?= htmlspecialchars(t('plugin::prime-builder.editor.widgets'), ENT_QUOTES, 'UTF-8') ?></button>
                <button type="button" role="tab" aria-selected="false" class="pb-panel__tab" data-pb-panel-tab="inspector"><?= htmlspecialchars(t('plugin::prime-builder.editor.tab_content'), ENT_QUOTES, 'UTF-8') ?></button>
                <button type="button" role="tab" aria-selected="false" class="pb-panel__tab" data-pb-panel-tab="navigator"><?= htmlspecialchars(t('plugin::prime-builder.editor.navigator'), ENT_QUOTES, 'UTF-8') ?></button>
            </div>
            <div class="pb-panel__body" data-pb-panel-body></div>
        </aside>

        <section class="pb-canvas-wrap" aria-label="Canevas d’édition">
            <div class="pb-canvas-frame" data-pb-canvas-frame data-device="desktop">
                <div class="pb-canvas" data-pb-canvas></div>
            </div>
        </section>
    </main>

    <script type="application/json" id="pb-initial-document"><?= $docJson ?></script>
    <script type="application/json" id="pb-widgets-meta"><?= $widgetsJson ?></script>
    <script type="module" src="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/js/editor/app.js"></script>
</body>
</html>
