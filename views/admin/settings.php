<?php

declare(strict_types=1);

/** @var \Prime\View\TemplateContext $this */
/** @var string $pageHeading */
/** @var list<array{label: string, url: ?string}> $breadcrumbs */
/** @var array<string, bool|int|float|string> $values */
/** @var array<string, bool> $errors */
/** @var list<string> $uploadWarnings */
/** @var string $formAction */
$this->layout('admin::layouts/app');
$invalid = static fn (string $name): string => isset($errors[$name]) ? ' aria-invalid="true"' : '';
$checked = static fn (string $name): bool => in_array($values[$name] ?? false, [true, 1, '1', 'true'], true);
?>
<?php $this->start('content'); ?>
<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => $pageHeading,
    'subtitle' => t('plugin::prime-builder.settings.description'),
    'breadcrumbs' => $breadcrumbs,
])) ?>
<div class="pc-form-constrained" data-pb-settings>
    <form method="post" action="<?= $this->e($formAction) ?>" class="pc-stack pc-stack--lg">
        <?= $this->raw($this->csrfField()) ?>
        <?php if ($errors !== []): ?>
            <div class="pc-alert pc-alert--danger" role="alert"><?= $this->e(t('plugin::prime-builder.settings.invalid')) ?></div>
        <?php endif; ?>
        <section class="pc-card" aria-labelledby="pb-settings-title">
            <header class="pc-card__header"><h2 class="pc-card__title" id="pb-settings-title"><?= $this->e(t('plugin::prime-builder.settings.title')) ?></h2></header>
            <div class="pc-card__body pc-stack pc-stack--md">
                <label><input type="checkbox" name="pages" value="1"<?= $checked('pages') ? ' checked' : '' ?><?= $invalid('pages') ?>> <?= $this->e(t('plugin::prime-builder.settings.pages')) ?></label>
                <label><input type="checkbox" name="posts" value="1"<?= $checked('posts') ? ' checked' : '' ?><?= $invalid('posts') ?>> <?= $this->e(t('plugin::prime-builder.settings.posts')) ?></label>
                <div class="pc-field">
                    <label class="pc-label" for="pb-other-types"><?= $this->e(t('plugin::prime-builder.settings.other_types')) ?></label>
                    <input class="pc-input" id="pb-other-types" name="other_types" type="text" maxlength="500" value="<?= $this->e((string) ($values['other_types'] ?? '')) ?>" aria-describedby="pb-other-help"<?= $invalid('other_types') ?>>
                    <p class="pc-field__help" id="pb-other-help"><?= $this->e(t('plugin::prime-builder.settings.other_types_help')) ?></p>
                </div>
                <div class="pc-field">
                    <label class="pc-label" for="pb-tablet"><?= $this->e(t('plugin::prime-builder.settings.breakpoint_tablet')) ?></label>
                    <input class="pc-input pc-input--narrow" id="pb-tablet" name="breakpoint_tablet" type="number" min="600" max="2000" required value="<?= $this->e((string) ($values['breakpoint_tablet'] ?? '')) ?>" aria-describedby="pb-tablet-help"<?= $invalid('breakpoint_tablet') ?>>
                    <p class="pc-field__help" id="pb-tablet-help"><?= $this->e(t('plugin::prime-builder.settings.breakpoint_tablet_help')) ?></p>
                </div>
                <div class="pc-field">
                    <label class="pc-label" for="pb-mobile"><?= $this->e(t('plugin::prime-builder.settings.breakpoint_mobile')) ?></label>
                    <input class="pc-input pc-input--narrow" id="pb-mobile" name="breakpoint_mobile" type="number" min="320" max="1600" required value="<?= $this->e((string) ($values['breakpoint_mobile'] ?? '')) ?>" aria-describedby="pb-mobile-help"<?= $invalid('breakpoint_mobile') ?>>
                    <p class="pc-field__help" id="pb-mobile-help"><?= $this->e(t('plugin::prime-builder.settings.breakpoint_mobile_help')) ?></p>
                </div>
                <div class="pc-field">
                    <label class="pc-label" for="pb-public-fields"><?= $this->e(t('plugin::prime-builder.settings.public_fields')) ?></label>
                    <textarea class="pc-input" id="pb-public-fields" name="public_fields" rows="4" maxlength="8192" aria-describedby="pb-public-fields-help"<?= $invalid('public_fields') ?>><?= $this->e((string) ($values['public_fields'] ?? '')) ?></textarea>
                    <p class="pc-field__help" id="pb-public-fields-help"><?= $this->e(t('plugin::prime-builder.settings.public_fields_help')) ?></p>
                </div>
                <div class="pc-field">
                    <label class="pc-label" for="pb-notification-email"><?= $this->e(t('plugin::prime-builder.settings.notification_email')) ?></label>
                    <input class="pc-input" id="pb-notification-email" name="notification_email" type="email" maxlength="254" value="<?= $this->e((string) ($values['notification_email'] ?? '')) ?>" aria-describedby="pb-notification-email-help"<?= $invalid('notification_email') ?>>
                    <p class="pc-field__help" id="pb-notification-email-help"><?= $this->e(t('plugin::prime-builder.settings.notification_email_help')) ?></p>
                </div>
                <div class="pc-field">
                    <label class="pc-label" for="pb-submission-retention"><?= $this->e(t('plugin::prime-builder.settings.submission_retention_days')) ?></label>
                    <input class="pc-input pc-input--narrow" id="pb-submission-retention" name="submission_retention_days" type="number" min="1" max="3650" required value="<?= $this->e((string) ($values['submission_retention_days'] ?? '')) ?>" aria-describedby="pb-submission-retention-help"<?= $invalid('submission_retention_days') ?>>
                    <p class="pc-field__help" id="pb-submission-retention-help"><?= $this->e(t('plugin::prime-builder.settings.submission_retention_days_help')) ?></p>
                </div>
                <?php foreach (['upload_file_kb' => [1, 8192], 'upload_total_kb' => [1, 8192], 'upload_max_files' => [1, 4]] as $name => [$min, $max]): ?>
                    <div class="pc-field">
                        <label class="pc-label" for="pb-<?= $this->e($name) ?>"><?= $this->e(t('plugin::prime-builder.settings.' . $name)) ?></label>
                        <input class="pc-input pc-input--narrow" id="pb-<?= $this->e($name) ?>" name="<?= $this->e($name) ?>" type="number" min="<?= $min ?>" max="<?= $max ?>" required value="<?= $this->e((string) ($values[$name] ?? '')) ?>" aria-describedby="pb-<?= $this->e($name) ?>-help"<?= $invalid($name) ?>>
                        <p class="pc-field__help" id="pb-<?= $this->e($name) ?>-help"><?= $this->e(t('plugin::prime-builder.settings.' . $name . '_help')) ?></p>
                    </div>
                <?php endforeach; ?>
                <?php if ($uploadWarnings !== []): ?>
                    <div class="pc-alert pc-alert--warning" role="status">
                        <strong><?= $this->e(t('plugin::prime-builder.settings.upload_warnings')) ?></strong>
                        <ul><?php foreach ($uploadWarnings as $warning): ?><li><?= $this->e(t('plugin::prime-builder.settings.upload_warning_' . $warning)) ?></li><?php endforeach; ?></ul>
                    </div>
                <?php endif; ?>
                <div class="pc-field">
                    <label class="pc-label" for="pb-editor-css"><?= $this->e(t('plugin::prime-builder.settings.editor_css')) ?></label>
                    <textarea class="pc-input" id="pb-editor-css" name="editor_css" rows="4" maxlength="512" aria-describedby="pb-editor-css-help"<?= $invalid('editor_css') ?>><?= $this->e((string) ($values['editor_css'] ?? '')) ?></textarea>
                    <p class="pc-field__help" id="pb-editor-css-help"><?= $this->e(t('plugin::prime-builder.settings.editor_css_help')) ?></p>
                </div>
            </div>
        </section>
        <section class="pc-card" aria-labelledby="pb-kit-title">
            <header class="pc-card__header"><h2 class="pc-card__title" id="pb-kit-title"><?= $this->e(t('plugin::prime-builder.settings.kit')) ?></h2></header>
            <div class="pc-card__body pc-stack pc-stack--md">
                <p><?= $this->e(t('plugin::prime-builder.settings.kit_help')) ?></p>
                <div class="pc-field">
                    <label class="pc-label" for="pb-font-loading"><?= $this->e(t('plugin::prime-builder.settings.font_loading')) ?></label>
                    <select class="pc-input" id="pb-font-loading" name="font_loading" aria-describedby="pb-font-loading-help"<?= $invalid('font_loading') ?>>
                        <option value="theme"<?= ($values['font_loading'] ?? 'theme') === 'theme' ? ' selected' : '' ?>><?= $this->e(t('plugin::prime-builder.settings.font_theme')) ?></option>
                        <option value="inter_local"<?= ($values['font_loading'] ?? '') === 'inter_local' ? ' selected' : '' ?>><?= $this->e(t('plugin::prime-builder.settings.font_inter_local')) ?></option>
                    </select>
                    <p class="pc-field__help" id="pb-font-loading-help"><?= $this->e(t('plugin::prime-builder.settings.font_loading_help')) ?></p>
                </div>
                <div class="pc-field">
                    <label class="pc-label" for="pb-kit-primary"><?= $this->e(t('plugin::prime-builder.settings.kit_primary')) ?></label>
                    <input class="pc-input pc-input--narrow" id="pb-kit-primary" name="kit_primary" type="text" pattern="#[a-fA-F0-9]{3}([a-fA-F0-9]{3})?" required value="<?= $this->e((string) ($values['kit_primary'] ?? '')) ?>"<?= $invalid('kit_primary') ?>>
                </div>
                <div class="pc-field">
                    <label class="pc-label" for="pb-kit-text"><?= $this->e(t('plugin::prime-builder.settings.kit_text')) ?></label>
                    <input class="pc-input pc-input--narrow" id="pb-kit-text" name="kit_text" type="text" pattern="#[a-fA-F0-9]{3}([a-fA-F0-9]{3})?" required value="<?= $this->e((string) ($values['kit_text'] ?? '')) ?>"<?= $invalid('kit_text') ?>>
                </div>
                <div class="pc-field">
                    <label class="pc-label" for="pb-kit-size"><?= $this->e(t('plugin::prime-builder.settings.kit_font_size')) ?></label>
                    <input class="pc-input pc-input--narrow" id="pb-kit-size" name="kit_font_size" type="number" min="12" max="28" required value="<?= $this->e((string) ($values['kit_font_size'] ?? '')) ?>"<?= $invalid('kit_font_size') ?>>
                </div>
            </div>
            <div class="pc-card__footer"><button class="pc-btn pc-btn--primary" type="submit"><?= $this->e(t('plugin::prime-builder.ui.save')) ?></button></div>
        </section>
    </form>
</div>
<?php $this->stop(); ?>
