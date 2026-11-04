<?php
// Parents, texts and privacy (see README.md in this directory)
echo "Privacy: no browser page translation in the admin area\n";
// The <html> line carries translate="no" and class="notranslate", the head the meta for Chrome. Works on the
// rendered page and on the template source (one tag per line).
$notranslate = static fn (string $html): bool => (bool) preg_match('/^<html\b.*\stranslate="no"(?=[\s>]).*\sclass="notranslate"/m', $html)
    && str_contains($html, '<meta name="google" content="notranslate">');
$adminPage = App\View::capture('admin/layout', ['content' => '<p>Max Muster</p>', 'title' => 'Runde', 'flash' => []]);
check('admin layout: translate="no", class and meta notranslate', $notranslate($adminPage) && str_contains($adminPage, '<html lang="de" translate="no"'));
$printPage = App\View::capture('admin/print', ['round' => ['id' => 1, 'title' => 'Elternsprechtag'], 'days' => [], 'showEmail' => true, 'dayFilter' => 0]);
check('print view: translate="no", class and meta notranslate', $notranslate($printPage));
$roots = array_filter(glob(APP_ROOT . '/app/templates/admin/*.php') ?: [], static fn (string $f): bool => str_contains((string) file_get_contents($f), '<html'));
$unmarked = array_filter($roots, static fn (string $f): bool => !$notranslate((string) file_get_contents($f)));
check('every standalone admin page is marked (' . count($roots) . ' templates' . ($unmarked ? ', missing: ' . implode(', ', array_map('basename', $unmarked)) : '') . ')',
    count($roots) >= 2 && !$unmarked);
// Parents may need a language beyond the six offered, and only see their own data there
check('parent pages stay translatable', !str_contains(App\View::capture('layout', ['content' => '', 'title' => 'T']), 'notranslate'));
