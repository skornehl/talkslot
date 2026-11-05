<?php
declare(strict_types=1);

namespace App;

/**
 * Abuse protection of the public pages beyond the limits per truncated IP (expert review P1-7, SEC-1/SEC-3).
 *
 * - Slot squatting: successful bookings count per round and truncated IP ("round", generous because parents in
 *   one school Wi-Fi or mobile network share an IP). A burst (more than "burst" bookings of one round from one
 *   truncated IP within a few minutes) sends a warning to the teacher of the round and all admins, at most one
 *   per round and hour, pointing to the bulk deletion and the round code. First detect and repair, then limit:
 *   the limit stays generous, the warning and deleting several bookings at once are the main remedy.
 * - Mails under the school's address: the mails parents trigger themselves (confirmation after booking, change
 *   notice after moving, cancellation, the confirmation sent again from the appointment page) are limited per
 *   recipient ("recipient" per hour, "recipient_day" per day). Above it the action still happens, only the mail
 *   is not sent. Mails from teachers, reminders and summaries do not count. The address is counted as an HMAC of
 *   its normalised form (address()), like every rate-limit subject, and deleted after 24 hours at the latest.
 * - Volume: all those mails together count per hour; above mail_alert_hourly the operator gets an alert
 *   (App\Alert, at most one per hour).
 */
final class Abuse
{
    /** Successful bookings per round and truncated IP and hour by default (rate_limits.round). */
    public const ROUND_LIMIT = 15;
    /** A mail parents triggered was not sent: the address got too many already (mailAllowed()). */
    public const LIMITED = 'limited';

    /** Rate-limit subject of the round for this client: per round and truncated IP. */
    private static function roundSubject(int $roundId): string
    {
        return 'round:' . $roundId . '|' . RateLimiter::clientKey();
    }

    /** Has this client used up its bookings of the round? (Checked before booking, counted by booked().) */
    public static function roundLimited(int $roundId): bool
    {
        [$limit, $window] = RateLimiter::limits('round', self::ROUND_LIMIT, 3600);
        return RateLimiter::count('round', $window, self::roundSubject($roundId)) >= $limit;
    }

    /**
     * Counts a successful booking by parents in the round. A burst from one truncated IP warns the teacher of
     * the round and the admins.
     */
    public static function booked(array $round): void
    {
        $subject = self::roundSubject((int) $round['id']);
        [$limit, $window] = RateLimiter::limits('round', self::ROUND_LIMIT, 3600);
        RateLimiter::add('round', $window, 1, $subject);
        [$burst, $burstWindow] = RateLimiter::limits('burst', 5, 300);
        if (!RateLimiter::hit('burst', $burst, $burstWindow, $subject)
            && RateLimiter::hit('burst_notice', 1, 3600, 'round:' . $round['id'])) {
            self::warn($round, $burst, $burstWindow);
        }
    }

    /** Counts a mail to $email that parents trigger. false: the address got too many, do not send it (LIMITED). */
    public static function mailAllowed(string $email): bool
    {
        $subject = 'mail:' . self::address($email);
        [$limit, $window] = RateLimiter::limits('recipient', 3, 3600);
        [$dayLimit, $dayWindow] = RateLimiter::limits('recipient_day', 10, 86400);
        if (!RateLimiter::hit('recipient', $limit, $window, $subject) || !RateLimiter::hit('recipient_day', $dayLimit, $dayWindow, $subject)) {
            return false;
        }
        self::volume();
        return true;
    }

    /**
     * The address as counted: trimmed, in lower case and without a "+tag" in the local part, which most
     * providers deliver to the same mailbox ("Anna+1@Example.org" → "anna@example.org").
     */
    public static function address(string $email): string
    {
        $email = mb_strtolower(trim($email));
        $at = strrpos($email, '@');
        if ($at === false) {
            return $email;
        }
        $local = substr($email, 0, $at);
        $plus = strpos($local, '+');
        return ($plus !== false && $plus > 0 ? substr($local, 0, $plus) : $local) . substr($email, $at);
    }

    /** Counts a mail towards the hourly volume; the operator hears when it goes above mail_alert_hourly. */
    private static function volume(): void
    {
        $threshold = max(0, (int) Config::get('mail_alert_hourly', 100));
        if ($threshold === 0 || RateLimiter::hit('mail_volume', $threshold, 3600, 'all')) {
            return;
        }
        // German like all alerts to the operator; no personal data. Alert sends at most one per hour.
        Alert::notify('mail_volume', 'Ungewöhnlich viele E-Mails an Eltern', [
            'In der letzten Stunde haben Eltern auf den öffentlichen Seiten mehr als ' . $threshold . ' E-Mails ausgelöst',
            '(Bestätigungen, Änderungen, Absagen; Einstellung mail_alert_hourly).',
            '',
            'Das kann normal sein, z. B. kurz nachdem ein Elternbrief mit dem Link verteilt wurde. Es kann aber auch',
            'ein Missbrauch sein: Jemand bucht mit fremden Adressen, damit Mails unter dem Absender der Schule an sie',
            'gehen. An eine einzelne Adresse gehen höchstens rate_limits.recipient bzw. recipient_day solcher Mails.',
            '',
            'Bitte prüfen Sie im Verwaltungsbereich die neuen Buchungen (Fantasienamen, viele Buchungen kurz',
            'nacheinander). Einträge lassen sich je Tag für einen Zeitraum auf einmal löschen. Schutz: Runden mit',
            '„Nur mit Rundencode“ veröffentlichen. Bei Fragen des Hosters zum Mailvolumen hilft diese Nachricht.',
        ]);
    }

    /** Warning to the teacher of the round and all admins, each in their language, one mail per address. */
    private static function warn(array $round, int $count, int $seconds): void
    {
        $owner = Db::one('SELECT * FROM users WHERE id = ? AND is_active = 1', [(int) $round['user_id']]);
        $recipients = array_merge($owner ? [$owner] : [], Db::all("SELECT * FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id"));
        $byAddress = [];
        foreach ($recipients as $user) {
            if (is_valid_email((string) $user['email'])) {
                $byAddress[mb_strtolower((string) $user['email'])] ??= $user;
            }
        }
        $ownerName = $owner ? (string) ($owner['display_name'] ?: $owner['username']) : '';
        foreach ($byAddress as $user) {
            [$subject, $body] = AdminI18n::with(AdminI18n::of($user), static function () use ($user, $round, $count, $seconds, $ownerName): array {
                $vars = ['title' => (string) $round['title'], 'owner' => $ownerName, 'count' => $count,
                    'minutes' => max(1, intdiv($seconds, 60)), 'url' => abs_url('/admin/round/' . $round['id'], ['lang' => null]), 'app' => app_name()];
                return [__('Auffällig viele Buchungen in der Runde „{title}“', $vars), implode("\n", [
                    __('Hallo {name},', ['name' => $user['display_name'] ?: $user['username']]),
                    '',
                    __('in der Runde „{title}“ ({owner}) bei {app} sind innerhalb von {minutes} Minuten mehr als {count} Buchungen aus demselben Netzwerk eingegangen.', $vars),
                    __('Das können viele Eltern im selben WLAN sein, z. B. bei einem Elternabend, aber auch ein Missbrauch: Jemand belegt Termine mit Fantasienamen, damit andere Eltern keine mehr finden.'),
                    '',
                    __('Bitte prüfen Sie die Buchungen: {url}', $vars),
                    __('Unter jedem Tag lassen sich mit „Mehrere Buchungen löschen …“ alle Einträge eines Zeitraums auf einmal löschen. Damit es nicht wieder passiert, unter „Bearbeiten“ die Option „Nur mit Rundencode“ wählen und den Code nur den Eltern geben (z. B. im Elternbrief).'),
                    '',
                    __('Diese Nachricht geht an die Lehrkraft der Runde und an alle Admins, höchstens einmal pro Stunde und Runde.'),
                ])];
            });
            Mailer::send((string) $user['email'], $subject, $body);
        }
    }
}
