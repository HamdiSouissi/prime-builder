<?php

declare(strict_types=1);

/** @var \Prime\View\TemplateContext $this */
/** @var string $pageHeading */
/** @var list<array{label: string, url: ?string}> $breadcrumbs */
/** @var list<array{label: string, url: string}> $contentTypes */
/** @var bool $canManageSettings */
/** @var string $version */
/** @var string $cmsVersion */
/** @var int $widgetCount */
/** @var int $templateCount */
$this->layout('admin::layouts/app');
?>
<?php $this->start('content'); ?>
<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => $pageHeading,
    'subtitle' => t('plugin::prime-builder.dashboard.subtitle'),
    'breadcrumbs' => $breadcrumbs,
])) ?>
<div class="pc-stack pc-stack--lg" data-pb-dashboard>
    <section class="pc-card" aria-labelledby="pb-start-title">
        <header class="pc-card__header"><h2 class="pc-card__title" id="pb-start-title"><?= $this->e(t('plugin::prime-builder.dashboard.start')) ?></h2></header>
        <div class="pc-card__body pc-stack pc-stack--md">
            <p><?= $this->e(t('plugin::prime-builder.dashboard.instructions')) ?></p>
            <?php if ($contentTypes === []): ?>
                <p><?= $this->e(t('plugin::prime-builder.dashboard.no_types')) ?></p>
            <?php else: ?>
                <ul>
                    <?php foreach ($contentTypes as $contentType): ?>
                        <li><a href="<?= $this->e($contentType['url']) ?>"><?= $this->e($contentType['label']) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($canManageSettings): ?>
                <p><a class="pc-btn pc-btn--secondary" href="/admin/settings/general#settings-section-prime-builder"><?= $this->e(t('plugin::prime-builder.dashboard.settings')) ?></a></p>
            <?php endif; ?>
        </div>
    </section>
    <section class="pc-card" aria-labelledby="pb-status-title">
        <header class="pc-card__header"><h2 class="pc-card__title" id="pb-status-title"><?= $this->e(t('plugin::prime-builder.dashboard.status')) ?></h2></header>
        <div class="pc-card__body pc-stack pc-stack--md">
            <dl>
                <dt><?= $this->e(t('plugin::prime-builder.dashboard.plugin_version')) ?></dt><dd><?= $this->e($version) ?></dd>
                <dt><?= $this->e(t('plugin::prime-builder.dashboard.cms_version')) ?></dt><dd><?= $this->e($cmsVersion) ?></dd>
                <dt><?= $this->e(t('plugin::prime-builder.dashboard.widgets')) ?></dt><dd><?= $this->e((string) $widgetCount) ?></dd>
                <dt><?= $this->e(t('plugin::prime-builder.dashboard.templates')) ?></dt><dd><?= $this->e((string) $templateCount) ?></dd>
            </dl>
            <p><?= $this->e(t('plugin::prime-builder.dashboard.limits')) ?></p>
            <?php if ($canManageSettings): ?>
                <p><a href="/admin/tools/system"><?= $this->e(t('plugin::prime-builder.dashboard.system')) ?></a></p>
            <?php endif; ?>
        </div>
    </section>
</div>
<?php $this->stop(); ?>
