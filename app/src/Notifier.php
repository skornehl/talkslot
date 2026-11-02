<?php
declare(strict_types=1);

namespace App;

/**
 * E-Mail-Benachrichtigungen an Eltern – nur, wenn sie freiwillig eine Adresse angegeben haben.
 * Antworten gehen an die Lehrkraft (Reply-To). Zusammenfassungen für Lehrkräfte: siehe Summaries.
 */
final class Notifier
{
    /** @param array $b Buchung aus BookingService::findById/findByToken */
    public static function parentBooked(array $b, string $token): bool
    {
        return self::toParent($b, 'booked', $token);
    }

    public static function parentChanged(array $b, ?string $token): bool
    {
        return self::toParent($b, 'changed', $token);
    }

    public static function parentCancelled(array $b): bool
    {
        return self::toParent($b, 'cancelled', null);
    }

    private static function toParent(array $b, string $type, ?string $token): bool
    {
        $email = (string) ($b['email'] ?? '');
        if ($email === '') {
            return false;
        }
        return (bool) I18n::with((string) $b['lang'], static function () use ($b, $type, $token, $email): bool {
            $b = Translator::localize($b, (int) $b['round_id'], I18n::lang()); // Titel in der Sprache der Eltern (auch in der .ics)
            $link = $token !== null ? abs_url('/b/' . $token, ['lang' => I18n::lang()]) : '';
            $vars = [
                'title' => $b['title'],
                'when' => fmt_slot($b['day_date'], $b['start_time'], $b['end_time']),
                'name' => $b['name'],
                'link' => $link !== '' ? $link : t('mail.link_unchanged'),
                'app' => app_name(),
                'teacher' => (string) ($b['teacher_name'] ?? ''),
                'controller' => Operator::name() !== '' ? Operator::name() : (string) ($b['teacher_name'] ?? ''),
            ];
            $body = t("mail.$type.body", $vars);
            if (!empty($b['auto_translated'])) {
                $body .= "\n\n" . t('round.auto_translated', ['source' => I18n::name((string) $b['source_lang'])]);
            }
            $attachments = [];
            if ($type !== 'cancelled') {
                // Termin als Kalenderdatei; Titel = Name der Runde, Beschreibung = persönlicher Link
                $description = $link !== '' ? t('mail.ics.description', ['link' => $link]) : '';
                $attachments[] = [
                    'name' => 'termin.ics',
                    'type' => 'text/calendar; charset=utf-8; method=PUBLISH',
                    'content' => IcsExport::forParent($b, $description),
                ];
                $body .= "\n\n" . t('mail.ics.hint');
            }
            $replyTo = filter_var((string) ($b['teacher_email'] ?? ''), FILTER_VALIDATE_EMAIL) ? (string) $b['teacher_email'] : null;
            return Mailer::send($email, t("mail.$type.subject", $vars), $body . "\n\n" . t('mail.footer', $vars), $attachments, $replyTo);
        });
    }
}
