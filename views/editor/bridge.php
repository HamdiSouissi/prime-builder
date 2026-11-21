<?php

declare(strict_types=1);

$entryId = is_object($entry ?? null) && isset($entry->id) ? (int) $entry->id : 0;
$json = is_string($contentJson ?? null) ? $contentJson : '{"version":1,"blocks":[]}';
?>
<div class="pb-bridge">
    <div class="pb-bridge__card">
        <div class="pb-bridge__info">
            <h3><?= htmlspecialchars(t('plugin::prime-builder.editor.preview_title'), ENT_QUOTES, 'UTF-8') ?></h3>
            <p><?= htmlspecialchars(t('plugin::prime-builder.editor.preview_hint'), ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <?php if ($entryId > 0) : ?>
            <a href="/admin/prime-builder/edit/<?= $entryId ?>" class="pc-btn pc-btn--primary">
                <?= htmlspecialchars(t('plugin::prime-builder.editor.launch_button'), ENT_QUOTES, 'UTF-8') ?>
            </a>
        <?php endif; ?>
    </div>
    <textarea name="content_json" data-prime-custom-editor-input hidden><?= htmlspecialchars($json, ENT_QUOTES, 'UTF-8') ?></textarea>
</div>
