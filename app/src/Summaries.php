<?php
declare(strict_types=1);

namespace App;

/**
 * Summary emails to teachers (can be turned off per person in the profile):
 * - Daily overview the evening before: from config summary_evening_hour (default 6 pm) for the next day
 * - Overview after the booking deadline: as soon as the last slot of a day can no longer be booked
 * Content (expert review P1-4): per round and day the number of appointments, what stands out (notes()) and the
 * link to the list in the admin area, but no names, as the mails stay in mailboxes the school's deletion
 * rules may not reach. Names, and a calendar file of the appointments without names, only where the school
 * allows them (config summary_names) and the account wants them (withNames()). Never parents' addresses, and
 * nothing to an address outside allowed_account_domains (AccountDomains).
 * run() queues them (Outbox); deliver() creates the mail when the outbox sends it, so the list is
 * current then. sent_summaries prevents queueing twice; the entry is made together with the queue entry.
 */
final class Summaries
{
    /** @return array<string,int> number of summaries queued */
    public static function run(): array
    {
        return ['summaries_evening' => self::evening(), 'summaries_deadline' => self::deadline()];
    }

    private static function evening(): int
    {
        $now = Time::now();
        if ((int) $now->format('G') < (int) Config::get('summary_evening_hour', 18)) {
            return 0;
        }
        $tomorrow = $now->modify('+1 day')->format('Y-m-d');
        $users = Db::all(
            "SELECT DISTINCT u.id FROM users u
               JOIN rounds r ON r.user_id = u.id
               JOIN round_days d ON d.round_id = r.id AND d.day_date = ?
               JOIN slots s ON s.day_id = d.id
               JOIN bookings b ON b.slot_id = s.id
              WHERE u.is_active = 1 AND u.summary_evening = 1 AND COALESCE(u.email, '') <> ''",
            [$tomorrow]
        );
        $queued = 0;
        foreach ($users as $u) {
            $queued += (int) self::queue((int) $u['id'], 'evening', $tomorrow);
        }
        return $queued;
    }

    private static function deadline(): int
    {
        // Days whose last slot can no longer be booked (start − booking deadline ≤ now)
        $days = Db::all(
            "SELECT d.id, r.user_id,
                    TIMESTAMP(d.day_date, MAX(s.start_time)) - INTERVAL r.booking_deadline_hours HOUR AS closes_at
               FROM round_days d
               JOIN rounds r ON r.id = d.round_id
               JOIN users u ON u.id = r.user_id
               JOIN slots s ON s.day_id = d.id
              WHERE d.day_date >= ? AND u.is_active = 1 AND u.summary_deadline = 1 AND COALESCE(u.email, '') <> ''
                AND (r.is_active = 1 OR EXISTS (SELECT 1 FROM slots s2 JOIN bookings b2 ON b2.slot_id = s2.id WHERE s2.day_id = d.id))
              GROUP BY d.id, d.day_date, r.user_id, r.booking_deadline_hours
             HAVING closes_at <= ?",
            [Time::today(), Time::nowSql()]
        );
        $queued = 0;
        foreach ($days as $day) {
            $queued += (int) self::queue((int) $day['user_id'], 'deadline', 'day-' . $day['id']);
        }
        return $queued;
    }

    /** Records the summary and queues it; false if that has already happened. */
    private static function queue(int $userId, string $kind, string $ref): bool
    {
        return (bool) Db::transaction(static function () use ($userId, $kind, $ref): bool {
            $new = Db::exec(
                'INSERT IGNORE INTO sent_summaries (user_id, kind, ref, sent_at) VALUES (?, ?, ?, ?)',
                [$userId, $kind, $ref, Time::nowSql()]
            ) === 1;
            if ($new) {
                Outbox::queueAccount($userId, 'summary_' . $kind, $ref);
            }
            return $new;
        });
    }

    /**
     * Names (and the calendar file) in the summaries of this account: only if the school allows them (config
     * summary_names) and the account wants them (wantsNames()).
     */
    public static function withNames(array $user): bool
    {
        return (bool) Config::get('summary_names', false) && self::wantsNames($user);
    }

    /**
     * The account's choice: 1 = with names, 0 = without (new accounts). NULL = an account from before this
     * choice (migration 22): it keeps what it had, names, until it confirms the notice on the dashboard
     * (notice()), so that its summaries do not change without a word.
     */
    public static function wantsNames(array $user): bool
    {
        return array_key_exists('summary_names', $user) && ($user['summary_names'] === null || (int) $user['summary_names'] === 1);
    }

    /**
     * The notice on the dashboard for an account from before summaries without names (summary_names NULL)
     * that gets summaries: what changed for it. null: nothing to say.
     */
    public static function notice(array $user, bool $isAdmin): ?string
    {
        if (!array_key_exists('summary_names', $user) || $user['summary_names'] !== null || (!$user['summary_evening'] && !$user['summary_deadline'])) {
            return null;
        }
        if (Config::get('summary_names', false)) {
            return __('Neu: Die Kalenderdatei Ihrer Zusammenfassungen enthält keine Namen mehr. Ob die E-Mail selbst die Namen enthält, können Sie jetzt im Profil wählen; bis dahin bleibt es wie bisher.');
        }
        return __('Neu: Ihre Zusammenfassungen per E-Mail enthalten keine Namen und keine Kalenderdatei mehr, sondern je Tag die Zahl der Termine, Auffälliges und den Link zur Terminliste. So liegen keine Namenslisten in Postfächern und Kalendern, für die die Löschregeln der Schule nicht gelten.')
            . ' ' . ($isAdmin ? __('Namen kann die Schule mit „summary_names“ in config/config.php freigeben, wenn alle Konten dienstliche Adressen verwenden.') : __('Namen kann nur die Schule freigeben.'));
    }

    /**
     * Sends a queued summary (from the outbox). null: no longer needed – the account is inactive or has
     * no address (or one outside allowed_account_domains), the summary was turned off, the evening is over
     * ("tomorrow" would be wrong) or the day is gone or no longer this teacher's.
     */
    public static function deliver(string $kind, int $userId, string $ref): ?bool
    {
        $user = Db::one("SELECT * FROM users WHERE id = ? AND is_active = 1 AND COALESCE(email, '') <> ''", [$userId]);
        if ($user && !AccountDomains::permits((string) $user['email'])) {
            return null;
        }
        if ($kind === 'summary_evening' && $user && $user['summary_evening'] && $ref > Time::today()) {
            return self::sendEvening($user, $ref);
        }
        if ($kind !== 'summary_deadline' || !$user || !$user['summary_deadline']) {
            return null;
        }
        $day = Db::one('SELECT d.id, d.day_date, r.title FROM round_days d JOIN rounds r ON r.id = d.round_id WHERE d.id = ? AND r.user_id = ? AND d.day_date >= ?',
            [(int) substr($ref, 4), $userId, Time::today()]);
        return $day ? self::sendDeadline($user, $day) : null;
    }

    private static function sendEvening(array $user, string $date): bool
    {
        $rows = self::bookings('d.day_date = ? AND r.user_id = ?', [$date, $user['id']]);
        // In the teacher's language (dates as well)
        return (bool) AdminI18n::with(AdminI18n::of($user), static function () use ($user, $rows, $date): bool {
            $vars = ['count' => count($rows), 'date' => fmt_date($date)];
            $subject = count($rows) === 1 ? __('Morgen: {count} Termin ({date})', $vars) : __('Morgen: {count} Termine ({date})', $vars);
            return self::send($user, $subject, __('Ihre Termine für morgen:'), $rows, 'termine-' . $date . '.ics');
        });
    }

    private static function sendDeadline(array $user, array $day): bool
    {
        $rows = self::bookings('d.id = ?', [$day['id']]);
        $free = (int) Db::value(
            'SELECT COUNT(*) FROM slots s LEFT JOIN bookings b ON b.slot_id = s.id WHERE s.day_id = ? AND b.id IS NULL AND s.is_blocked = 0',
            [$day['id']]
        );
        return (bool) AdminI18n::with(AdminI18n::of($user), static function () use ($user, $day, $rows, $free): bool {
            $subject = __('Buchungsschluss: {title} – {date}', ['date' => fmt_date($day['day_date']), 'title' => $day['title']]);
            $intro = match (true) {
                $free === 0 => __('Für diesen Tag sind keine Buchungen mehr möglich. Endgültige Terminliste:'),
                $free === 1 => __('Für diesen Tag sind keine Buchungen mehr möglich. Endgültige Terminliste ({count} Termin blieb frei):', ['count' => $free]),
                default => __('Für diesen Tag sind keine Buchungen mehr möglich. Endgültige Terminliste ({count} Termine blieben frei):', ['count' => $free]),
            };
            return self::send($user, $subject, $intro, $rows, 'termine-' . $day['day_date'] . '.ics');
        });
    }

    private static function bookings(string $where, array $params): array
    {
        // Addresses only for notes() (the same address twice, none): they never go into the mail
        return Db::all(
            "SELECT b.id, b.name, b.email, b.source, b.mail_failed_at, s.is_conflict, d.day_date, s.start_time, s.end_time, r.title, r.id AS round_id
               FROM bookings b JOIN slots s ON s.id = b.slot_id JOIN round_days d ON d.id = s.day_id JOIN rounds r ON r.id = d.round_id
              WHERE $where ORDER BY d.day_date, s.start_time",
            $params
        );
    }

    /** Called within AdminI18n::with() in the recipient's language. */
    private static function send(array $user, string $subject, string $intro, array $rows, string $icsName): bool
    {
        $names = self::withNames($user);
        $attachments = $names && $rows ? [[
            'name' => $icsName,
            'type' => 'text/calendar; charset=utf-8; method=PUBLISH',
            'content' => IcsExport::forRound(['title' => $rows[0]['title']], $rows),
        ]] : [];
        return Mailer::send((string) $user['email'], $subject, self::text($user, $intro, $rows, $names), $attachments);
    }

    /**
     * The text of a summary, per round and day: the times and names ($names) or the number of appointments,
     * then what stands out and the link to the list. Within AdminI18n::with() in the recipient's language.
     * @param list<array> $rows from bookings(), ordered by day and time
     */
    public static function text(array $user, string $intro, array $rows, bool $names): string
    {
        $lines = [__('Hallo {name},', ['name' => $user['display_name']]), '', $intro];
        $groups = [];
        foreach ($rows as $r) {
            $groups[$r['round_id'] . ' ' . $r['day_date']][] = $r;
        }
        foreach ($groups as $group) {
            $first = $group[0];
            $lines[] = '';
            $lines[] = $first['title'] . ' – ' . fmt_date($first['day_date']);
            if ($names) {
                foreach ($group as $r) {
                    $lines[] = '  ' . fmt_range($r['start_time'], $r['end_time']) . '  ' . $r['name'];
                }
            } else {
                $vars = ['count' => count($group), 'from' => fmt_time($first['start_time']), 'to' => fmt_time($group[count($group) - 1]['end_time'])];
                $lines[] = '  ' . (count($group) === 1 ? __('1 Termin um {from}', $vars) : __('{count} Termine zwischen {from} und {to}', $vars));
            }
            if (($notes = self::notes($group)) !== []) {
                $lines[] = '  ' . __('Auffällig: {notes}', ['notes' => implode(', ', $notes)]);
            }
            $lines[] = '  ' . __('Übersicht und Druckansicht: {url}', ['url' => abs_url('/admin/round/' . (int) $first['round_id'], ['lang' => null])]);
        }
        if (!$rows) {
            $lines[] = '';
            $lines[] = __('(keine Buchungen)');
        }
        $lines[] = '';
        $lines[] = '--';
        $lines[] = $names
            ? __('Diese Zusammenfassung enthält Namen. Bitte löschen Sie sie, sobald Sie sie nicht mehr brauchen, spätestens zum Ende des Schuljahres. Abbestellen können Sie sie im Profil.')
            : __('Diese Zusammenfassung enthält keine Namen: Die Terminliste sehen Sie nach der Anmeldung über den Link. Abbestellen können Sie sie im Profil.');
        return implode("\n", $lines);
    }

    /**
     * What stands out in the bookings of a day, without names or addresses: the same name or address more than
     * once (siblings, or someone booking many slots), parents' bookings without an address (they cannot be told
     * about changes), mails that could not be delivered and appointments that no longer fit the schedule.
     * @param list<array> $rows from bookings()
     * @return list<string> e.g. ["2× gleicher Name", "1× ohne E-Mail-Adresse"]
     */
    public static function notes(array $rows): array
    {
        $names = $emails = [];
        $noEmail = $failed = $conflicts = 0;
        foreach ($rows as $r) {
            $name = mb_strtolower((string) preg_replace('/\s+/u', ' ', trim((string) $r['name'])));
            $names[$name] = ($names[$name] ?? 0) + 1;
            $email = mb_strtolower(trim((string) ($r['email'] ?? '')));
            if ($email !== '') {
                $emails[$email] = ($emails[$email] ?? 0) + 1;
            } elseif (($r['source'] ?? 'parent') === 'parent') {
                $noEmail++;
            }
            $failed += ($r['mail_failed_at'] ?? null) !== null ? 1 : 0;
            $conflicts += !empty($r['is_conflict']) ? 1 : 0;
        }
        $twice = static fn (array $counts): int => array_sum(array_filter($counts, static fn (int $n): bool => $n > 1));
        return array_values(array_filter([
            $twice($names) ? __('{count}× gleicher Name', ['count' => $twice($names)]) : '',
            $twice($emails) ? __('{count}× gleiche E-Mail-Adresse', ['count' => $twice($emails)]) : '',
            $noEmail ? __('{count}× ohne E-Mail-Adresse', ['count' => $noEmail]) : '',
            $failed ? __('{count}× E-Mail nicht zugestellt', ['count' => $failed]) : '',
            $conflicts ? __('{count}× passt nicht mehr zum Zeitplan', ['count' => $conflicts]) : '',
        ]));
    }
}
