<?php

declare(strict_types=1);

/** @var \Prime\View\TemplateContext $this */
/** @var string $pageHeading */
/** @var list<array{label: string, url: ?string}> $breadcrumbs */
/** @var array<string, string> $values */
/** @var list<array<string, mixed>> $choices */
/** @var list<array<string, mixed>> $sourceChoices */
/** @var list<array<string, mixed>> $models */
/** @var string $modelFilter */
/** @var string|null $error */
$this->layout('admin::layouts/app');
?>
<?php $this->start('content'); ?>
<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => $pageHeading,
    'subtitle' => t('plugin::prime-builder.theme.description'),
    'breadcrumbs' => $breadcrumbs,
])) ?>
<div class="pc-form-constrained pc-stack pc-stack--lg" data-pb-theme>
    <section class="pc-card" aria-labelledby="pb-models-title">
        <header class="pc-card__header"><h2 class="pc-card__title" id="pb-models-title"><?= $this->e(t('plugin::prime-builder.theme.models')) ?></h2></header>
        <div class="pc-card__body pc-stack pc-stack--md">
            <form method="get" action="/admin/prime-builder/theme" class="pb-theme-actions">
                <label class="pc-label" for="pb-model-filter"><?= $this->e(t('plugin::prime-builder.theme.filter')) ?></label>
                <select class="pc-input" id="pb-model-filter" name="kind"><option value="all"><?= $this->e(t('plugin::prime-builder.theme.filter_all')) ?></option><?php foreach (\PrimeBuilder\Theme\Models::KINDS as $kind): ?><option value="<?= $this->e($kind) ?>"<?= $modelFilter === $kind ? ' selected' : '' ?>><?= $this->e(t('plugin::prime-builder.theme.kind_' . $kind)) ?></option><?php endforeach; ?></select>
                <button class="pc-btn pc-btn--secondary" type="submit"><?= $this->e(t('plugin::prime-builder.theme.filter_apply')) ?></button>
            </form>
            <?php if ($models === []): ?><p><?= $this->e(t('plugin::prime-builder.theme.empty')) ?></p><?php endif; ?>
            <?php foreach ($models as $model): ?>
                <article class="pb-theme-model-row">
                    <div><strong><?= $this->e((string) $model['name']) ?></strong><br><span><?= $this->e(t('plugin::prime-builder.theme.kind_' . $model['kind'])) ?> · <?= $this->e(t('plugin::prime-builder.theme.' . ($model['enabled'] ? 'active' : 'inactive'))) ?></span></div>
                    <div class="pb-theme-actions">
                        <a class="pc-btn pc-btn--secondary" href="/admin/prime-builder/theme/<?= $this->e((string) $model['id']) ?>"><?= $this->e(t('plugin::prime-builder.theme.edit_conditions')) ?></a>
                        <a class="pc-btn pc-btn--secondary" href="/admin/prime-builder/theme/<?= $this->e((string) $model['id']) ?>/edit"><?= $this->e(t('plugin::prime-builder.theme.edit_layout')) ?></a>
                        <form method="post" action="/admin/prime-builder/theme/<?= $this->e((string) $model['id']) ?>/delete"><?= $this->raw($this->csrfField()) ?><button class="pc-btn pc-btn--secondary" type="submit" aria-label="<?= $this->e(t('plugin::prime-builder.theme.delete') . ' — ' . (string) $model['name']) ?>"><?= $this->e(t('plugin::prime-builder.theme.delete')) ?></button></form>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    </section>
    <form method="post" action="/admin/prime-builder/theme/create" class="pc-card" aria-labelledby="pb-theme-create-title">
        <?= $this->raw($this->csrfField()) ?>
        <header class="pc-card__header"><h2 class="pc-card__title" id="pb-theme-create-title"><?= $this->e(t('plugin::prime-builder.theme.create_title')) ?></h2></header>
        <div class="pc-card__body pc-stack pc-stack--md">
            <div class="pc-field"><label class="pc-label" for="pb-model-name"><?= $this->e(t('plugin::prime-builder.theme.name')) ?></label><input class="pc-input" id="pb-model-name" name="name" maxlength="100" required></div>
            <div class="pc-field"><label class="pc-label" for="pb-model-kind"><?= $this->e(t('plugin::prime-builder.theme.kind')) ?></label><select class="pc-input" id="pb-model-kind" name="kind"><?php foreach (\PrimeBuilder\Theme\Models::KINDS as $kind): ?><option value="<?= $this->e($kind) ?>"><?= $this->e(t('plugin::prime-builder.theme.kind_' . $kind)) ?></option><?php endforeach; ?></select></div>
            <div class="pc-field"><label class="pc-label" for="pb-model-source"><?= $this->e(t('plugin::prime-builder.theme.source')) ?></label><select class="pc-input" id="pb-model-source" name="source"><option value=""><?= $this->e(t('plugin::prime-builder.theme.empty_source')) ?></option><?php foreach ($sourceChoices as $choice): ?><option value="<?= $this->e((string) $choice['id']) ?>"><?= $this->e((string) $choice['name']) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="pc-card__footer"><button class="pc-btn pc-btn--primary" type="submit"><?= $this->e(t('plugin::prime-builder.theme.create')) ?></button></div>
    </form>
    <form method="post" action="/admin/prime-builder/theme" class="pc-stack pc-stack--lg">
        <?= $this->raw($this->csrfField()) ?>
        <?php if ($error !== null): ?>
            <div class="pc-alert pc-alert--danger" role="alert"><?= $this->e(t('plugin::prime-builder.theme.' . $error)) ?></div>
        <?php endif; ?>
        <section class="pc-card" aria-labelledby="pb-theme-title">
            <header class="pc-card__header"><h2 class="pc-card__title" id="pb-theme-title"><?= $this->e(t('plugin::prime-builder.theme.partials')) ?></h2></header>
            <div class="pc-card__body pc-stack pc-stack--md">
                <p><?= $this->e(t('plugin::prime-builder.theme.help')) ?></p>
                <?php foreach (['header', 'footer'] as $slot): ?>
                    <div class="pc-field">
                        <label class="pc-label" for="pb-theme-<?= $this->e($slot) ?>"><?= $this->e(t('plugin::prime-builder.theme.' . $slot)) ?></label>
                        <select class="pc-input" id="pb-theme-<?= $this->e($slot) ?>" name="<?= $this->e($slot) ?>">
                            <option value=""><?= $this->e(t('plugin::prime-builder.theme.original')) ?></option>
                            <?php foreach ($choices as $choice): ?>
                                <option value="<?= $this->e((string) $choice['id']) ?>"<?= ($values[$slot] ?? '') === $choice['id'] ? ' selected' : '' ?>><?= $this->e((string) $choice['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endforeach; ?>
                <div class="pc-field">
                    <label class="pc-label" for="pb-theme-exclude"><?= $this->e(t('plugin::prime-builder.theme.exclude')) ?></label>
                    <input class="pc-input" id="pb-theme-exclude" name="exclude" type="text" maxlength="2100" value="<?= $this->e($values['exclude'] ?? '') ?>" aria-describedby="pb-theme-exclude-help">
                    <p class="pc-field__help" id="pb-theme-exclude-help"><?= $this->e(t('plugin::prime-builder.theme.exclude_help')) ?></p>
                </div>
            </div>
            <div class="pc-card__footer"><button class="pc-btn pc-btn--primary" type="submit"><?= $this->e(t('plugin::prime-builder.ui.save')) ?></button></div>
        </section>
    </form>
</div>
<?php $this->stop(); ?>
