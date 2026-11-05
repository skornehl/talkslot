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
        $items = [self::legal($legalHints)];
        if (Operator::type() === 'school') {
            $items[] = self::dpo();
        }
        return [...$items, self::mail(), self::cron(), self::server(), self::rights()];
    }

    /** Started: a round has been public at least once (or got an entry by its teacher). */
    public static function started(): bool
    {
        return Db::value('SELECT 1 FROM rounds WHERE was_used = 1 LIMIT 1') !== null;
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

    /** @param list<string> $hints */
    private static function legal(array $hints): array
    {
        $blocked = !Operator::ready() || Operator::type() === null || LegalTexts::open() !== [];
        return self::item('legal', $blocked ? self::TODO : ($hints ? self::CHECK : self::DONE), __('Impressum und Datenschutzerklärung'),
            match (true) {
                $blocked => __('Bis das erledigt ist, lassen sich keine Runden aktivieren.'),
                $hints !== [] => __('Runden lassen sich aktivieren. Bitte prüfen Sie trotzdem diese Hinweise:'),
                default => __('Angaben des Betreibers, Betriebsart und beide Texte sind vollständig.'),
            }, $hints, url('/admin/texts'), __('Zu den Texten'));
    }

    private static function dpo(): array
    {
        $missing = array_filter(LegalTexts::open(), static fn (array $t): bool => $t['key'] === 'privacy'
            && array_filter($t['placeholders'], static fn (string $p): bool => preg_match(self::DPO_PLACEHOLDER, $p) === 1) !== []);
        return self::item('dpo', $missing ? self::TODO : self::DONE, __('Datenschutzbeauftragte der Schule'),
            $missing
                ? __('In der Datenschutzerklärung ({languages}) fehlen noch die Kontaktdaten der bzw. des Datenschutzbeauftragten der Schule. Die Schule oder der Schulträger nennt sie Ihnen.', ['languages' => strtoupper(implode(', ', array_column($missing, 'lang')))])
                : __('In der Datenschutzerklärung eingetragen.'),
            [], $missing ? url('/admin/page/privacy/' . $missing[array_key_first($missing)]['lang']) : null, __('Datenschutzerklärung bearbeiten'));
    }

    private static function mail(): array
    {
        $late = Outbox::late();
        $from = (string) Config::get('mail.from_address', '');
        $transport = (string) Config::get('mail.transport', 'mail');
        return match (true) {
            $late !== null => self::item('mail', self::TODO, __('E-Mail-Versand'),
                __('{count} E-Mail(s) warten seit über {hours} Stunden auf den Versand. Bitte prüfen Sie den Mailversand und den Cronjob.', ['count' => $late['count'], 'hours' => Outbox::lateHours()])),
            $from === '' => self::item('mail', self::TODO, __('E-Mail-Versand'),
                __('In der Konfiguration fehlt die Absenderadresse (mail.from_address). Ohne sie bekommen Eltern keine Bestätigung.')),
            default => self::item('mail', self::CHECK, __('E-Mail-Versand'),
                __('Versand über {transport} von {from}. Ob die E-Mails ankommen, zeigt nur ein Versuch: Buchen Sie einmal selbst einen Termin mit Ihrer eigenen Adresse und prüfen Sie auch den Spam-Ordner.', ['transport' => $transport === 'smtp' ? 'SMTP' : 'PHP mail()', 'from' => $from])),
        };
    }

    private static function cron(): array
    {
        $guide = Version::PROJECT_URL . (AdminI18n::lang() === 'de' ? 'de/' : '') . 'guide/operations/#cron-monitoring';
        $status = CronMonitor::status();
        $last = CronMonitor::lastRun();
        return match (true) {
            ($status['state'] ?? null) === 'late' => self::item('cron', self::TODO, __('Cronjob'),
                __('Seit dem {time} Uhr nicht mehr vollständig gelaufen. Ohne ihn gehen Erinnerungen und Zusammenfassungen verspätet oder gar nicht hinaus.', ['time' => date('d.m.Y H:i', (int) $status['last'])]), [], $guide, __('Zur Anleitung')),
            $last !== null => self::item('cron', self::DONE, __('Cronjob'), __('Zuletzt vollständig gelaufen am {time} Uhr.', ['time' => date('d.m.Y H:i', $last)])),
            CronMonitor::alertHours() === 0 => self::item('cron', self::CHECK, __('Cronjob'),
                __('Noch kein Lauf verzeichnet, und die Überwachung ist abgeschaltet (cron_alert_hours = 0). Ohne Cronjob gehen Erinnerungen und Zusammenfassungen nur hinaus, wenn jemand die Seite aufruft.'), [], $guide, __('Zur Anleitung')),
            default => self::item('cron', self::TODO, __('Cronjob'),
                __('Noch kein vollständiger Lauf verzeichnet. Bitte richten Sie ihn ein (stündlich bin/cron.php oder der Web-Cron), damit Erinnerungen und Zusammenfassungen pünktlich hinausgehen.'), [], $guide, __('Zur Anleitung')),
        };
    }

    private static function server(): array
    {
        $problem = Health::serverProblem();
        $warnings = ConfigSchema::warnings(Config::all());
        return match (true) {
            $problem !== null => self::item('server', self::TODO, __('Server und Konfiguration'), $problem, $warnings),
            $warnings !== [] => self::item('server', self::CHECK, __('Server und Konfiguration'),
                __('Diese Einstellungen in config/config.php wirken nicht wie geschrieben (Aufgabe der technischen Betreuung):'), $warnings),
            default => self::item('server', self::DONE, __('Server und Konfiguration'),
                __('PHP {php}, Datenbank {database}; die Konfiguration ist in Ordnung.', ['php' => PHP_VERSION, 'database' => Health::serverVersion()])),
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
