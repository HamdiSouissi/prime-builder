<?php

declare(strict_types=1);

/** @var \Prime\Site\PageContext $context */
echo \Prime\Kernel\Application::getInstance()->get(\PrimeBuilder\Theme\LiveEntry::class)->render($context);
