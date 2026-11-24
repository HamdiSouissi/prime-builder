<?php

declare(strict_types=1);

/** @var \Prime\View\TemplateContext $this */
/** @var \Prime\Content\Entry $entry */
/** @var string $configJson */
/** @var bool $canTemplates */
/** @var bool $conversionFailed */
/** @var string $cssUrl */
/** @var string $jsUrl */
?>
<!doctype html>
<html lang="<?= $this->e(current_locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= $this->e(csrf_token()) ?>">
    <title><?= $this->e(t('plugin::prime-builder.ui.editor_title')) ?> — <?= $this->e($entry->title) ?></title>
    <link rel="stylesheet" data-pb-editor-css href="<?= $this->e($cssUrl) ?>">
    <script type="module" src="<?= $this->e($jsUrl) ?>"></script>
</head>
<body>
<a class="pb-skip" href="#pb-canvas"><?= $this->e(t('plugin::prime-builder.ui.skip')) ?></a>
<div class="pb-app" data-pb-app<?= $conversionFailed ? ' data-pb-blocked' : '' ?>>
    <textarea hidden data-pb-config><?= $this->e($configJson) ?></textarea>
    <header class="pb-topbar">
        <div class="pb-brand"><span class="pb-brand-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none"><path d="M4 5h16v4H4zm0 7h7v7H4zm10 0h6v3h-6zm0 5h6v2h-6z" fill="currentColor"/></svg></span><span><strong><?= $this->e(t('plugin::prime-builder.menu')) ?></strong><small><?= $this->e($entry->title) ?></small></span></div>
        <div class="pb-top-actions"><button type="button" class="pb-button pb-button-quiet" data-pb-command-open aria-haspopup="dialog"><?= $this->e(t('plugin::prime-builder.ui.commands')) ?> <span aria-hidden="true"><?= $this->e(t('plugin::prime-builder.ui.command_hint')) ?></span></button><span class="pb-save-state" data-pb-save-state aria-live="polite"></span><a class="pb-button pb-button-quiet" data-pb-return href="<?= $this->e($entry->editUrl()) ?>"><?= $this->e(t('plugin::prime-builder.ui.back')) ?></a><button type="button" class="pb-button pb-button-primary" data-pb-save<?= $conversionFailed ? ' disabled' : '' ?>><?= $this->e(t('plugin::prime-builder.ui.save')) ?></button></div>
    </header>
    <p class="pb-conversion-warning" data-pb-conversion-warning role="alert"<?= $conversionFailed ? '' : ' hidden' ?>><?= $this->e(t('plugin::prime-builder.ui.conversion_failed')) ?></p>
    <p class="pb-conversion-warning" data-pb-preview-warning role="alert" hidden><?= $this->e(t('plugin::prime-builder.ui.preview_location_error')) ?></p>
    <p class="pb-conversion-warning" data-pb-group-warning role="status" aria-live="polite" hidden><?= $this->e(t('plugin::prime-builder.ui.original_group_warning')) ?></p>
    <div class="pb-workspace">
        <aside class="pb-sidebar" aria-label="<?= $this->e(t('plugin::prime-builder.ui.sidebar')) ?>">
            <div class="pb-panel-head"><div><span class="pb-eyebrow"><?= $this->e(t('plugin::prime-builder.ui.eyebrow')) ?></span><h1><?= $this->e(t('plugin::prime-builder.ui.editor_title')) ?></h1></div><span class="pb-panel-dot" aria-hidden="true"></span></div>
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
                <div class="pb-library-actions"><?php if ($canTemplates): ?><label class="pb-library-import"><?= $this->e(t('plugin::prime-builder.ui.import')) ?><input type="file" accept="application/json,.json" data-pb-import<?= $conversionFailed ? ' disabled' : '' ?>></label><?php endif; ?><button type="button" data-pb-export<?= $conversionFailed ? ' disabled' : '' ?>><?= $this->e(t('plugin::prime-builder.ui.export')) ?></button></div>
                <p class="pb-intro"><?= $this->e(t('plugin::prime-builder.ui.import_help')) ?></p>
                <div data-pb-library-list></div>
            </section>
            <section class="pb-panel-content" data-pb-settings-panel hidden>
                <div class="pb-selected-heading"><div><span class="pb-eyebrow"><?= $this->e(t('plugin::prime-builder.ui.edit_element')) ?></span><h2 data-pb-selected-title></h2></div><button type="button" class="pb-icon-button" data-pb-close-settings aria-label="<?= $this->e(t('plugin::prime-builder.ui.widgets')) ?>">×</button></div>
                <div class="pb-settings-tabs" role="group" aria-label="<?= $this->e(t('plugin::prime-builder.ui.panels')) ?>"><button type="button" data-pb-tab="content" aria-pressed="true"><?= $this->e(t('plugin::prime-builder.ui.content')) ?></button><button type="button" data-pb-tab="style" aria-pressed="false"><?= $this->e(t('plugin::prime-builder.ui.style')) ?></button><button type="button" data-pb-tab="advanced" aria-pressed="false"><?= $this->e(t('plugin::prime-builder.ui.advanced')) ?></button></div>
                <div data-pb-fields></div>
                <label class="pb-field" for="pb-parent"><?= $this->e(t('plugin::prime-builder.ui.move_to')) ?><select id="pb-parent" data-pb-parent></select></label>
                <div class="pb-element-actions"><button type="button" data-pb-action="move_up"><?= $this->e(t('plugin::prime-builder.ui.move_up')) ?></button><button type="button" data-pb-action="move_down"><?= $this->e(t('plugin::prime-builder.ui.move_down')) ?></button><button type="button" data-pb-action="duplicate"><?= $this->e(t('plugin::prime-builder.ui.duplicate')) ?></button><button type="button" class="pb-danger" data-pb-action="remove"><?= $this->e(t('plugin::prime-builder.ui.remove')) ?></button><button type="button" data-pb-context-open aria-haspopup="menu" aria-controls="pb-context-menu" aria-expanded="false"><?= $this->e(t('plugin::prime-builder.ui.context_menu')) ?></button></div>
            </section>
            <div class="pb-side-foot"><div class="pb-side-foot-icon" aria-hidden="true">✦</div><p><?= $this->e(t('plugin::prime-builder.ui.tip')) ?></p></div>
        </aside>
        <main class="pb-stage" id="pb-canvas">
            <div class="pb-stage-toolbar"><div><span class="pb-stage-title"><?= $this->e(t('plugin::prime-builder.ui.preview')) ?></span><span class="pb-stage-separator" aria-hidden="true"></span><span class="pb-stage-subtitle"><?= $this->e(t('plugin::prime-builder.ui.preview_hint')) ?></span></div><div class="pb-devices" role="group" aria-label="<?= $this->e(t('plugin::prime-builder.ui.responsive')) ?>"><button type="button" data-pb-device="desktop" aria-pressed="true"><?= $this->e(t('plugin::prime-builder.ui.desktop')) ?></button><button type="button" data-pb-device="tablet" aria-pressed="false"><?= $this->e(t('plugin::prime-builder.ui.tablet')) ?></button><button type="button" data-pb-device="mobile" aria-pressed="false"><?= $this->e(t('plugin::prime-builder.ui.mobile')) ?></button></div></div>
            <div class="pb-canvas-scroll"><div class="pb-canvas-frame pb-device-desktop" data-pb-frame-wrap><div class="pb-canvas-chrome"><span></span><span></span><span></span><div><?= $this->e($entry->title) ?></div></div><iframe data-pb-frame src="<?= $this->e($entry->previewUrl()) ?>" title="<?= $this->e(t('plugin::prime-builder.ui.preview')) ?>"></iframe></div></div>
        </main>
    </div>
    <div class="pb-selection-toolbar" data-pb-selection-toolbar role="toolbar" aria-label="<?= $this->e(t('plugin::prime-builder.ui.element_toolbar')) ?>" hidden>
        <button type="button" data-pb-tool="copy" aria-label="<?= $this->e(t('plugin::prime-builder.ui.copy')) ?>" title="<?= $this->e(t('plugin::prime-builder.ui.copy')) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="8" y="8" width="11" height="11" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/></svg></button>
        <button type="button" data-pb-tool="duplicate" aria-label="<?= $this->e(t('plugin::prime-builder.ui.duplicate')) ?>" title="<?= $this->e(t('plugin::prime-builder.ui.duplicate')) ?>"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="5" y="5" width="14" height="14" rx="2"/><path d="M12 8v8M8 12h8"/></svg></button>
        <button type="button" data-pb-toolbar-menu aria-label="<?= $this->e(t('plugin::prime-builder.ui.context_menu')) ?>" title="<?= $this->e(t('plugin::prime-builder.ui.context_menu')) ?>" aria-haspopup="menu" aria-controls="pb-context-menu" aria-expanded="false"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><circle cx="5" cy="12" r="1.5"/><circle cx="12" cy="12" r="1.5"/><circle cx="19" cy="12" r="1.5"/></svg></button>
    </div>
    <div class="pb-context-menu" id="pb-context-menu" data-pb-context-menu role="menu" aria-label="<?= $this->e(t('plugin::prime-builder.ui.context_menu')) ?>" hidden>
        <button type="button" role="menuitem" tabindex="-1" data-pb-context="copy"><?= $this->e(t('plugin::prime-builder.ui.copy')) ?></button>
        <button type="button" role="menuitem" tabindex="-1" data-pb-context="paste"><?= $this->e(t('plugin::prime-builder.ui.paste')) ?></button>
        <button type="button" role="menuitem" tabindex="-1" data-pb-context="paste_style"><?= $this->e(t('plugin::prime-builder.ui.paste_style')) ?></button>
        <button type="button" role="menuitem" tabindex="-1" data-pb-context="duplicate"><?= $this->e(t('plugin::prime-builder.ui.duplicate')) ?></button>
        <?php if ($canTemplates): ?><button type="button" role="menuitem" tabindex="-1" data-pb-context="save_template"><?= $this->e(t('plugin::prime-builder.ui.save_template')) ?></button><?php endif; ?>
        <button type="button" role="menuitem" tabindex="-1" data-pb-context="reset_style"><?= $this->e(t('plugin::prime-builder.ui.reset_style')) ?></button>
        <button type="button" role="menuitem" tabindex="-1" data-pb-context="remove"><?= $this->e(t('plugin::prime-builder.ui.remove')) ?></button>
    </div>
    <span class="pb-sr-only" data-pb-live aria-live="polite"></span>
    <footer class="pb-bottombar"><div><button type="button" class="pb-icon-button" data-pb-undo<?= $conversionFailed ? ' disabled' : '' ?> title="<?= $this->e(t('plugin::prime-builder.ui.undo')) ?>" aria-label="<?= $this->e(t('plugin::prime-builder.ui.undo')) ?>">↶</button><button type="button" class="pb-icon-button" data-pb-redo<?= $conversionFailed ? ' disabled' : '' ?> title="<?= $this->e(t('plugin::prime-builder.ui.redo')) ?>" aria-label="<?= $this->e(t('plugin::prime-builder.ui.redo')) ?>">↷</button><button type="button" class="pb-history-open" data-pb-history-open aria-haspopup="dialog"><?= $this->e(t('plugin::prime-builder.ui.history')) ?></button><a href="/admin/content/<?= $this->e(rawurlencode($entry->type)) ?>/<?= $this->e((string) $entry->id) ?>/revisions"><?= $this->e(t('plugin::prime-builder.ui.revisions')) ?></a></div><div><label for="pb-status"><?= $this->e(t('plugin::prime-builder.ui.document_status')) ?></label><select id="pb-status" data-pb-status<?= $conversionFailed ? ' disabled' : '' ?>></select><label data-pb-schedule-wrap hidden><?= $this->e(t('plugin::prime-builder.ui.schedule_date')) ?> <input type="datetime-local" data-pb-schedule<?= $conversionFailed ? ' disabled' : '' ?>></label><span class="pb-status-indicator" aria-hidden="true"></span></div></footer>
</div>
<dialog class="pb-command-dialog" data-pb-commands aria-labelledby="pb-command-title">
    <div class="pb-command-head"><h2 id="pb-command-title"><?= $this->e(t('plugin::prime-builder.ui.commands')) ?></h2><button type="button" data-pb-command-close aria-label="<?= $this->e(t('plugin::prime-builder.ui.close')) ?>">×</button></div>
    <label class="pb-field"><?= $this->e(t('plugin::prime-builder.ui.command_search')) ?><input type="search" data-pb-command-search></label>
    <div class="pb-command-list" data-pb-command-list></div>
</dialog>
<dialog class="pb-command-dialog pb-history-dialog" data-pb-history aria-labelledby="pb-history-title" aria-describedby="pb-history-help">
    <div class="pb-command-head"><h2 id="pb-history-title"><?= $this->e(t('plugin::prime-builder.ui.history')) ?></h2><button type="button" data-pb-history-close aria-label="<?= $this->e(t('plugin::prime-builder.ui.close')) ?>">×</button></div>
    <p id="pb-history-help" class="pb-intro"><?= $this->e(t('plugin::prime-builder.ui.history_help')) ?></p>
    <ol class="pb-history-list" data-pb-history-list></ol>
</dialog>
<?php if ($canTemplates): ?>
<dialog class="pb-confirm-dialog" data-pb-save-template aria-labelledby="pb-save-template-title" aria-describedby="pb-save-template-help">
    <form data-pb-template-form>
        <h2 id="pb-save-template-title"><?= $this->e(t('plugin::prime-builder.ui.save_template')) ?></h2>
        <p id="pb-save-template-help"><?= $this->e(t('plugin::prime-builder.ui.template_shared_help')) ?></p>
        <label class="pb-field" for="pb-template-name"><?= $this->e(t('plugin::prime-builder.ui.template_name')) ?><input id="pb-template-name" data-pb-template-name required maxlength="100" autocomplete="off"></label>
        <p data-pb-template-error role="alert" hidden></p>
        <div class="pb-confirm-actions">
            <button type="button" data-pb-template-cancel><?= $this->e(t('plugin::prime-builder.ui.cancel')) ?></button>
            <button type="submit" class="pb-button pb-button-primary"><?= $this->e(t('plugin::prime-builder.ui.save')) ?></button>
        </div>
    </form>
</dialog>
<?php endif; ?>
<dialog class="pb-confirm-dialog" data-pb-confirm aria-labelledby="pb-confirm-title" aria-describedby="pb-confirm-message">
    <h2 id="pb-confirm-title" data-pb-confirm-title></h2>
    <p id="pb-confirm-message" data-pb-confirm-message></p>
    <div class="pb-confirm-actions">
        <button type="button" data-pb-confirm-cancel><?= $this->e(t('plugin::prime-builder.ui.cancel')) ?></button>
        <button type="button" class="pb-button pb-button-primary" data-pb-confirm-accept><?= $this->e(t('plugin::prime-builder.ui.continue')) ?></button>
    </div>
</dialog>
</body>
</html>
