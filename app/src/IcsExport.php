<?php
declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Kalenderdateien (iCalendar, RFC 5545).
 * - forRound(): gebuchte Termine einer Runde für die Lehrkraft (Titel = Name aus dem Freitextfeld)
 * - forParent(): eigener Termin als Mail-Anhang für Eltern (Titel = Name der Runde)
 * Datensparsam: keine E-Mail-Adressen. Zeiten in UTC, daher kein VTIMEZONE-Block nötig.
 */
final class IcsExport
{
    /** @param list<array> $bookings Zeilen mit id, name, day_date, start_time, end_time */
    public static function forRound(array $round, array $bookings): string
    {
        $events = [];
        foreach ($bookings as $b) {
            $events[] = [
                // Stabile UID je Buchung und Startzeit: erneuter Import aktualisiert statt zu verdoppeln
                'uid' => 'booking-' . (int) $b['id'] . '-' . str_replace([':', '-'], '', $b['day_date'] . $b['start_time']),
                'date' => $b['day_date'], 'start' => $b['start_time'], 'end' => $b['end_time'],
                'summary' => $b['name'],
            ];
        }
        return self::calendar($round['title'], $events);
    }

    /**
     * Termin der Eltern. Die UID ist je Buchung fest, SEQUENCE steigt mit jeder Änderung,
     * damit Kalender eine Umbuchung als Aktualisierung desselben Termins erkennen.
     */
    public static function forParent(array $booking, string $description = ''): string
    {
        return self::calendar($booking['title'], [[
            'uid' => 'appointment-' . (int) $booking['id'],
            'date' => $booking['day_date'], 'start' => $booking['start_time'], 'end' => $booking['end_time'],
            'summary' => $booking['title'],
            'description' => $description,
            'sequence' => max(0, strtotime((string) ($booking['updated_at'] ?? 'now')) - strtotime((string) ($booking['created_at'] ?? 'now'))),
        ]]);
    }

    /** @param list<array{uid:string,date:string,start:string,end:string,summary:string,description?:string,sequence?:int}> $events */
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

    /** Escaping für TEXT-Werte (RFC 5545, 3.3.11). */
    private static function text(string $s): string
    {
        $s = str_replace(['\\', ';', ',', "\r\n", "\r", "\n"], ['\\\\', '\;', '\,', '\n', '\n', '\n'], $s);
        return (string) preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $s);
    }

    /** Zeilen über 75 Byte falten, ohne UTF-8-Zeichen zu zerschneiden (RFC 5545, 3.1). */
    private static function fold(string $line): string
    {
        $out = '';
        $limit = 75;
        while (strlen($line) > $limit) {
            $cut = $limit;
            while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
                $cut--; // nicht mitten in einem Mehrbyte-Zeichen trennen
            }
            $out .= substr($line, 0, $cut) . "\r\n ";
            $line = substr($line, $cut);
            $limit = 74; // Folgezeilen beginnen mit einem Leerzeichen
        }
        return $out . $line;
    }
}
