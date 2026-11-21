<?php

declare(strict_types=1);
$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$colors = is_array($kit['colors'] ?? null) ? $kit['colors'] : [];
$typo = is_array($kit['typography'] ?? null) ? $kit['typography'] : [];
$layout = is_array($kit['layout'] ?? null) ? $kit['layout'] : [];
?>
<div class="pb-admin">
    <h1><?= $e($title ?? 'Kit du site') ?></h1>
    <form method="post" action="/admin/prime-builder/kit" class="pc-form-narrow">
        <?= function_exists('csrf_field') ? csrf_field() : '' ?>
        <label>Couleur primaire <input type="color" name="primary_color" value="<?= $e($colors['primary'] ?? '#ED6647') ?>"></label>
        <label>Couleur secondaire <input type="color" name="secondary_color" value="<?= $e($colors['secondary'] ?? '#1E293B') ?>"></label>
        <label>Couleur du texte <input type="color" name="text_color" value="<?= $e($colors['text'] ?? '#334155') ?>"></label>
        <label>Couleur d’accent <input type="color" name="accent_color" value="<?= $e($colors['accent'] ?? '#C9441F') ?>"></label>
        <label>Police des titres <input type="text" name="heading_font" value="<?= $e($typo['heading_font'] ?? 'Plus Jakarta Sans, sans-serif') ?>"></label>
        <label>Police du corps <input type="text" name="body_font" value="<?= $e($typo['body_font'] ?? 'Inter, sans-serif') ?>"></label>
        <label>Largeur du contenu (px) <input type="number" name="content_width" value="<?= (int) ($layout['content_width'] ?? 1200) ?>"></label>
        <label>Espacement des conteneurs (px) <input type="number" name="container_gap" value="<?= (int) ($layout['container_gap'] ?? 20) ?>"></label>
        <button type="submit" class="pc-btn pc-btn--primary">Enregistrer le kit</button>
    </form>
</div>
