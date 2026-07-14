<?php

$finder = PhpCsFixer\Finder::create()
    ->in(__DIR__)
    ->ignoreDotFiles(true)
    ->ignoreVCS(true)
    ->exclude(['var', 'vendor'])
    ->files()
    ->name('*.php')
;

return (new PhpCsFixer\Config())
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect())
    ->setUsingCache(true)
    ->setRiskyAllowed(true)
    ->setFinder($finder)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        '@PHPUnit48Migration:risky' => true,
        'fopen_flags' => false,
        'protected_to_private' => false,
        // Part of future @Symfony ruleset in PHP-CS-Fixer To be removed from the config file once upgrading
        'phpdoc_types_order' => ['null_adjustment' => 'always_last', 'sort_algorithm' => 'none'],
        'single_line_throw' => false,
        // this must be disabled because the output of some tests include NBSP characters
        'non_printable_character' => false,
        'blank_line_between_import_groups' => false,
        'no_trailing_comma_in_singleline' => false,
        'nullable_type_declaration_for_default_null_value' => true,
        'phpdoc_to_comment' => false,
        // Override @Symfony ruleset to keep mixed return type for PHPStan
        'no_superfluous_phpdoc_tags' => ['allow_hidden_params' => true, 'allow_mixed' => true, 'remove_inheritdoc' => true],

        // --- Deliberate style choices of this bundle (kept out of @Symfony) ---
        // The codebase consistently uses non-Yoda comparisons ($x === null),
        // unqualified global calls (count(), not \count()), plain arrow
        // functions and its own operator spacing. These are intentional and
        // consistent, so the linter matches them instead of rewriting them.
        'yoda_style' => false,
        'native_function_invocation' => false,
        'native_constant_invocation' => false,
        'static_lambda' => false,
        'concat_space' => false,
        'binary_operator_spaces' => false,
        'global_namespace_import' => false,
        'fully_qualified_strict_types' => false,
        'increment_style' => false,
        // The bundle writes arrow/closure declarations without the extra space
        // (fn(...) and function(...)), so keep the default spacing untouched.
        'function_declaration' => false,
    ])
;
