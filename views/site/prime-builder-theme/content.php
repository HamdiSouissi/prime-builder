<?php

declare(strict_types=1);

/** @var \Prime\Site\View\SiteView $site */
/** @var \Prime\Site\PageContext $context */
// The CMS treats a doctype as a complete document and will not wrap this in
// the theme layout. Its public API names the script footer method foot().
$content = \Prime\Kernel\Application::getInstance()->get(\Prime\Hooks\Hooks::class)
    ->apply('prime_builder.theme_content', '', $context);
?>
<!DOCTYPE html>
<html lang="<?= $site->e($site->locale()) ?>" dir="<?= $site->e($site->direction()) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?= $site->head() ?>
</head>
<body class="<?= $site->bodyClass() ?>">
    <a class="skip-link" href="#main-content"><?= $site->t('site.skip_to_content') ?></a>
    <?= $site->partial('header') ?>
    <main id="main-content" class="site-main" tabindex="-1"<?= $context->isPreview ? ' data-pb-editor-content' : '' ?>><?= is_string($content) ? $content : '' ?></main>
    <?= $site->partial('footer') ?>
    <?= $site->foot() ?>
</body>
</html>
