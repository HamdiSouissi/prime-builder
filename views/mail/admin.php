<?php

declare(strict_types=1);

/** @var \Prime\View\TemplateContext $this */
/** @var string $site_name */
/** @var string $inbox_url */
$this->layout('mail::layout', ['siteName' => $site_name]);
?>
<?php $this->start('content'); ?>
<h1><?= $this->e($this->t('plugin::prime-builder.mail.admin_subject')) ?></h1>
<p><?= $this->e($this->t('plugin::prime-builder.mail.admin_body')) ?></p>
<p><a href="<?= $this->e($inbox_url) ?>"><?= $this->e($this->t('plugin::prime-builder.mail.admin_action')) ?></a></p>
<?php $this->stop(); ?>
