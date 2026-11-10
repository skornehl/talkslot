<?php
// Usability: accessibility (expert review P2-2; see README.md in this directory)
echo "Accessibility (expert review P2-2)\n";
$a11yCss = (string) file_get_contents(APP_ROOT . '/public/assets/css/app.css');
$a11yTokens = static function (string $block): array {
    preg_match_all('/--([a-z-]+):\s*(#[0-9a-fA-F]{6})\b/', $block, $m);
    return array_combine($m[1], $m[2]);
};
$a11yMedia = strpos($a11yCss, '@media screen and (prefers-color-scheme: dark)');
$a11yForced = strpos($a11yCss, ':root[data-theme="dark"] {');
check('the stylesheet has the light and both dark blocks the contrast check reads', $a11yMedia !== false && $a11yForced !== false && $a11yForced > $a11yMedia);
$a11yThemes = ['light' => $a11yTokens(substr($a11yCss, 0, $a11yMedia)), 'dark (system)' => $a11yTokens(substr($a11yCss, $a11yMedia, $a11yForced - $a11yMedia)),
    'dark (chosen)' => $a11yTokens(substr($a11yCss, $a11yForced, (int) strpos($a11yCss, '}', $a11yForced) - $a11yForced))];
$a11yLuminance = static function (string $hex): float {
    $c = array_map(static fn (string $h): float => hexdec($h) / 255, str_split(substr($hex, 1), 2));
    [$r, $g, $b] = array_map(static fn (float $v): float => $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4, $c);
    return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
};
$a11yRatio = static function (string $a, string $b) use ($a11yLuminance): float {
    [$l1, $l2] = [$a11yLuminance($a), $a11yLuminance($b)];
    return (max($l1, $l2) + 0.05) / (min($l1, $l2) + 0.05);
};
$a11yLow = [];
foreach ($a11yThemes as $theme => $t) {
    foreach (['focus', 'input-border'] as $fg) {
        foreach (['bg', 'surface'] as $bg) {
            if (!isset($t[$fg], $t[$bg])) {
                $a11yLow[] = "$theme: --$fg or --$bg missing or not a #rrggbb colour";
                continue;
            }
            if ($a11yRatio($t[$fg], $t[$bg]) < 3) {
                $a11yLow[] = "$theme: --$fg on --$bg " . round($a11yRatio($t[$fg], $t[$bg]), 2);
            }
        }
    }
}
check('WCAG 1.4.11: focus ring and borders of inputs and slot tiles at least 3:1 against page and card, in all themes' . ($a11yLow ? ' (' . implode('; ', $a11yLow) . ')' : ''),
    count($a11yThemes) === 3 && !$a11yLow && str_contains($a11yCss, 'border: 2px solid var(--input-border); border-radius: 8px; cursor: pointer;'));
check('focus ring in the dark header: at least 3:1 in light and dark',
    str_contains($a11yCss, '.site-header :focus-visible { outline-color: #f0a500; }')
    && $a11yRatio('#f0a500', $a11yThemes['light']['primary']) >= 3 && $a11yRatio('#f0a500', $a11yThemes['dark (system)']['header-bg']) >= 3);
