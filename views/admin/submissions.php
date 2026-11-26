<?php

declare(strict_types=1);

/** @var \Prime\View\TemplateContext $this */
/** @var string $pageHeading */
/** @var list<array{label: string, url: ?string}> $breadcrumbs */
/** @var list<array{id: int, entry_id: int, node_id: string, name: string, email: string, message: string, created_at: string}> $submissions */
/** @var string $search */
/** @var int $page */
/** @var bool $hasNext */
/** @var int $retentionDays */
$this->layout('admin::layouts/app');
$pageUrl = static fn (int $number): string => '/admin/prime-builder/submissions?' . http_build_query(['q' => $search, 'page' => $number], '', '&', PHP_QUERY_RFC3986);
$exportUrl = '/admin/prime-builder/submissions/export' . ($search === '' ? '' : '?' . http_build_query(['q' => $search], '', '&', PHP_QUERY_RFC3986));
$exportPageUrl = '/admin/prime-builder/submissions/export?' . http_build_query(['q' => $search, 'page' => $page], '', '&', PHP_QUERY_RFC3986);
?>
<?php $this->start('content'); ?>
<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => $pageHeading,
    'subtitle' => t('plugin::prime-builder.forms.inbox_description'),
    'breadcrumbs' => $breadcrumbs,
])) ?>
<div class="pc-form-constrained pc-stack pc-stack--lg" data-pb-submissions>
    <p><?= $this->e(t('plugin::prime-builder.forms.inbox_help', ['days' => $retentionDays])) ?></p>
    <form method="get" action="/admin/prime-builder/submissions" role="search" class="pc-stack pc-stack--md">
        <div class="pc-field">
            <label class="pc-label" for="pb-submissions-search"><?= $this->e(t('plugin::prime-builder.forms.search')) ?></label>
            <input class="pc-input" type="search" id="pb-submissions-search" name="q" maxlength="160" value="<?= $this->e($search) ?>">
        </div>
        <div class="pb-submissions-actions">
            <button class="pc-btn pc-btn--primary" type="submit"><?= $this->e(t('plugin::prime-builder.forms.search_button')) ?></button>
            <?php if ($search !== ''): ?><a class="pc-btn pc-btn--secondary" href="/admin/prime-builder/submissions"><?= $this->e(t('plugin::prime-builder.forms.clear_search')) ?></a><?php endif; ?>
            <a class="pc-btn pc-btn--secondary" href="<?= $this->e($exportUrl) ?>"><?= $this->e(t('plugin::prime-builder.forms.export')) ?></a>
            <a class="pc-btn pc-btn--secondary" href="<?= $this->e($exportPageUrl) ?>"><?= $this->e(t('plugin::prime-builder.forms.export_page')) ?></a>
        </div>
        <p class="pc-field__help"><?= $this->e(t('plugin::prime-builder.forms.export_help')) ?></p>
    </form>
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
    <?php if ($page > 1 || $hasNext): ?>
        <nav class="pb-submissions-actions" aria-label="<?= $this->e(t('plugin::prime-builder.forms.pagination')) ?>">
            <?php if ($page > 1): ?><a class="pc-btn pc-btn--secondary" href="<?= $this->e($pageUrl($page - 1)) ?>"><?= $this->e(t('plugin::prime-builder.forms.previous')) ?></a><?php endif; ?>
            <span><?= $this->e(t('plugin::prime-builder.forms.page', ['page' => $page])) ?></span>
            <?php if ($hasNext): ?><a class="pc-btn pc-btn--secondary" href="<?= $this->e($pageUrl($page + 1)) ?>"><?= $this->e(t('plugin::prime-builder.forms.next')) ?></a><?php endif; ?>
        </nav>
    <?php endif; ?>
</div>
<?php $this->stop(); ?>
