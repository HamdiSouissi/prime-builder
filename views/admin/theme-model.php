<?php

declare(strict_types=1);

/** @var \Prime\View\TemplateContext $this */
/** @var string $pageHeading */
/** @var list<array{label: string, url: ?string}> $breadcrumbs */
/** @var array<string, mixed> $model */
/** @var string $themeConfig */
/** @var string|null $error */
$this->layout('admin::layouts/app');
$renderRule = function (string $group, string $type, string $value): void {
    ?>
    <div class="pb-theme-rule" data-pb-rule>
        <label class="pc-field"><span class="pc-label"><?= $this->e(t('plugin::prime-builder.theme.rule_type')) ?></span>
            <select class="pc-input" data-pb-rule-type name="<?= $this->e($group) ?>_type[]">
                <?php foreach (\PrimeBuilder\Theme\Conditions::TYPES as $candidate): ?>
                    <option value="<?= $this->e($candidate) ?>"<?= $candidate === $type ? ' selected' : '' ?>><?= $this->e(t('plugin::prime-builder.theme.rule_' . $candidate)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <input type="hidden" data-pb-rule-value name="<?= $this->e($group) ?>_value[]" value="<?= $this->e($value) ?>">
        <div data-pb-rule-controls></div>
        <button class="pc-btn pc-btn--secondary" type="button" data-pb-remove-rule><?= $this->e(t('plugin::prime-builder.theme.remove_rule')) ?></button>
    </div>
    <?php
};
?>
<?php $this->start('content'); ?>
<?= $this->raw($this->insert('admin::components/page-header', [
    'title' => $pageHeading,
    'subtitle' => (string) $model['name'],
    'breadcrumbs' => $breadcrumbs,
])) ?>
<div class="pc-form-constrained" data-pb-theme-rules>
    <textarea hidden data-pb-theme-config><?= $this->e($themeConfig) ?></textarea>
    <form method="post" action="/admin/prime-builder/theme/<?= $this->e((string) $model['id']) ?>" class="pc-stack pc-stack--lg">
        <?= $this->raw($this->csrfField()) ?>
        <?php if ($error !== null): ?><div class="pc-alert pc-alert--danger" role="alert"><?= $this->e(t('plugin::prime-builder.theme.' . $error)) ?></div><?php endif; ?>
        <section class="pc-card">
            <div class="pc-card__body pc-stack pc-stack--md">
                <p><strong><?= $this->e(t('plugin::prime-builder.theme.kind_' . $model['kind'])) ?></strong></p>
                <p><?= $this->e(t('plugin::prime-builder.' . ($model['kind'] === 'popup' ? 'popup.conditions_help' : 'theme.conditions_help'))) ?></p>
                <div class="pc-field"><label class="pc-label" for="pb-name"><?= $this->e(t('plugin::prime-builder.theme.name')) ?></label><input class="pc-input" id="pb-name" name="name" maxlength="100" required value="<?= $this->e((string) $model['name']) ?>"></div>
                <div class="pc-field"><label class="pc-label" for="pb-priority"><?= $this->e(t('plugin::prime-builder.theme.priority')) ?></label><input class="pc-input" id="pb-priority" name="priority" type="number" min="-1000" max="1000" required value="<?= $this->e((string) $model['priority']) ?>" aria-describedby="pb-priority-help"><p class="pc-field__help" id="pb-priority-help"><?= $this->e(t('plugin::prime-builder.' . ($model['kind'] === 'popup' ? 'popup.priority_help' : 'theme.priority_help'))) ?></p></div>
                <label><input type="checkbox" name="enabled" value="1"<?= $model['enabled'] ? ' checked' : '' ?>> <?= $this->e(t('plugin::prime-builder.theme.enabled')) ?></label>
                <?php if ($model['kind'] === 'popup' && is_array($model['popup'] ?? null)): $popup = $model['popup']; ?>
                    <fieldset class="pb-theme-rules pc-stack pc-stack--md">
                        <legend><?= $this->e(t('plugin::prime-builder.popup.settings')) ?></legend>
                        <p><?= $this->e(t('plugin::prime-builder.popup.consent_help')) ?></p>
                        <div class="pc-field"><label class="pc-label" for="pb-popup-trigger"><?= $this->e(t('plugin::prime-builder.popup.trigger')) ?></label><select class="pc-input" id="pb-popup-trigger" name="popup_trigger"><?php foreach (\PrimeBuilder\Popup\Options::TRIGGERS as $trigger): ?><option value="<?= $this->e($trigger) ?>"<?= $popup['trigger'] === $trigger ? ' selected' : '' ?>><?= $this->e(t('plugin::prime-builder.popup.trigger_' . $trigger)) ?></option><?php endforeach; ?></select></div>
                        <div class="pc-field"><label class="pc-label" for="pb-popup-delay"><?= $this->e(t('plugin::prime-builder.popup.delay')) ?></label><input class="pc-input" id="pb-popup-delay" name="popup_delay" type="number" min="0" max="120" required value="<?= $this->e((string) $popup['delay']) ?>"></div>
                        <div class="pc-field"><label class="pc-label" for="pb-popup-scroll"><?= $this->e(t('plugin::prime-builder.popup.scroll')) ?></label><input class="pc-input" id="pb-popup-scroll" name="popup_scroll" type="number" min="1" max="100" required value="<?= $this->e((string) $popup['scroll']) ?>"></div>
                        <div class="pc-field"><label class="pc-label" for="pb-popup-idle"><?= $this->e(t('plugin::prime-builder.popup.idle')) ?></label><input class="pc-input" id="pb-popup-idle" name="popup_idle" type="number" min="5" max="1800" required value="<?= $this->e((string) $popup['idle']) ?>"></div>
                        <div class="pc-field"><label class="pc-label" for="pb-popup-frequency"><?= $this->e(t('plugin::prime-builder.popup.frequency')) ?></label><select class="pc-input" id="pb-popup-frequency" name="popup_frequency"><?php foreach (\PrimeBuilder\Popup\Options::FREQUENCIES as $frequency): ?><option value="<?= $this->e($frequency) ?>"<?= $popup['frequency'] === $frequency ? ' selected' : '' ?>><?= $this->e(t('plugin::prime-builder.popup.frequency_' . $frequency)) ?></option><?php endforeach; ?></select></div>
                        <div class="pc-field"><label class="pc-label" for="pb-popup-days"><?= $this->e(t('plugin::prime-builder.popup.days')) ?></label><input class="pc-input" id="pb-popup-days" name="popup_days" type="number" min="1" max="365" required value="<?= $this->e((string) $popup['days']) ?>"></div>
                        <p><?= $this->e(t('plugin::prime-builder.popup.link_help')) ?> <code>#pb-popup-<?= $this->e((string) $model['id']) ?></code></p>
                    </fieldset>
                <?php endif; ?>
                <?php foreach (['include', 'exclude'] as $group): ?>
                    <fieldset class="pb-theme-rules" data-pb-rule-group="<?= $this->e($group) ?>">
                        <legend><?= $this->e(t('plugin::prime-builder.theme.' . $group . '_rules')) ?></legend>
                        <div class="pc-stack pc-stack--md" data-pb-rule-list>
                            <?php foreach ($model[$group] as $rule): ?>
                                <?php $renderRule($group, $rule['type'], $rule['value']); ?>
                            <?php endforeach; ?>
                        </div>
                        <button class="pc-btn pc-btn--secondary" type="button" data-pb-add-rule><?= $this->e(t('plugin::prime-builder.theme.add_' . $group)) ?></button>
                    </fieldset>
                <?php endforeach; ?>
            </div>
            <div class="pc-card__footer pb-theme-actions"><button class="pc-btn pc-btn--primary" type="submit"><?= $this->e(t('plugin::prime-builder.ui.save')) ?></button><a class="pc-btn pc-btn--secondary" href="/admin/prime-builder/theme/<?= $this->e((string) $model['id']) ?>/edit"><?= $this->e(t('plugin::prime-builder.theme.edit_layout')) ?></a></div>
        </section>
    </form>
    <template data-pb-rule-template><?php $renderRule('template', 'all', ''); ?></template>
</div>
<?php $this->stop(); ?>
