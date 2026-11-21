<?php

declare(strict_types=1);
$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<div class="pb-admin">
    <div class="pb-admin__header">
        <h1><?= $e($title ?? 'Modèles Prime Builder') ?></h1>
        <nav class="pb-admin__nav" aria-label="Navigation Prime Builder">
            <a href="/admin/prime-builder" class="pc-btn pc-btn--secondary">Modèles</a>
            <a href="/admin/prime-builder/submissions" class="pc-btn pc-btn--ghost">Envois</a>
            <a href="/admin/prime-builder/kit" class="pc-btn pc-btn--ghost">Kit du site</a>
            <a href="/admin/prime-builder/settings" class="pc-btn pc-btn--ghost">Réglages</a>
        </nav>
    </div>

    <form method="post" action="/admin/prime-builder/templates" class="pb-admin__form">
        <?= function_exists('csrf_field') ? csrf_field() : '' ?>
        <input type="text" name="title" placeholder="Nom du modèle" required>
        <select name="type">
            <option value="section">Section</option>
            <option value="page">Page</option>
            <option value="widget">Widget global</option>
            <option value="popup">Popup</option>
            <option value="header">En-tête (Theme Builder)</option>
            <option value="footer">Pied de page (Theme Builder)</option>
            <option value="single">Contenu seul (Theme Builder)</option>
            <option value="archive">Archive (Theme Builder)</option>
            <option value="search">Recherche (Theme Builder)</option>
            <option value="404">404 (Theme Builder)</option>
        </select>
        <button type="submit" class="pc-btn pc-btn--primary">Créer</button>
    </form>

    <table class="pc-table pc-table--stacked">
        <thead>
            <tr>
                <th>Titre</th>
                <th>Type</th>
                <th>Statut</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($templates ?? [] as $tpl) : ?>
                <tr>
                    <td class="pc-table__primary"><?= $e($tpl['title'] ?? '') ?></td>
                    <td data-prime-label="Type"><?= $e($tpl['type'] ?? '') ?></td>
                    <td data-prime-label="Statut"><?= $e($tpl['status'] ?? '') ?></td>
                    <td class="pc-table__actions">
                        <a href="/admin/prime-builder/templates/<?= (int) ($tpl['id'] ?? 0) ?>/export" class="pc-btn pc-btn--sm pc-btn--secondary">Exporter JSON</a>
                        <form method="post" action="/admin/prime-builder/templates/<?= (int) ($tpl['id'] ?? 0) ?>/delete" style="display:inline">
                            <?= function_exists('csrf_field') ? csrf_field() : '' ?>
                            <button type="submit" class="pc-btn pc-btn--sm pc-btn--danger">Supprimer</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
