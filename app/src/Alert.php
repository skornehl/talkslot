<?php
declare(strict_types=1);

namespace App;

/**
 * Meldet Fehler per Mail an den Betreiber: unbehandelte Exceptions, fatale PHP-Fehler,
 * Warnungen, Fehler bei Migration/Bereinigung, nicht erreichbare Datenbank, gescheiterten
 * Mailversand.
 *
 * Empfänger: config „alert_email“ (Deployment: Variable ALERT_EMAIL), sonst die E-Mail-Adresse
 * des Admin-Kontos. Ist die Datenbank nicht erreichbar, geht nur die Adresse aus der Config.
 *
 * Gleiche Fehler (Art + Ort) werden höchstens einmal pro Stunde gemeldet, insgesamt höchstens
 * MAX_PER_DAY Mails am Tag; unterdrückte Wiederholungen werden in der nächsten Mail gezählt.
 * Der Zähler liegt in app/var (nicht in der Datenbank, die ja ausgefallen sein kann).
 *
 * Die Mail enthält keine personenbezogenen Daten: Meldung wie im Fehlerprotokoll bereinigt,
 * vom Pfad nur unverfängliche Teile (keine Tokens, keine Runden-Links), kein Query-String,
 * Ablauf nur mit Datei, Zeile und Funktion (ohne Argumente).
 */
final class Alert
{
    public const THROTTLE_SECONDS = 3600;
    public const MAX_PER_DAY = 30;

    /** Für Tests: fängt Mails ab, statt sie zu senden (fn(string $to, string $subject, string $body): bool). */
    public static ?\Closure $transport = null;
    /** Für Tests: anderer Ort für den Zähler. */
    public static ?string $stateFile = null;

    private static bool $sending = false;

    /** Exception protokollieren und melden. */
    public static function exception(\Throwable $e, string $context = ''): void
    {
        ErrorPage::log($e);
        $lines = [
            'Fehler: ' . get_class($e) . ': ' . ErrorPage::sanitize($e->getMessage()),
            'Ort: ' . self::relative($e->getFile()) . ':' . $e->getLine(),
        ];
        for ($p = $e->getPrevious(); $p !== null; $p = $p->getPrevious()) {
            $lines[] = 'Ursache: ' . get_class($p) . ': ' . ErrorPage::sanitize($p->getMessage())
                . ' (' . self::relative($p->getFile()) . ':' . $p->getLine() . ')';
        }
        $lines[] = '';
        $lines[] = 'Ablauf:';
        foreach (array_slice($e->getTrace(), 0, 12) as $i => $f) {
            $lines[] = sprintf('#%d %s%s %s%s%s()', $i,
                isset($f['file']) ? self::relative($f['file']) : '[intern]',
                isset($f['line']) ? ':' . $f['line'] : '',
                $f['class'] ?? '', $f['type'] ?? '', $f['function']);
        }
        $kind = Db::isUnavailable() ? 'Datenbank nicht erreichbar' : get_class($e);
        self::notify(
            get_class($e) . '@' . self::relative($e->getFile()) . ':' . $e->getLine(),
            $kind . ($context !== '' ? ' (' . $context . ')' : ''),
            $lines,
            Db::isUnavailable()
        );
    }

    /** Fataler PHP-Fehler (aus error_get_last() im Shutdown) bzw. Warnung. */
    public static function phpError(int $type, string $message, string $file, int $line): void
    {
        $label = match ($type) {
            E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR => 'Fataler Fehler',
            E_PARSE => 'Syntaxfehler',
            default => 'PHP-Warnung',
        };
        self::notify(
            'php' . $type . '@' . self::relative($file) . ':' . $line,
            $label,
            [$label . ': ' . ErrorPage::sanitize($message), 'Ort: ' . self::relative($file) . ':' . $line],
            $label !== 'PHP-Warnung'
        );
    }

    /**
     * Mail an den Betreiber, gedrosselt je $key. Gibt true zurück, wenn gesendet wurde.
     * $important (Datenbank weg, fataler Fehler): zählt nicht gegen das Tageslimit, damit häufige
     * kleinere Meldungen (z. B. absichtlich ausgelöste Warnungen) wichtige nicht verdrängen.
     * @param list<string> $lines
     */
    public static function notify(string $key, string $subject, array $lines, bool $important = false): bool
    {
        if (self::$sending) {
            return false; // Fehler beim Senden der Meldung selbst nicht erneut melden
        }
        self::$sending = true;
        try {
            $to = self::recipient();
            if ($to === null) {
                ErrorPage::writeLog('Alert: kein Empfänger (config alert_email bzw. E-Mail des Admin-Kontos) – keine Meldung versendet');
                return false;
            }
            $suppressed = self::claim($key, $important);
            if ($suppressed === null) {
                return false;
            }
            $body = self::body($lines, $suppressed);
            $subject = '[' . self::appName() . '] ' . $subject;
            $ok = self::$transport !== null
                ? (bool) (self::$transport)($to, $subject, $body)
                : Mailer::send($to, $subject, $body);
            if (!$ok) {
                ErrorPage::writeLog('Alert: Meldung konnte nicht versendet werden');
            }
            return $ok;
        } catch (\Throwable $e) {
            ErrorPage::writeLog('Alert: ' . get_class($e) . ': ' . ErrorPage::sanitize($e->getMessage()));
            return false;
        } finally {
            self::$sending = false;
        }
    }

    private static function recipient(): ?string
    {
        $to = trim((string) Config::get('alert_email', ''));
        if (filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $to;
        }
        if (Db::isUnavailable()) {
            return null;
        }
        try {
            $admin = Operator::admin();
            $to = trim((string) ($admin['email'] ?? ''));
            return filter_var($to, FILTER_VALIDATE_EMAIL) ? $to : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Drosselung: null = nicht senden; sonst Anzahl der seit der letzten Mail unterdrückten
     * gleichen Fehler. Ist app/var nicht beschreibbar, liegt der Zähler im Temp-Verzeichnis; nur
     * wenn auch das scheitert, wird ungedrosselt gesendet (lieber zu viel als nichts).
     */
    private static function claim(string $key, bool $important): ?int
    {
        $fh = @fopen(self::$stateFile ?? DATA_ROOT . '/app/var/alerts.json', 'c+')
            ?: @fopen(sys_get_temp_dir() . '/tp-alerts-' . substr(hash('sha256', DATA_ROOT), 0, 12) . '.json', 'c+');
        if ($fh === false) {
            return 0;
        }
        try {
            flock($fh, LOCK_EX);
            $state = json_decode((string) stream_get_contents($fh), true);
            $state = is_array($state) ? $state : [];
            $now = time();
            $today = date('Y-m-d', $now);
            if (($state['_day'] ?? '') !== $today) {
                $state['_day'] = $today;
                $state['_count'] = 0;
            }
            $id = hash('sha256', $key);
            $entry = $state['keys'][$id] ?? ['last' => 0, 'suppressed' => 0];
            if ($now - (int) $entry['last'] < self::THROTTLE_SECONDS || (!$important && (int) $state['_count'] >= self::MAX_PER_DAY)) {
                $entry['suppressed'] = (int) $entry['suppressed'] + 1;
                $suppressed = null;
            } else {
                $suppressed = (int) $entry['suppressed'];
                $entry = ['last' => $now, 'suppressed' => 0];
                $state['_count'] = (int) $state['_count'] + 1;
            }
            $state['keys'][$id] = $entry;
            // Alte Einträge (älter als ein Tag, nichts offen) entfernen
            $state['keys'] = array_filter($state['keys'], static fn ($e) => $now - (int) $e['last'] < 86400 || (int) $e['suppressed'] > 0);
            ftruncate($fh, 0);
            rewind($fh);
            fwrite($fh, (string) json_encode($state));
            fflush($fh);
            return $suppressed;
        } finally {
            flock($fh, LOCK_UN);
            fclose($fh);
        }
    }

    /** @param list<string> $lines */
    private static function body(array $lines, int $suppressed): string
    {
        $version = Version::info();
        $head = [
            self::appName() . ' hat einen Fehler gemeldet.',
            '',
            'Zeit: ' . date('d.m.Y H:i:s'),
            'Seite: ' . (string) Config::get('base_url', ''),
            'Version: ' . $version['version'] . ($version['commit'] !== '' ? ' (' . $version['commit'] . ')' : ''),
            'Anfrage: ' . self::request(),
        ];
        if ($suppressed > 0) {
            $head[] = "Seit der letzten Meldung $suppressed weitere Male aufgetreten.";
        }
        return implode("\n", array_merge($head, [''], $lines, [
            '',
            '--',
            'Gleiche Fehler werden höchstens einmal pro Stunde gemeldet. Details stehen im',
            'Fehlerprotokoll app/var/php-error.log. Diese Mail enthält keine personenbezogenen Daten.',
        ])) . "\n";
    }

    private static function request(): string
    {
        if (PHP_SAPI === 'cli') {
            return 'CLI ' . basename((string) ($_SERVER['argv'][0] ?? ''));
        }
        return self::describeRequest((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'), (string) ($_SERVER['REQUEST_URI'] ?? '/'));
    }

    /**
     * Methode und Pfad ohne Query-String; nur Wörter und Zahlen (z. B. /admin/round/5/edit)
     * bleiben, alles andere (Tokens, Runden-Links) wird durch „…“ ersetzt.
     */
    public static function describeRequest(string $method, string $uri): string
    {
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $parts = array_map(
            static fn (string $s) => preg_match('/^(?:\d{1,9}|[a-z]{1,15})$/', $s) ? $s : '…',
            array_values(array_filter(explode('/', $path), static fn ($s) => $s !== ''))
        );
        return (string) preg_replace('/[^A-Z]/', '', strtoupper($method)) . ' /' . implode('/', $parts);
    }

    private static function relative(string $file): string
    {
        return ltrim(str_replace(APP_ROOT, '', $file), '/');
    }

    /** Name aus der Konfiguration (ohne Zeilenumbrüche, er steht im Betreff). */
    private static function appName(): string
    {
        $name = trim((string) preg_replace('/[\r\n]+/', ' ', (string) Config::get('app_name', 'TalkSlot')));
        return $name !== '' ? $name : 'TalkSlot';
    }
}
