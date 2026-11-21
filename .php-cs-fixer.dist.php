<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()->in([__DIR__ . '/src', __DIR__ . '/views', __DIR__ . '/lang', __DIR__ . '/tests'])->name('*.php')->ignoreVCS(true);

return (new Config())->setRiskyAllowed(true)->setRules([
    '@PSR12' => true,
    'declare_strict_types' => true,
    'ordered_imports' => ['sort_algorithm' => 'alpha', 'imports_order' => ['class', 'function', 'const']],
    'no_unused_imports' => true,
    'array_syntax' => ['syntax' => 'short'],
    'single_quote' => true,
    'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
    'concat_space' => ['spacing' => 'one'],
])->setFinder($finder);
