<?php
declare(strict_types=1);

namespace App;

/**
 * Email notifications to parents – only if they voluntarily provided an address.
 * Replies go to the teacher (Reply-To). Summaries for teachers: see Summaries.
 */
final class Notifier
{
    /** @param array $b booking from BookingService::findById/findByToken */
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

    /** Reminder the evening before (see Reminders), with the link of its own token. */
    public static function parentReminder(array $b, string $token): bool
    {
        return self::toParent($b, 'reminder', $token);
    }

    private static function toParent(array $b, string $type, ?string $token): bool
    {
        $email = (string) ($b['email'] ?? '');
        if ($email === '') {
            return false;
        }
        return (bool) I18n::with((string) $b['lang'], static function () use ($b, $type, $token, $email): bool {
            $b = Translator::localize($b, (int) $b['round_id'], I18n::lang()); // title in the parents' language (also in the .ics)
            $link = $token !== null ? abs_url('/b/' . $token, ['lang' => I18n::lang()]) : '';
            $vars = [
                'title' => $b['title'],
                'when' => fmt_slot($b['day_date'], $b['start_time'], $b['end_time'])
                    . (trim((string) ($b['location'] ?? '')) !== '' ? "\n" . t('round.location', ['location' => trim((string) $b['location'])]) : ''),
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
            if ($type === 'booked' || $type === 'changed') {
                // Appointment as calendar file; title = name of the round, description = personal link
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
