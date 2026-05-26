<?php

/*
 * PHP-CS-Fixer config for woocommerce-maya-gateway.
 * Mirrors the repo-root style (PER + aligned operators).
 */

$config = new PhpCsFixer\Config();

return $config
    ->setRules([
        '@PER'                   => true,
        'binary_operator_spaces' => ['default' => 'align_single_space_minimal'],
        'ordered_imports'        => ['case_sensitive' => true],
    ])
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->in(__DIR__)
            ->exclude([
                'vendor',
                '.phpunit.cache',
                'node_modules',
            ]),
    );
