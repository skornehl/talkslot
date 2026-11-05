<?php
declare(strict_types=1);

namespace App;

/**
 * Notices when the cron job stops running (bin/cron.php or the web cron POST /cron), for example because
 * GitHub disabled the scheduled workflow: in public repositories it does so after 60 days without activity.
 *
 * Every completed cron run stores its time as "last_cron", apart from "last_cleanup", which the clean-up on
 * page views sets as well. Without a completed run for cron_alert_hours (default 3: two hours behind the
 * hourly schedule, so that a single run GitHub delays or skips is no alarm yet), admins see a warning on the
 * dashboard and the operator gets an alert mail (App\Alert): once per outage, repeated daily while it lasts.
 * Page views check this; the web cron does not, it reports its own run.
 *
 * If no run has been recorded at all, nothing is mailed (the cron job is recommended, not required: the
 * clean-up also runs on page views). Admins are asked to set it up once the same time has passed since the
 * monitoring began ("cron_watch_since", set on the first page view, so an update is no alarm either).
 */
final class CronMonitor
{
    public const REPEAT_SECONDS = 86400;

    /** At the end of a completed cron run. */
    public static function ran(): void
    {
        Settings::set('last_cron', (string) time());
    }

    /** Hours without a completed run after which admins are warned (0 = monitoring off). */
    public static function alertHours(): int
    {
        return max(0, (int) Config::get('cron_alert_hours', 3));
    }

    /**
     * "late" (no completed run for $hours), "never" (no run at all, $hours since $since) or null (all well,
     * or monitoring off).
     * @return 'late'|'never'|null
     */
    public static function state(?int $last, ?int $since, int $now, int $hours): ?string
    {
        if ($hours <= 0) {
            return null;
        }
        if ($last !== null) {
            return $now - $last >= $hours * 3600 ? 'late' : null;
        }
        return $since !== null && $now - $since >= $hours * 3600 ? 'never' : null;
    }

    /** Alert mail due: none since the last completed run (a new outage), or the last one is a day old. */
    public static function alertDue(int $last, int $sent, int $now): bool
    {
        return $sent <= $last || $now - $sent >= self::REPEAT_SECONDS;
    }

    /**
     * For the admins' dashboard: null while all is well.
     * @return array{state: 'late'|'never', last: ?int}|null
     */
    public static function status(): ?array
    {
        $s = self::settings();
        $last = $s['last_cron'] !== null ? (int) $s['last_cron'] : null;
        $since = $s['cron_watch_since'] !== null ? (int) $s['cron_watch_since'] : null;
        $state = self::state($last, $since, time(), self::alertHours());
        return $state === null ? null : ['state' => $state, 'last' => $last];
    }

    /**
     * On page views (not on the web cron itself): starts the monitoring and sends the alert mail when the
     * cron job is late. One query while all is well; a claim in the settings makes sure that only one of
     * several parallel requests sends it.
     */
    public static function check(): void
    {
        $hours = self::alertHours();
        if ($hours === 0) {
            return;
        }
        $s = self::settings();
        $now = time();
        if ($s['last_cron'] === null) {
            if ($s['cron_watch_since'] === null) {
                Db::run("INSERT IGNORE INTO settings (name, value) VALUES ('cron_watch_since', ?)", [(string) $now]);
            }
            return;
        }
        $last = (int) $s['last_cron'];
        $sent = $s['cron_alert_sent'] ?? '0';
        if (self::state($last, null, $now, $hours) !== 'late' || !self::alertDue($last, (int) $sent, $now)) {
            return;
        }
        if ($s['cron_alert_sent'] === null) {
            Db::run("INSERT IGNORE INTO settings (name, value) VALUES ('cron_alert_sent', '0')");
        }
        if (Db::exec("UPDATE settings SET value = ? WHERE name = 'cron_alert_sent' AND value = ?", [(string) $now, $sent]) === 1) {
            Alert::notify('cron-late', 'Cronjob läuft nicht', self::alertLines($last, $hours), true);
        }
    }

    /**
     * Text of the alert mail (German like all alerts to the operator; no personal data).
     * @return list<string>
     */
    public static function alertLines(int $last, int $hours): array
    {
        return [
            'Der Cronjob ist seit dem ' . date('d.m.Y H:i', $last) . ' Uhr nicht mehr vollständig gelaufen (gemeldet wird nach ' . $hours . ' Stunden).',
            'Ohne ihn werden Buchungen vergangener Tage verspätet gelöscht und Zusammenfassungen und Erinnerungen',
            'verspätet oder gar nicht verschickt.',
            '',
            'Bitte prüfen:',
            '- Cronjob auf dem Server: läuft bin/cron.php stündlich? "php bin/cron.php -v" zeigt Fehler direkt an.',
            '- GitHub-Workflow "Cron": ist er unter Actions noch aktiv? GitHub schaltet geplante Workflows in',
            '  öffentlichen Repositorys nach 60 Tagen ohne Aktivität ab, in Forks sind sie anfangs aus.',
            '  "Enable workflow" (oder "gh workflow enable cron.yml") schaltet ihn wieder ein.',
            '- Externer Cron-Dienst: antwortet POST /cron mit {"status":"ok"}? Ein neues APP_SECRET ändert den Schlüssel.',
            '',
            'Solange der Cronjob ausfällt, kommt diese Meldung einmal am Tag. Admins sehen den Hinweis auch in der Übersicht.',
        ];
    }

    /** @return array{last_cron: ?string, cron_watch_since: ?string, cron_alert_sent: ?string} */
    private static function settings(): array
    {
        $values = ['last_cron' => null, 'cron_watch_since' => null, 'cron_alert_sent' => null];
        foreach (Db::all("SELECT name, value FROM settings WHERE name IN ('last_cron', 'cron_watch_since', 'cron_alert_sent')") as $row) {
            $values[$row['name']] = $row['value'];
        }
        return $values;
    }
}
