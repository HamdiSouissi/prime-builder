<?php

declare(strict_types=1);
$e = static fn (mixed $v): string => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<div class="pb-admin">
    <div class="pb-admin__header">
        <h1><?= $e($title ?? 'Envois de formulaires') ?></h1>
        <a href="/admin/prime-builder/submissions/export" class="pc-btn pc-btn--secondary">Exporter CSV</a>
    </div>

    <?php if (empty($submissions)) : ?>
        <p><?= $e(t('plugin::prime-builder.forms.no_submissions')) ?></p>
    <?php else : ?>
        <table class="pc-table pc-table--stacked">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Formulaire</th>
                    <th>Données</th>
                    <th>Date</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($submissions as $sub) : ?>
                    <tr>
                        <td class="pc-table__primary">#<?= (int) ($sub['id'] ?? 0) ?></td>
                        <td data-prime-label="Formulaire"><?= $e($sub['form_id'] ?? '') ?></td>
                        <td data-prime-label="Données"><code><?= $e($sub['data_json'] ?? '{}') ?></code></td>
                        <td data-prime-label="Date"><?= $e($sub['created_at'] ?? '') ?></td>
                        <td class="pc-table__actions">
                            <form method="post" action="/admin/prime-builder/submissions/<?= (int) ($sub['id'] ?? 0) ?>/delete" style="display:inline">
                                <?= function_exists('csrf_field') ? csrf_field() : '' ?>
                                <button type="submit" class="pc-btn pc-btn--sm pc-btn--danger">Supprimer</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
