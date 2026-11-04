<?php
declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Calendar files (iCalendar, RFC 5545).
 * - forRound(): booked appointments of a round for the teacher (title = name from the free-text field)
 * - forParent(): own appointment as an email attachment for parents (title = name of the round)
 * Data-minimal: no email addresses. Times in UTC, so no VTIMEZONE block needed.
 */
final class IcsExport
{
    /** @param list<array> $bookings rows with id, name, day_date, start_time, end_time */
    public static function forRound(array $round, array $bookings): string
    {
        $events = [];
        foreach ($bookings as $b) {
            $events[] = [
                // Stable UID per booking and start time: re-importing updates instead of duplicating
                'uid' => 'booking-' . (int) $b['id'] . '-' . str_replace([':', '-'], '', $b['day_date'] . $b['start_time']),
                'date' => $b['day_date'], 'start' => $b['start_time'], 'end' => $b['end_time'],
                'summary' => $b['name'],
            ];
        }
        return self::calendar($round['title'], $events);
    }

    /**
     * The parents' appointment. The UID is fixed per booking, SEQUENCE increases with every change,
     * so that calendars recognize a rebooking as an update of the same appointment.
     */
    public static function forParent(array $booking, string $description = ''): string
    {
        return self::calendar($booking['title'], [[
            'uid' => 'appointment-' . (int) $booking['id'],
            'date' => $booking['day_date'], 'start' => $booking['start_time'], 'end' => $booking['end_time'],
            'summary' => $booking['title'],
            'description' => $description,
            'location' => trim((string) ($booking['location'] ?? '')),
            'sequence' => max(0, strtotime((string) ($booking['updated_at'] ?? 'now')) - strtotime((string) ($booking['created_at'] ?? 'now'))),
        ]]);
    }

    /** @param list<array{uid:string,date:string,start:string,end:string,summary:string,description?:string,location?:string,sequence?:int}> $events */
    private static function calendar(string $name, array $events): string
    {
        $host = (string) (parse_url((string) Config::get('base_url', ''), PHP_URL_HOST) ?: 'localhost');
        $stamp = self::utc(Time::now());
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//TalkSlot//DE',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
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
