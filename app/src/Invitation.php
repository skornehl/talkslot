<?php
declare(strict_types=1);

namespace App;

/** Invitation text for parents on the round page of the admin area (to copy or open as an e-mail). */
final class Invitation
{
    /**
     * Text to pass on to parents (link and access code if applicable), in the admin language. Below the link
     * one line per further parent language with its own name and a direct link (?lang=), so that parents
     * with little German find their language before they open the page; the plain link shows the default
     * language. Names rather than sentences keep the text short enough for "Als E-Mail öffnen" (some mail
     * programs cut mailto: links after about 2,000 characters). The sentence on multiple bookings is the
     * one the round page shows.
     */
    public static function text(array $round, array $owner): string
    {
        $lines = [
            __('Liebe Eltern,'),
            '',
            __('über den folgenden Link können Sie einen Gesprächstermin für „{title}“ bei {teacher} buchen:', ['title' => $round['title'], 'teacher' => $owner['display_name'] ?: __('mir')]),
            abs_url('/' . $round['slug']),
        ];
        $other = array_diff(I18n::languages(), [I18n::defaultLanguage()]);
        if ($other) {
            $lines[] = '';
            foreach ($other as $lang) {
                $lines[] = I18n::name($lang) . ': ' . abs_url('/' . $round['slug'], ['lang' => $lang]);
            }
        }
        if (trim((string) ($round['location'] ?? '')) !== '') {
            $lines[] = '';
            $lines[] = __('Ort: {location}', ['location' => trim((string) $round['location'])]);
        }
        $code = RoundCode::format($round['code'] ?? null);
        if ($code !== '' && RoundAccess::required($round)) {
            $lines[] = '';
            $lines[] = __('Code der Terminvergabe: {code} (wird nach dem Öffnen des Links abgefragt).', ['code' => $code]);
            $lines[] = __('Alternativ können Sie auf {url} nur diesen Code eingeben.', ['url' => abs_url('/')]);
            $lines[] = __('Bitte geben Sie Link und Code nicht weiter.');
        } elseif ($code !== '') {
            $lines[] = __('Alternativ können Sie auf {url} den Code der Terminvergabe {code} eingeben.', ['url' => abs_url('/'), 'code' => $code]);
        }
        $lines[] = '';
        $lines[] = I18n::textIn(AdminI18n::lang(), BookingService::limitNotice($round)) . ' '
            . __('Nach der Buchung erhalten Sie einen persönlichen Link, über den Sie den Termin ändern oder absagen können.');
        $lines[] = '';
        $lines[] = __('Viele Grüße');
        return implode("\n", $lines);
    }
}
