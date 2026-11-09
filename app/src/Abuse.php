<?php
declare(strict_types=1);

namespace App;

/**
 * Abuse protection of the public pages beyond the limits per truncated IP (expert review P1-7, SEC-1/SEC-3).
 *
 * - Slot squatting: bookings count per round and truncated IP ("round", generous because parents in one school
 *   Wi-Fi or mobile network share an IP). They are counted before booking (reserve()) and given back if nothing
 *   was booked (release()), so parallel requests cannot all pass the check. A burst (more than "burst" bookings
 *   of one round from one truncated IP within a few minutes) queues a warning to the teacher of the round and
 *   all admins (outbox, at most one per round and hour), pointing to the bulk deletion and the round code. First
 *   detect and repair, then limit: the limit stays generous, the warning and deleting several bookings at once
 *   are the main remedy.
 * - Mails under the school's address: the mails parents trigger themselves (confirmation after booking, change
 *   notice after moving, cancellation, the confirmation sent again from the appointment page) are limited per
 *   recipient ("recipient" per hour, "recipient_day" per day). Above it the action still happens, only the mail
 *   is not sent; a booking whose confirmation was held back keeps no address (PublicController), so no reminder
 *   or change notice goes there later either. Mails from teachers, reminders and summaries do not count. The
 *   address is counted as an HMAC of its normalised form (address()), like every rate-limit subject, and
 *   deleted after 24 hours at the latest.
 * - Volume: all those mails together count per hour; above mail_alert_hourly the operator gets an alert
 *   (App\Alert, at most one per hour).
 */
final class Abuse
{
    /** Bookings per round and truncated IP and hour by default (rate_limits.round). */
    public const ROUND_LIMIT = 15;
    /** A mail parents triggered was not sent: the address got too many already (mailAllowed()). */
    public const LIMITED = 'limited';
    /** Providers whose addresses ignore dots in the local part (and are the same mailbox under both domains). */
    private const DOTLESS = ['gmail.com', 'googlemail.com'];

    /** Rate-limit subject of the round for this client: per round and truncated IP. */
    private static function roundSubject(int $roundId): string
    {
        return 'round:' . $roundId . '|' . RateLimiter::clientKey();
    }

    /** Has this client used up its bookings of the round? (Early check before the form is processed.) */
    public static function roundLimited(int $roundId): bool
    {
        [$limit, $window] = RateLimiter::limits('round', self::ROUND_LIMIT, 3600);
        return RateLimiter::count('round', $window, self::roundSubject($roundId)) >= $limit;
    }

    /** Counts a booking of the round before it is made. false: the limit is reached (call release()). */
    public static function reserve(array $round): bool
    {
        [$limit, $window] = RateLimiter::limits('round', self::ROUND_LIMIT, 3600);
        return RateLimiter::hit('round', $limit, $window, self::roundSubject((int) $round['id']));
    }

    /** Gives back a booking counted by reserve() that was not made. */
    public static function release(array $round): void
    {
        RateLimiter::undo('round', self::roundSubject((int) $round['id']));
    }

    /**
     * After a booking by parents: a burst from one truncated IP queues a warning to the teacher of the round
     * and the admins (one mail per address, sent from the outbox after the response).
     */
    public static function booked(array $round): void
    {
        [$burst, $burstWindow] = RateLimiter::limits('burst', 5, 300);
        if (RateLimiter::hit('burst', $burst, $burstWindow, self::roundSubject((int) $round['id']))
            || !RateLimiter::hit('burst_notice', 1, 3600, 'round:' . $round['id'])) {
            return;
        }
        $owner = Db::one('SELECT * FROM users WHERE id = ? AND is_active = 1', [(int) $round['user_id']]);
        $byAddress = [];
        foreach (array_merge($owner ? [$owner] : [], Db::all("SELECT * FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id")) as $user) {
            if (is_valid_email((string) $user['email'])) {
                $byAddress[mb_strtolower((string) $user['email'])] ??= (int) $user['id'];
            }
        }
        foreach ($byAddress as $userId) {
            Outbox::queueAccount($userId, 'burst_warning', 'round:' . $round['id']);
        }
    }

    /**
     * Sends a warning queued by booked() (from the outbox), in the language of the account. null: no longer
     * needed (account inactive or without a valid address, round gone).
     */
    public static function deliverWarning(int $userId, string $ref): ?bool
    {
        $user = Db::one('SELECT * FROM users WHERE id = ? AND is_active = 1', [$userId]);
        $round = str_starts_with($ref, 'round:') ? Db::one(
            "SELECT r.*, COALESCE(NULLIF(u.display_name, ''), u.username) AS owner FROM rounds r JOIN users u ON u.id = r.user_id WHERE r.id = ?",
            [(int) substr($ref, 6)]
        ) : null;
        if (!$user || !$round || !is_valid_email((string) $user['email'])) {
            return null;
        }
        [$burst, $burstWindow] = RateLimiter::limits('burst', 5, 300);
        [$limit] = RateLimiter::limits('round', self::ROUND_LIMIT, 3600);
        return (bool) AdminI18n::with(AdminI18n::of($user), static function () use ($user, $round, $burst, $burstWindow, $limit): bool {
            $vars = ['title' => (string) $round['title'], 'owner' => (string) $round['owner'], 'count' => $burst, 'limit' => $limit,
                'minutes' => max(1, intdiv($burstWindow, 60)), 'url' => abs_url('/admin/round/' . $round['id'], ['lang' => null]), 'app' => app_name()];
            return Mailer::send((string) $user['email'], __('Auffällig viele Buchungen in der Runde „{title}“', $vars), implode("\n", [
                __('Hallo {name},', ['name' => $user['display_name'] ?: $user['username']]),
                '',
                __('in der Runde „{title}“ ({owner}) bei {app} sind innerhalb von {minutes} Minuten mehr als {count} Buchungen aus demselben Netzwerk eingegangen.', $vars),
                __('Das können viele Eltern im selben WLAN sein, z. B. bei einem Elternabend, aber auch ein Missbrauch: Jemand belegt Termine mit Fantasienamen, damit andere Eltern keine mehr finden. Sehen die Namen echt aus, ist nichts zu tun.'),
                '',
                __('Bitte prüfen Sie die Buchungen: {url}', $vars),
                __('Unter jedem Tag lassen sich mit „Mehrere Buchungen löschen …“ die Einträge eines Zeitraums auf einmal löschen.'),
                RoundAccess::required($round)
                    ? __('Die Runde ist schon „Nur mit Rundencode“ geschützt, der Code ist also wohl weitergegeben worden. Legen Sie unter „Bearbeiten“ einen neuen Code an und geben Sie ihn nur den Eltern; bestehende Buchungen bleiben gültig.')
                    : __('Damit es nicht wieder passiert, unter „Bearbeiten“ die Option „Nur mit Rundencode“ wählen und den Code nur den Eltern geben (z. B. im Elternbrief).'),
                __('Aus einem Netzwerk nimmt die Runde höchstens {limit} Buchungen pro Stunde an; Eltern dort sehen den Hinweis, es z. B. über mobile Daten zu versuchen oder sich an Sie zu wenden.', $vars),
                '',
                __('Diese Nachricht geht an die Lehrkraft der Runde und an alle Admins, höchstens einmal pro Stunde und Runde.'),
            ]));
        });
    }

    /** Counts a mail to $email that parents trigger. false: the address got too many, do not send it (LIMITED). */
    public static function mailAllowed(string $email): bool
    {
        $subject = 'mail:' . self::address($email);
        [$limit, $window] = RateLimiter::limits('recipient', 10, 3600);
        [$dayLimit, $dayWindow] = RateLimiter::limits('recipient_day', 20, 86400);
        if (!RateLimiter::hit('recipient', $limit, $window, $subject) || !RateLimiter::hit('recipient_day', $dayLimit, $dayWindow, $subject)) {
            return false;
        }
        self::volume();
        return true;
    }

    /**
     * The address as counted, so that spellings of one mailbox share the counter: trimmed, in lower case,
     * without a "+tag" in the local part ("Anna+1@Example.org" → "anna@example.org"), and for Gmail without
     * dots and under one domain ("a.n.na@googlemail.com" → "anna@gmail.com").
     */
    public static function address(string $email): string
    {
        $email = mb_strtolower(trim($email));
        $at = strrpos($email, '@');
        if ($at === false) {
            return $email;
        }
        [$local, $domain] = [substr($email, 0, $at), substr($email, $at + 1)];
        $plus = strpos($local, '+');
        $local = $plus !== false && $plus > 0 ? substr($local, 0, $plus) : $local;
        if (in_array($domain, self::DOTLESS, true)) {
            [$local, $domain] = [str_replace('.', '', $local), self::DOTLESS[0]];
        }
        return $local . '@' . $domain;
    }

    /** Counts a mail towards the hourly volume; the operator hears when it goes above mail_alert_hourly. */
    private static function volume(): void
    {
        $threshold = max(0, (int) Config::get('mail_alert_hourly', 100));
        if ($threshold === 0 || RateLimiter::hit('mail_volume', $threshold, 3600, 'all')) {
            return;
        }
        [$perHour] = RateLimiter::limits('recipient', 10, 3600);
        [$perDay] = RateLimiter::limits('recipient_day', 20, 86400);
        // German like all alerts to the operator; no personal data. Alert sends at most one per hour.
        Alert::notify('mail_volume', 'Ungewöhnlich viele E-Mails an Eltern', [
            'In der letzten Stunde haben Eltern auf den öffentlichen Seiten mehr als ' . $threshold . ' E-Mails ausgelöst',
            '(Bestätigungen, Änderungen, Absagen; Einstellung mail_alert_hourly).',
            '',
            'Das kann normal sein, z. B. kurz nachdem ein Elternbrief mit dem Link verteilt wurde. Es kann aber auch',
            'ein Missbrauch sein: Jemand bucht mit fremden Adressen, damit Mails unter dem Absender der Schule an sie',
            'gehen. An eine einzelne Adresse gehen höchstens ' . $perHour . ' solcher Mails pro Stunde und ' . $perDay . ' pro Tag.',
            '',
            'Bitte prüfen Sie im Verwaltungsbereich die neuen Buchungen (Fantasienamen, viele Buchungen kurz',
            'nacheinander). Einträge lassen sich je Tag für einen Zeitraum auf einmal löschen. Schutz: Runden mit',
            '„Nur mit Rundencode“ veröffentlichen. Bei Fragen des Hosters zum Mailvolumen hilft diese Nachricht.',
        ]);
    }
}
