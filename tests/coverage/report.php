<?php
/**
 * Line coverage report (expert review P1-14): merges what tests/coverage/prepend.php collected during the unit,
 * smoke and browser tests and prints the coverage per file and in total.
 *
 *   php tests/coverage/report.php [--min=<percent>] [--diff=<file>|-] [--github] [--markdown=<file>]
 *                                 [--dir=<raw data>] [--root=<checkout>]
 *
 * --min       fails (exit 1) if the total is below this percentage: the coverage must not go down
 * --diff      a unified diff (git diff), "-" = standard input: lists the changed lines that no test executes
 * --github    additionally as annotations of the workflow run (GitHub Actions)
 * --markdown  writes a summary in Markdown to this file (the job summary in CI)
 * --dir       raw data, default <temp>/talkslot-coverage
 * --root      checkout whose files are counted, default the one this script belongs to
 *
 * Counted are the PHP files of app/src, app/templates, bin and public plus app/bootstrap.php and app/helpers.php.
 * A file that no test loads at all fails the report as well (except those in UNLOADED_OK): its lines would
 * otherwise be missing from the total.
 */
declare(strict_types=1);

const UNLOADED_OK = [
    // Template of app_root.php in the web directory; tests/deploy.sh loads the copy the build makes of it
    'bin/app-root.php',
];

$opt = getopt('', ['min:', 'diff:', 'github', 'markdown:', 'dir:', 'root:']);
$root = rtrim((string) ($opt['root'] ?? dirname(__DIR__, 2)), '/');
$dir = (string) ($opt['dir'] ?? sys_get_temp_dir() . '/talkslot-coverage');
$min = isset($opt['min']) ? (float) $opt['min'] : null;
$github = isset($opt['github']);

// The files that count, relative to the checkout
$files = [];
$scan = static function (string $path) use (&$scan, &$files, $root): void {
    foreach (glob($root . '/' . $path . '/*') ?: [] as $f) {
        if (is_dir($f)) {
            $scan(substr($f, strlen($root) + 1));
        } elseif (str_ends_with($f, '.php')) {
            $files[substr($f, strlen($root) + 1)] = null;
        }
    }
};
foreach (['app/src', 'app/templates', 'bin', 'public'] as $path) {
    $scan($path);
}
foreach (['app/bootstrap.php', 'app/helpers.php'] as $f) {
    if (is_file("$root/$f")) {
        $files[$f] = null;
    }
}
foreach ($files as $f => $_) {
    if (str_starts_with($f, 'public/assets/')) {
        unset($files[$f]);
    }
}
ksort($files);

// Merge: a line counts as covered if any process executed it
$raw = glob($dir . '/*.json') ?: [];
foreach ($raw as $json) {
    $data = json_decode((string) file_get_contents($json), true);
    foreach (is_array($data) ? $data : [] as $path => $lines) {
        $rel = str_starts_with((string) $path, $root . '/') ? substr((string) $path, strlen($root) + 1) : null;
        if ($rel === null || !array_key_exists($rel, $files) || !is_array($lines)) {
            continue;
        }
        foreach ($lines as $line => $hit) {
            $files[$rel][(int) $line] = max($files[$rel][(int) $line] ?? -1, $hit > 0 ? 1 : -1);
        }
    }
}

/** Line numbers as ranges: [3, 4, 5, 9] → [[3, 5], [9, 9]] */
$ranges = static function (array $lines): array {
    sort($lines);
    $out = [];
    foreach ($lines as $l) {
        if ($out && $out[count($out) - 1][1] === $l - 1) {
            $out[count($out) - 1][1] = $l;
        } else {
            $out[] = [$l, $l];
        }
    }
    return $out;
};
$fmt = static fn (array $ranges): string => implode(',', array_map(static fn (array $r): string => $r[0] === $r[1] ? (string) $r[0] : $r[0] . '-' . $r[1], $ranges));

$rows = [];
$covered = 0;
$total = 0;
$unloaded = [];
foreach ($files as $f => $lines) {
    if ($lines === null) {
        $unloaded[] = $f;
        continue;
    }
    ksort($lines);
    $c = count(array_filter($lines, static fn (int $h): bool => $h > 0));
    $covered += $c;
    $total += count($lines);
    $rows[] = ['file' => $f, 'covered' => $c, 'total' => count($lines), 'missing' => $ranges(array_keys($lines, -1, true))];
}
usort($rows, static fn (array $a, array $b): int => [$a['total'] ? $a['covered'] / $a['total'] : 1, $a['file']] <=> [$b['total'] ? $b['covered'] / $b['total'] : 1, $b['file']]);
$percent = $total > 0 ? 100 * $covered / $total : 0.0;
$pct = static fn (float $p): string => number_format(floor($p * 10) / 10, 1) . ' %'; // rounded down: 99.96 is not 100

// Changed lines (lines of the new version in the diff) that no test executed
$changed = [];
if (isset($opt['diff'])) {
    $diff = (string) file_get_contents($opt['diff'] === '-' ? 'php://stdin' : (string) $opt['diff']);
    $file = null;
    $line = 0;
    $old = 0; // lines of the hunk still to come, old and new version
    $new = 0;
    foreach (preg_split('/\r?\n/', $diff) ?: [] as $d) {
        if ($old > 0 || $new > 0) {
            $c = $d[0] ?? ' ';
            if ($c === '+') {
                if ($file !== null) {
                    $changed[$file][] = $line;
                }
                $line++;
                $new--;
            } elseif ($c === '-') {
                $old--;
            } elseif ($c !== '\\') { // context line ("\ No newline at end of file" counts for neither)
                $line++;
                $old--;
                $new--;
            }
        } elseif (str_starts_with($d, '+++ ')) {
            $file = preg_match('#^\+\+\+ (?:b/)?(.+?)\t?$#', $d, $m) && $m[1] !== '/dev/null' ? $m[1] : null;
        } elseif (preg_match('/^@@ -\d+(?:,(\d+))? \+(\d+)(?:,(\d+))? @@/', $d, $m)) {
            $old = $m[1] === '' ? 1 : (int) $m[1];
            $line = (int) $m[2];
            $new = isset($m[3]) && $m[3] !== '' ? (int) $m[3] : 1;
        }
    }
}
$uncoveredChanged = [];
foreach ($changed as $f => $lines) {
    if (!array_key_exists($f, $files)) {
        continue; // not a counted file (tests, documentation, JavaScript, …)
    }
    $miss = $files[$f] === null ? $lines : array_values(array_filter($lines, static fn (int $l): bool => ($files[$f][$l] ?? 0) === -1));
    if ($miss) {
        $uncoveredChanged[$f] = $ranges($miss);
    }
}

$unexpected = array_values(array_diff($unloaded, UNLOADED_OK));
$below = $min !== null && $percent < $min;

// Plain text
printf("Line coverage of unit, smoke and browser tests: %d of %d lines = %s%s, %d processes\n",
    $covered, $total, $pct($percent), $min !== null ? ' (minimum ' . $pct($min) . ')' : '', count($raw));
foreach ($rows as $r) {
    if ($r['covered'] < $r['total']) {
        printf("%7s %5d/%-5d %-48s %s\n", $pct(100 * $r['covered'] / $r['total']), $r['covered'], $r['total'], $r['file'], $fmt($r['missing']));
    }
}
printf("%d files completely covered\n", count(array_filter($rows, static fn (array $r): bool => $r['covered'] === $r['total'])));
if ($unloaded) {
    echo 'Not loaded by any test: ', implode(', ', $unloaded), "\n";
}
if (isset($opt['diff'])) {
    echo $uncoveredChanged ? "Changed lines that no test executes:\n" : "Every changed line is executed by a test.\n";
    foreach ($uncoveredChanged as $f => $r) {
        echo "  $f: ", $fmt($r), "\n";
    }
}

if ($github) {
    foreach ($uncoveredChanged as $f => $rs) {
        foreach ($rs as [$from, $to]) {
            printf("::warning file=%s,line=%d,endLine=%d,title=Not covered by tests::%s\n", $f, $from, $to,
                $files[$f] === null ? 'No test loads this file.' : ($from === $to ? "Line $from is" : "Lines $from-$to are") . ' not executed by any unit, smoke or browser test.');
        }
    }
    foreach ($unexpected as $f) {
        printf("::error file=%s,title=Not covered by tests::No test loads this file.\n", $f);
    }
    if ($below) {
        printf("::error title=Coverage below the minimum::Line coverage %s is below the minimum of %s.\n", $pct($percent), $pct($min));
    }
}

if (isset($opt['markdown'])) {
    $md = "### Line coverage: " . $pct($percent) . "\n\n"
        . "$covered of $total lines of PHP executed by the unit, smoke and browser tests"
        . ($min !== null ? ' (minimum: ' . $pct($min) . ($below ? ', **not reached**' : '') . ')' : '') . ".\n\n";
    if (isset($opt['diff'])) {
        $md .= $uncoveredChanged ? "**Changed lines that no test executes**\n\n" : "Every changed line is executed by a test.\n\n";
        foreach ($uncoveredChanged as $f => $r) {
            $md .= "- `$f`: " . $fmt($r) . "\n";
        }
        $md .= $uncoveredChanged ? "\n" : '';
    }
    if ($unloaded) {
        $md .= 'Not loaded by any test: ' . implode(', ', array_map(static fn (string $f): string => "`$f`", $unloaded)) . "\n\n";
    }
    $md .= "<details><summary>Files below 100 %</summary>\n\n| Coverage | Lines | File | Not executed |\n|---:|---:|---|---|\n";
    foreach ($rows as $r) {
        if ($r['covered'] < $r['total']) {
            $md .= sprintf("| %s | %d/%d | `%s` | %s |\n", $pct(100 * $r['covered'] / $r['total']), $r['covered'], $r['total'], $r['file'], $fmt($r['missing']));
        }
    }
    file_put_contents((string) $opt['markdown'], $md . "\n</details>\n");
}

if ($unexpected) {
    fwrite(STDERR, 'Not loaded by any test: ' . implode(', ', $unexpected) . "\n");
}
if ($below) {
    fwrite(STDERR, 'Line coverage ' . $pct($percent) . ' is below the minimum of ' . $pct($min) . "\n");
}
exit($below || $unexpected ? 1 : 0);
