<?php

declare(strict_types=1);

/** @var \Prime\View\TemplateContext $this */
/** @var string $pageHeading */
/** @var list<array{label: string, url: ?string}> $breadcrumbs */
/** @var list<array{id: int, entry_id: int, node_id: string, name: string, email: string, message: string, created_at: string}> $submissions */
$this->layout('admin::layouts/app');
?>
<?php $this->start('content'); ?>
<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => $pageHeading,
    'subtitle' => t('plugin::prime-builder.forms.inbox_description'),
    'breadcrumbs' => $breadcrumbs,
])) ?>
<div class="pc-form-constrained pc-stack pc-stack--lg" data-pb-submissions>
    <p><?= $this->e(t('plugin::prime-builder.forms.inbox_help')) ?></p>
    <?php if ($submissions === []): ?>
        <div class="pc-card"><div class="pc-card__body"><?= $this->e(t('plugin::prime-builder.forms.empty')) ?></div></div>
    <?php else: ?>
        <?php foreach ($submissions as $submission): ?>
            <article class="pc-card" data-pb-submission-id="<?= $this->e((string) $submission['id']) ?>">
                <header class="pc-card__header"><h2 class="pc-card__title"><?= $this->e(t('plugin::prime-builder.forms.submission')) ?> #<?= $this->e((string) $submission['id']) ?></h2></header>
                <div class="pc-card__body pb-submission-content">
                    <dl>
                        <dt><?= $this->e(t('plugin::prime-builder.forms.content')) ?></dt><dd><?= $this->e((string) $submission['entry_id']) ?> / <?= $this->e($submission['node_id']) ?></dd>
                        <dt><?= $this->e(t('plugin::prime-builder.forms.received')) ?></dt><dd><?= $this->e($submission['created_at']) ?> UTC</dd>
                        <dt><?= $this->e(t('plugin::prime-builder.forms.name')) ?></dt><dd><?= $this->e($submission['name']) ?></dd>
                        <dt><?= $this->e(t('plugin::prime-builder.forms.email')) ?></dt><dd><?= $this->e($submission['email']) ?></dd>
                        <dt><?= $this->e(t('plugin::prime-builder.forms.message')) ?></dt><dd class="pb-submission-message"><?= $this->e($submission['message']) ?></dd>
                    </dl>
                </div>
                <div class="pc-card__footer">
                    <form method="post" action="/admin/prime-builder/submissions/<?= $this->e((string) $submission['id']) ?>/delete">
                        <?= $this->raw($this->csrfField()) ?>
                        <button class="pc-btn pc-btn--danger" type="submit" aria-label="<?= $this->e(t('plugin::prime-builder.forms.delete')) ?> #<?= $this->e((string) $submission['id']) ?>"><?= $this->e(t('plugin::prime-builder.forms.delete')) ?></button>
                    </form>
                </div>
            </article>
        <?php endforeach; ?>
    <?php endif; ?>
</div>
<?php $this->stop(); ?>
