<?php

declare(strict_types=1);

/** @var \Prime\View\TemplateContext $this */
/** @var ?\Prime\Content\Entry $entry */
/** @var string $contentJson */
?>
<div class="pb-alternate">
    <textarea name="content_json" data-prime-custom-editor-input hidden><?= $this->e($contentJson) ?></textarea>
    <?php if ($entry !== null): ?>
        <p><?= $this->e(t('plugin::prime-builder.editor.description')) ?></p>
        <a class="pc-btn pc-btn--primary" href="/admin/prime-builder/edit/<?= $this->e((string) $entry->id) ?>"><?= $this->e(t('plugin::prime-builder.editor.open')) ?></a>
        <iframe class="pb-alternate-preview" src="<?= $this->e($entry->previewUrl()) ?>" title="<?= $this->e(t('plugin::prime-builder.editor.preview')) ?>" loading="lazy"></iframe>
    <?php else: ?>
        <p><?= $this->e(t('plugin::prime-builder.editor.save_first')) ?></p>
    <?php endif; ?>
</div>
