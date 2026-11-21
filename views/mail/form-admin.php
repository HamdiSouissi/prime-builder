<?php

declare(strict_types=1);
?>
<h1>Nouvel envoi de formulaire : <?= htmlspecialchars((string) ($formId ?? ''), ENT_QUOTES, 'UTF-8') ?></h1>
<ul>
<?php foreach (($fields ?? []) as $k => $v) : ?>
    <li><strong><?= htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8') ?> :</strong> <?= htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8') ?></li>
<?php endforeach; ?>
</ul>
