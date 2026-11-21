<?php

declare(strict_types=1);

/** @var \Prime\View\TemplateContext $this */
/** @var string $pageHeading */
/** @var list<array{label: string, url: ?string}> $breadcrumbs */
/** @var array<string, string> $values */
/** @var list<array<string, mixed>> $choices */
/** @var string|null $error */
$this->layout('admin::layouts/app');
?>
<?php $this->start('content'); ?>
<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => $pageHeading,
    'subtitle' => t('plugin::prime-builder.theme.description'),
    'breadcrumbs' => $breadcrumbs,
])) ?>
<div class="pc-form-constrained" data-pb-theme>
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
