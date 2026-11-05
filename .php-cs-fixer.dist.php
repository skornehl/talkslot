<?php
/**
 * Coding standard (expert review P3-4): PSR-12, checked by dev/cs.sh and the CI workflow "Coding standard".
 * Two rules are left out because the code follows another, consistent style there:
 * - blank_line_after_opening_tag: every file starts with "<?php" and "declare(strict_types=1);" right below
 * - method_argument_space: long argument lists wrap after a comma, not one argument per line
 * Templates (HTML with PHP inside) and tests are not checked.
 */
declare(strict_types=1);

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__ . '/app/src', __DIR__ . '/bin', __DIR__ . '/public', __DIR__ . '/config'])
    ->append([__DIR__ . '/app/bootstrap.php', __DIR__ . '/app/helpers.php', __FILE__])
    ->name('*.php');

return (new PhpCsFixer\Config())
    ->setRules([
        '@PSR12' => true,
        'blank_line_after_opening_tag' => false,
        'method_argument_space' => false,
    ])
    ->setFinder($finder)
    ->setUsingCache(false)
    ->setUnsupportedPhpVersionAllowed(true); // the dev and CI images may be newer than the fixer knows
