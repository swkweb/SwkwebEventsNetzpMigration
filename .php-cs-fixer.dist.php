<?php declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;
use PhpCsFixer\Runner\Parallel\ParallelConfigFactory;

/*
 * This document has been generated with
 * https://mlocati.github.io/php-cs-fixer-configurator/#version:3.95.11|configurator
 * you can change this configuration by importing this file.
 */
return (new Config())
    ->setParallelConfig(ParallelConfigFactory::detect())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PhpCsFixer:risky' => true,
        '@PHP8x2Migration' => true,
        '@PHP8x2Migration:risky' => true,
        '@PhpCsFixer' => true,
        // PHP attributes declared without arguments must (not) be followed by empty parentheses.
        'attribute_empty_parentheses' => true,
        // Ensure there is no code on the same line as the PHP open tag and it is followed by a blank line.
        'blank_line_after_opening_tag' => false,
        // An empty line feed must precede any configured statement.
        'blank_line_before_statement' => ['statements' => ['break', 'case', 'continue', 'declare', 'default', 'exit', 'goto', 'include', 'include_once', 'require', 'require_once', 'return', 'switch', 'throw', 'try', 'yield_from']],
        // Using `isset($var) &&` multiple times should be done in one call.
        'combine_consecutive_issets' => false,
        // Calling `unset` on multiple items should be done in one call.
        'combine_consecutive_unsets' => false,
        // Concatenation should be spaced according to configuration.
        'concat_space' => ['spacing' => 'one'],
        // The first argument of `DateTime::createFromFormat` method must start with `!`.
        'date_time_create_from_format_call' => true,
        // Class `DateTimeImmutable` should be used instead of `DateTime`.
        'date_time_immutable' => true,
        // Removes the leading part of fully qualified symbol references if a given symbol is imported or belongs to the current namespace.
        'fully_qualified_strict_types' => true,
        // Pre- or post-increment and decrement operators should be used if possible.
        'increment_style' => ['style' => 'post'],
        // Ensure there is no code on the same line as the PHP open tag.
        'linebreak_after_opening_tag' => false,
        // Promoted properties must be on separate lines.
        'multiline_promoted_properties' => true,
        // Add leading `\` before constant invocation of internal constant to speed up resolving. Constant name match is case-sensitive, except for `null`, `false` and `true`.
        'native_constant_invocation' => ['strict' => false],
        // Add leading `\` before function invocation to speed up resolving.
        'native_function_invocation' => false,
        // All items of the given PHPDoc tags must be either left-aligned or (by default) aligned vertically.
        'phpdoc_align' => ['align' => 'left'],
        // Docblocks should only be used on structural elements.
        'phpdoc_to_comment' => ['ignored_tags' => ['deprecated', 'var']],
        // Takes `@param` annotations of non-mixed types and adjusts accordingly the function signature.
        'phpdoc_to_param_type' => true,
        // Takes `@var` annotation of non-mixed types and adjusts accordingly the property signature.
        'phpdoc_to_property_type' => true,
        // Takes `@return` annotation of non-mixed types and adjusts accordingly the function signature.
        'phpdoc_to_return_type' => true,
        // Sorts PHPDoc types.
        'phpdoc_types_order' => ['sort_algorithm' => 'alpha', 'null_adjustment' => 'always_last'],
        // Arguments lists, array destructuring lists, arrays that are multi-line, `match`-lines and parameters lists must have a trailing comma.
        'trailing_comma_in_multiline' => ['after_heredoc' => true, 'elements' => ['arguments', 'array_destructuring', 'arrays', 'match', 'parameters']],
        // Anonymous functions with return as the only statement must use arrow functions.
        'use_arrow_functions' => false,
        // Write conditions in Yoda style (`true`), non-Yoda style (`['equal' => false, 'identical' => false, 'less_and_greater' => false]`) or ignore those conditions (`null`) based on configuration.
        'yoda_style' => false,
    ])
    ->setFinder(
        Finder::create()
            ->in(__DIR__)
            ->append([__FILE__]),
    )
;
