<?php

declare(strict_types=1);
$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<div class="pb-admin">
    <h1><?= $e($title ?? 'Réglages Prime Builder') ?></h1>
    <form method="post" action="/admin/prime-builder/settings" class="pc-form-narrow">
        <?= function_exists('csrf_field') ? csrf_field() : '' ?>
        <label>Types modifiables (séparés par virgule)
            <input type="text" name="content_types" value="<?= $e($settings['content_types'] ?? 'page') ?>">
        </label>
        <label>Point de rupture tablette (px)
            <input type="number" name="breakpoint_tablet" value="<?= (int) ($settings['breakpoint_tablet'] ?? 1024) ?>">
        </label>
        <label>Point de rupture mobile (px)
            <input type="number" name="breakpoint_mobile" value="<?= (int) ($settings['breakpoint_mobile'] ?? 767) ?>">
        </label>
        <label>Conservation des envois (jours)
            <input type="number" name="submission_retention_days" value="<?= (int) ($settings['submission_retention_days'] ?? 90) ?>">
        </label>
        <button type="submit" class="pc-btn pc-btn--primary">Enregistrer</button>
    </form>
</div>
