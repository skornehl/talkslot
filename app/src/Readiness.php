<?php
declare(strict_types=1);

namespace App;

/**
 * "Bereit für den Start?" (expert review P2-13): one checklist for admins instead of warnings in many places –
 * legal texts, the school's data protection officer, mail, cron job, server and the rights of the configuration,
 * each as done, to check or to do.
 *
 * The details of the legal texts come from the hints the admin area shows anyway (AdminController::operatorHints()
 * and legalHints()), passed in by the controller; the other checks read the same sources as the dashboard.
 */
final class Readiness
{
    public const DONE = 'done';
    public const CHECK = 'check';
    public const TODO = 'todo';

    /** The school's data protection officer in the school templates of the privacy policy (de, en) */
    private const DPO_PLACEHOLDER = '/\[(?:KONTAKTDATEN DER\/DES DATENSCHUTZBEAUFTRAGTEN|CONTACT DETAILS OF THE SCHOOL\'S DATA PROTECTION OFFICER)/u';

    /**
     * @param list<string> $legalHints notes on the legal texts (operator details, operator type, placeholders, templates)
     * @return list<array{key: string, state: string, title: string, text: string, details: list<string>, link: ?string, linkText: string}>
     */
    public static function checks(array $legalHints): array
    {
        $open = LegalTexts::open();
        $items = [self::legal($legalHints, $open)];
        if (Operator::type() === 'school') {
            $items[] = self::dpo($open);
        }
        return [...$items, self::contact(), self::mail(), self::cron(), self::server(), self::rights()];
    }

    /**
     * Started: a round has been public (or got an entry by its teacher) at some point. Remembered, as such rounds
     * are deleted after their deletion period: the checklist must not come back to the overview then.
     */
    public static function started(): bool
    {
        if (Settings::get('readiness_started') === '1') {
            return true;
        }
        if (Db::value('SELECT 1 FROM rounds WHERE was_used = 1 LIMIT 1') === null) {
            return false;
        }
        Settings::set('readiness_started', '1');
        return true;
    }

    /** @param list<array{state: string}> $items */
    public static function done(array $items): int
    {
        return count(array_filter($items, static fn (array $i): bool => $i['state'] === self::DONE));
    }

    /** Text for a state, shown next to the colour (never colour alone). */
    public static function stateLabel(string $state): string
    {
        return match ($state) {
            self::DONE => __('Erledigt'),
            self::CHECK => __('Bitte prüfen'),
            default => __('Noch offen'),
        };
    }

    private static function item(string $key, string $state, string $title, string $text, array $details = [], ?string $link = null, string $linkText = ''): array
    {
        return ['key' => $key, 'state' => $state, 'title' => $title, 'text' => $text, 'details' => array_values($details), 'link' => $link, 'linkText' => $linkText];
    }

    /**
     * @param list<string> $hints
     * @param list<array{key: string, lang: string, placeholders: list<string>}> $open LegalTexts::open()
     */
    private static function legal(array $hints, array $open): array
    {
        $blocked = !Operator::ready() || Operator::type() === null || $open !== [];
        return self::item('legal', $blocked ? self::TODO : ($hints ? self::CHECK : self::DONE), __('Impressum und Datenschutzerklärung'),
            match (true) {
                $blocked => __('Hier fehlt noch etwas (siehe unten). Bis dahin lassen sich keine Runden aktivieren.'),
                $hints !== [] => __('Runden lassen sich aktivieren. Bitte prüfen Sie trotzdem diese Hinweise:'),
                default => __('Angaben des Betreibers, Betriebsart und beide Texte sind vollständig.'),
            }, $hints, url('/admin/texts'), __('Zu den Texten'));
    }

    /** @param list<array{key: string, lang: string, placeholders: list<string>}> $open LegalTexts::open() */
    private static function dpo(array $open): array
    {
        $missing = array_filter($open, static fn (array $t): bool => $t['key'] === 'privacy'
            && array_filter($t['placeholders'], static fn (string $p): bool => preg_match(self::DPO_PLACEHOLDER, $p) === 1) !== []);
        return self::item('dpo', $missing ? self::TODO : self::DONE, __('Datenschutzbeauftragte der Schule'),
            $missing
                ? __('In der Datenschutzerklärung ({languages}) fehlen noch die Kontaktdaten der bzw. des Datenschutzbeauftragten der Schule. Die Schule oder der Schulträger nennt sie Ihnen.', ['languages' => strtoupper(implode(', ', array_column($missing, 'lang')))])
                : __('In der Datenschutzerklärung eingetragen.'),
            [], $missing ? url('/admin/page/privacy/' . $missing[array_key_first($missing)]['lang']) : null, __('Datenschutzerklärung bearbeiten'));
    }

    /** Optional, but without it parents who are stuck are only referred to the legal notice (ParentContact). */
    private static function contact(): array
    {
        $general = ParentContact::general();
        return self::item('contact', $general !== '' ? self::DONE : self::CHECK, __('Kontakt für Eltern bei Fragen'),
            $general !== '' ? __('Hinterlegt: {contact}', ['contact' => $general])
                : __('Noch kein allgemeiner Kontakt hinterlegt. Eltern, die nicht weiterkommen, werden dann nur auf das Impressum verwiesen, außer bei Lehrkräften mit eigenem Kontakt im Profil.'),
            [], url('/admin/texts') . '#parent-contact', __('Zu den Texten'));
    }

    /**
     * Whether mails arrive is only known once one did: an invited account that has signed in got its invitation
     * (the first admin from the installation does not count).
     */
    private static function mail(): array
    {
        $late = Outbox::late();
        $from = (string) Config::get('mail.from_address', '');
        $via = __('Versand über {transport} von {from}.', ['transport' => (string) Config::get('mail.transport', 'mail') === 'smtp' ? 'SMTP' : 'PHP mail()', 'from' => $from]);
        $arrived = Db::value('SELECT 1 FROM users WHERE last_login_at IS NOT NULL AND id <> (SELECT MIN(id) FROM users) LIMIT 1') !== null;
        return self::mailItem($late, $from, $via, $arrived);
    }

    /**
     * @param ?array{count: int} $late Outbox::late()
     * @param bool $arrived an invited account has signed in
     */
    public static function mailItem(?array $late, string $from, string $via, bool $arrived): array
    {
        return match (true) {
            $late !== null => self::item('mail', self::TODO, __('E-Mail-Versand'),
                __('{count} E-Mail(s) warten seit über {hours} Stunden auf den Versand. Bitte prüfen Sie den Mailversand und den Cronjob (Aufgabe der technischen Betreuung).', ['count' => $late['count'], 'hours' => Outbox::lateHours()]), [$via]),
            $from === '' => self::item('mail', self::TODO, __('E-Mail-Versand'),
                __('In der Konfiguration fehlt die Absenderadresse (mail.from_address, Aufgabe der technischen Betreuung). Ohne sie bekommen Eltern keine Bestätigung.')),
            $arrived => self::item('mail', self::DONE, __('E-Mail-Versand'), __('E-Mails kommen an: Eine eingeladene Person hat sich angemeldet.'), [$via]),
            default => self::item('mail', self::CHECK, __('E-Mail-Versand'),
                __('Ob E-Mails ankommen, zeigt ein Versuch: Laden Sie unter „Benutzer“ eine Lehrkraft ein. Kommt die Einladung an (auch im Spam-Ordner nachsehen) und meldet sie sich an, klappt der Versand.'), [$via], url('/admin/users'), __('Zu den Benutzern')),
        };
    }

    private static function cron(): array
    {
        $guide = Version::PROJECT_URL . (AdminI18n::lang() === 'de' ? 'de/' : '') . 'guide/operations/#cron-monitoring';
        return self::cronItem(CronMonitor::status(), CronMonitor::lastRun(), CronMonitor::alertHours() === 0, time(), $guide);
    }

    /**
     * @param ?array{state: string, last: ?int} $status CronMonitor::status()
     * @param bool $off the monitoring is switched off (cron_alert_hours = 0)
     */
    public static function cronItem(?array $status, ?int $last, bool $off, int $now, string $guide): array
    {
        $help = [
            __('Läuft er über den GitHub-Workflow „Cron“: GitHub schaltet geplante Workflows in öffentlichen Repositorys nach 60 Tagen ohne Aktivität ab, unter „Actions“ lässt er sich wieder einschalten.'),
            __('„php bin/cron.php -v“ auf dem Server zeigt, woran er scheitert.'),
        ];
        return match (true) {
            ($status['state'] ?? null) === 'late' => self::item('cron', self::TODO, __('Cronjob'),
                __('Seit dem {time} Uhr nicht mehr vollständig gelaufen (Aufgabe der technischen Betreuung). Ohne ihn gehen Erinnerungen und Zusammenfassungen verspätet oder gar nicht hinaus.', ['time' => date('d.m.Y H:i', (int) $status['last'])]), $help, $guide, __('Zur Anleitung')),
            $last !== null && $off && $now - $last > CronMonitor::DEFAULT_HOURS * 3600 => self::item('cron', self::CHECK, __('Cronjob'),
                __('Zuletzt vollständig gelaufen am {time} Uhr, die Überwachung ist abgeschaltet (cron_alert_hours = 0). Bitte prüfen, ob er noch läuft.', ['time' => date('d.m.Y H:i', $last)]), $help, $guide, __('Zur Anleitung')),
            $last !== null => self::item('cron', self::DONE, __('Cronjob'), __('Zuletzt vollständig gelaufen am {time} Uhr.', ['time' => date('d.m.Y H:i', $last)])),
            $off => self::item('cron', self::CHECK, __('Cronjob'),
                __('Noch kein Lauf verzeichnet, und die Überwachung ist abgeschaltet (cron_alert_hours = 0). Ohne Cronjob gehen Erinnerungen und Zusammenfassungen nur hinaus, wenn jemand die Seite aufruft.'), [], $guide, __('Zur Anleitung')),
            default => self::item('cron', self::TODO, __('Cronjob'),
                __('Noch kein vollständiger Lauf verzeichnet. Bitte richten Sie ihn ein (stündlich bin/cron.php oder der Web-Cron, Aufgabe der technischen Betreuung), damit Erinnerungen und Zusammenfassungen pünktlich hinausgehen.'), $help, $guide, __('Zur Anleitung')),
        };
    }

    private static function server(): array
    {
        return self::serverItem(Health::serverProblem(), ConfigSchema::warnings(Config::all()), Health::serverVersion());
    }

    /** @param list<string> $warnings ConfigSchema::warnings() */
    public static function serverItem(?string $problem, array $warnings, string $database): array
    {
        return match (true) {
            $problem !== null => self::item('server', self::TODO, __('Server und Konfiguration'), $problem, $warnings),
            $warnings !== [] => self::item('server', self::CHECK, __('Server und Konfiguration'),
                __('Diese Einstellungen in config/config.php wirken nicht wie geschrieben (Aufgabe der technischen Betreuung):'), $warnings),
            default => self::item('server', self::DONE, __('Server und Konfiguration'),
                __('PHP {php}, Datenbank {database}; die Konfiguration ist in Ordnung.', ['php' => PHP_VERSION, 'database' => $database])),
        };
    }

    private static function rights(): array
    {
        $exposed = Config::exposedFiles();
        return $exposed
            ? self::item('rights', self::TODO, __('Rechte der Konfigurationsdatei'),
                __('Für alle Benutzer des Servers lesbar: {files}. Sie enthält Passwörter und den geheimen Schlüssel. Bitte setzen Sie die Rechte auf 600 (oder 640), z. B. im FTP-Programm, oder geben Sie das an die technische Betreuung weiter.',
                    ['files' => implode(', ', array_map(static fn (string $file, string $mode): string => "$file ($mode)", array_keys($exposed), $exposed))]))
            : self::item('rights', self::DONE, __('Rechte der Konfigurationsdatei'), __('Nur für den Webserver lesbar.'));
    }
}
