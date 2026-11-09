<?php
declare(strict_types=1);

namespace App;

/**
 * Which build is running? On every run on main, the deployment determines the next version
 * (bin/next-version.php → next()), writes it with commit and timestamp to app/version.php
 * (bin/build-version.php) and creates the release after a successful upload.
 * Locally without this file: "dev" with the commit from .git (without a shell call).
 */
final class Version
{
    private static ?array $info = null;

    /** @return array{version:string, commit:string, built_at:string} */
    public static function info(): array
    {
        if (self::$info !== null) {
            return self::$info;
        }
        $file = APP_ROOT . '/app/version.php';
        $data = is_file($file) ? require $file : null;
        if (!is_array($data)) {
            $data = ['version' => 'dev', 'commit' => self::localCommit(), 'built_at' => ''];
        }
        return self::$info = [
            'version' => (string) ($data['version'] ?? 'dev'),
            'commit' => substr((string) ($data['commit'] ?? ''), 0, 7),
            'built_at' => (string) ($data['built_at'] ?? ''),
        ];
    }

    /** Project page linked in the footer ("Powered by TalkSlot …"). */
    public const PROJECT_URL = 'https://skornehl.github.io/talkslot/';

    /**
     * Short display for the footer, e.g. "v1.2.0", "v1.2.0+3 (abc1234)" (3 commits after the release),
     * "abc1234" (no release yet) or "dev (abc1234)" (local). The build time stays in info().
     */
    public static function short(?array $info = null): string
    {
        $info ??= self::info();
        $v = $info['version'];
        if (preg_match('/^v?(\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+?)?)-(\d+)-g([0-9a-f]+)(-dirty)?\z/', $v, $m)) {
            return 'v' . $m[1] . '+' . $m[2] . ' (' . substr($m[3], 0, 7) . ')';
        }
        if (preg_match('/^v?(\d+\.\d+\.\d+(?:-[0-9A-Za-z.]+)?)\z/', $v, $m)) {
            return 'v' . $m[1];
        }
        if ($v === 'dev' || $v === '') {
            return 'dev' . ($info['commit'] !== '' ? ' (' . $info['commit'] . ')' : '');
        }
        return substr($v, 0, 40); // commit only (no tag reachable)
    }

    /**
     * Next version according to Semantic Versioning from the commit messages since the last
     * tag (Conventional Commits): "type!:" or "BREAKING CHANGE:" → major, "feat:" → minor,
     * everything else → patch. Without a previous tag: v1.0.0.
     * @param list<string> $messages full commit messages (subject and body)
     */
    public static function next(string $lastTag, array $messages): string
    {
        if ($lastTag === '') {
            return 'v1.0.0';
        }
        if (!preg_match('/^v(\d+)\.(\d+)\.(\d+)\z/', $lastTag, $m)) {
            throw new \InvalidArgumentException("Not a version tag: $lastTag");
        }
        [$major, $minor, $patch] = [(int) $m[1], (int) $m[2], (int) $m[3]];
        $bump = 'patch';
        foreach ($messages as $msg) {
            $subject = strtok(ltrim($msg), "\n") ?: '';
            if (preg_match('/^[a-z]+(\([^)]*\))?!:/i', $subject) || preg_match('/^BREAKING[ -]CHANGE:/m', $msg)) {
                $bump = 'major';
                break;
            }
            if (preg_match('/^feat(\([^)]*\))?:/i', $subject)) {
                $bump = 'minor';
            }
        }
        return match ($bump) {
            'major' => 'v' . ($major + 1) . '.0.0',
            'minor' => "v$major." . ($minor + 1) . '.0',
            default => "v$major.$minor." . ($patch + 1),
        };
    }

    private static function localCommit(): string
    {
        $head = @file_get_contents(APP_ROOT . '/.git/HEAD');
        if (!is_string($head)) {
            return '';
        }
        $head = trim($head);
        if (str_starts_with($head, 'ref: ')) {
            $ref = substr($head, 5);
            $sha = @file_get_contents(APP_ROOT . '/.git/' . $ref);
            if (!is_string($sha)) {
                $packed = (string) @file_get_contents(APP_ROOT . '/.git/packed-refs');
                $sha = preg_match('/^([0-9a-f]{40}) ' . preg_quote($ref, '/') . '$/m', $packed, $m) ? $m[1] : '';
            }
            $head = trim((string) $sha);
        }
        return preg_match('/^[0-9a-f]{7,40}\z/', $head) ? substr($head, 0, 7) : '';
    }
}
