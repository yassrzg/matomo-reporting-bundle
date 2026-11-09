<?php

declare(strict_types=1);

$finder = (new PhpCsFixer\Finder())->in([__DIR__.'/src', __DIR__.'/config', __DIR__.'/tests'])->exclude(['App/var']);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'declare_strict_types' => true,
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced', 'strict' => true],
    ])
    ->setFinder($finder);
