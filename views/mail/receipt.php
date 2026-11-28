<?php

declare(strict_types=1);

/** @var \Prime\View\TemplateContext $this */
/** @var string $site_name */
$this->layout('mail::layout', ['siteName' => $site_name]);
?>
<?php $this->start('content'); ?>
<h1><?= $this->e($this->t('plugin::prime-builder.mail.receipt_subject')) ?></h1>
<p><?= $this->e($this->t('plugin::prime-builder.mail.receipt_body')) ?></p>
<?php $this->stop(); ?>
