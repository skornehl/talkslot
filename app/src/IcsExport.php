<?php
declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Calendar files (iCalendar, RFC 5545).
 * - forRound(): booked appointments for the teacher's summary (title = name of each appointment's round, never
 *   the parents' names: calendars are synchronised to services the school does not control, see Summaries)
 * - forParent(): own appointment as an email attachment for parents (title = name of the round and teacher)
 * - cancelForParent(): the cancellation of that appointment (METHOD:CANCEL, same UID)
 * Data-minimal: no email addresses of parents or teachers. Times in UTC, so no VTIMEZONE block needed.
 */
final class IcsExport
{
    /**
     * @param array{title: string} $round the name of the calendar, and the title of a booking without its own
     * @param list<array> $bookings rows with id, day_date, start_time, end_time and the title of their round, which
     *   may differ (the evening summary has every round of the teacher that day); a name in them is not used
     */
    public static function forRound(array $round, array $bookings): string
    {
        $events = [];
        foreach ($bookings as $b) {
            $events[] = [
                // Stable UID per booking and start time: re-importing updates instead of duplicating
                'uid' => 'booking-' . (int) $b['id'] . '-' . str_replace([':', '-'], '', $b['day_date'] . $b['start_time']),
                'date' => $b['day_date'], 'start' => $b['start_time'], 'end' => $b['end_time'],
                'summary' => (string) ($b['title'] ?? $round['title']),
            ];
        }
        return self::calendar($round['title'], $events);
    }

    /**
     * The parents' appointment. The UID is fixed per booking, SEQUENCE increases with every change,
     * so that calendars recognize a rebooking as an update of the same appointment. The title names the
     * teacher as well: parents with appointments at several teachers can tell them apart.
     */
    public static function forParent(array $booking, string $description = ''): string
    {
        return self::calendar($booking['title'], [self::parentEvent($booking, max(0, strtotime((string) ($booking['updated_at'] ?? 'now')) - strtotime((string) ($booking['created_at'] ?? 'now')))) + [
            'description' => $description,
        ]]);
    }

    /**
     * Cancellation of the parents' appointment (METHOD:CANCEL, RFC 5546): same UID, a SEQUENCE above that of
     * every earlier calendar file of the booking, STATUS:CANCELLED. The organizer is the sender address of
     * the installation (the method requires one), not a person's address.
     */
    public static function cancelForParent(array $booking): string
    {
        $from = Email::normalize((string) Config::get('mail.from_address', ''));
        return self::calendar($booking['title'], [self::parentEvent($booking, max(0, strtotime(Time::nowSql()) - strtotime((string) ($booking['created_at'] ?? 'now'))) + 1) + [
            'status' => 'CANCELLED',
            'organizer' => $from !== null ? 'ORGANIZER;CN="' . preg_replace('/[\x00-\x1F\x7F"]/', '', app_name()) . '":mailto:' . $from : '',
        ]], 'CANCEL');
    }

    private static function parentEvent(array $booking, int $sequence): array
    {
        $teacher = trim((string) ($booking['teacher_name'] ?? ''));
        return [
            'uid' => 'appointment-' . (int) $booking['id'],
            'date' => $booking['day_date'], 'start' => $booking['start_time'], 'end' => $booking['end_time'],
            'summary' => $booking['title'] . ($teacher !== '' ? ' – ' . $teacher : ''),
            'location' => trim((string) ($booking['location'] ?? '')),
            'sequence' => $sequence,
        ];
    }

    /** @param list<array{uid:string,date:string,start:string,end:string,summary:string,description?:string,location?:string,sequence?:int,status?:string,organizer?:string}> $events */
    private static function calendar(string $name, array $events, string $method = 'PUBLISH'): string
    {
        $host = (string) (parse_url((string) Config::get('base_url', ''), PHP_URL_HOST) ?: 'localhost');
        $stamp = self::utc(Time::now());
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//TalkSlot//DE',
            'CALSCALE:GREGORIAN',
            'METHOD:' . $method,
            'X-WR-CALNAME:' . self::text($name),
        ];
        foreach ($events as $ev) {
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . $ev['uid'] . '@' . $host;
            $lines[] = 'DTSTAMP:' . $stamp;
            $lines[] = 'DTSTART:' . self::utc(new DateTimeImmutable($ev['date'] . ' ' . $ev['start']));
            $lines[] = 'DTEND:' . self::utc(new DateTimeImmutable($ev['date'] . ' ' . $ev['end']));
            $lines[] = 'SEQUENCE:' . (int) ($ev['sequence'] ?? 0);
            $lines[] = 'SUMMARY:' . self::text($ev['summary']);
            if (($ev['status'] ?? '') !== '') {
                $lines[] = 'STATUS:' . $ev['status'];
            }
            if (($ev['organizer'] ?? '') !== '') {
                $lines[] = $ev['organizer'];
            }
            if (($ev['description'] ?? '') !== '') {
                $lines[] = 'DESCRIPTION:' . self::text($ev['description']);
            }
            if (($ev['location'] ?? '') !== '') {
                $lines[] = 'LOCATION:' . self::text($ev['location']);
            }
            $lines[] = 'TRANSP:OPAQUE';
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';
        return implode("\r\n", array_map([self::class, 'fold'], $lines)) . "\r\n";
    }

    private static function utc(DateTimeImmutable $d): string
    {
        return $d->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    /** Escaping for TEXT values (RFC 5545, 3.3.11). */
    private static function text(string $s): string
    {
        $s = str_replace(['\\', ';', ',', "\r\n", "\r", "\n"], ['\\\\', '\;', '\,', '\n', '\n', '\n'], $s);
        return (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $s);
    }

    /** Fold lines longer than 75 bytes without cutting UTF-8 characters (RFC 5545, 3.1). */
    private static function fold(string $line): string
    {
        $out = '';
        $limit = 75;
        while (strlen($line) > $limit) {
            $cut = $limit;
            while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--; // don't split in the middle of a multibyte character
            }
            $out .= substr($line, 0, $cut) . "\r\n ";
            $line = substr($line, $cut);
            $limit = 74; // continuation lines start with a space
        }
        return $out . $line;
    }
}
