<?php
declare(strict_types=1);

namespace App;

/**
 * Email notifications to parents – only if they voluntarily provided an address.
 * Replies go to the teacher (Reply-To). Summaries for teachers: see Summaries.
 * Mails to the address stored with the booking record their result there (BookingService::mailResult()).
 */
final class Notifier
{
    /** Results of sendOrQueue() */
    public const SENT = 'sent';
    public const QUEUED = 'queued';

    /** @param array $b booking from BookingService::findById/findByToken */
    public static function parentBooked(array $b, #[\SensitiveParameter] string $token): bool
    {
        return self::toBooking($b, 'booked', $token);
    }

    public static function parentChanged(array $b, #[\SensitiveParameter] ?string $token): bool
    {
        return self::toBooking($b, 'changed', $token);
    }

    /** After the parents cancelled: the booking is gone, so a failed mail cannot be retried. */
    public static function parentCancelled(array $b): bool
    {
        return self::toParent($b, 'cancelled', null);
    }

    /** Reminder the evening before (see Reminders), with the link of its own token. */
    public static function parentReminder(array $b, #[\SensitiveParameter] string $token): bool
    {
        return self::toBooking($b, 'reminder', $token);
    }

    /**
     * Confirmation ('booked') or change notice ('changed') to the booking's address, sent right away so the
     * page can say so. If that fails, the mail goes to the outbox and is sent later; a confirmation then
     * gets a new link, as only the hash of $token is stored. null: the booking has no address.
     */
    public static function sendOrQueue(array $b, string $type, #[\SensitiveParameter] ?string $token): ?string
    {
        if ((string) ($b['email'] ?? '') === '') {
            return null;
        }
        if (self::toBooking($b, $type, $token)) {
            return self::SENT;
        }
        Outbox::queueBooking((int) $b['id'], $type, true);
        return self::QUEUED;
    }

    private static function toBooking(array $b, string $type, #[\SensitiveParameter] ?string $token): bool
    {
        $sent = self::toParent($b, $type, $token);
        if ((string) ($b['email'] ?? '') !== '') {
            BookingService::mailResult($b, $type, $sent);
        }
        return $sent;
    }

    /**
     * The confirmation again, sent from the appointment page to an address entered there (it is not stored),
     * in the language of the page. The footer says why the mail was sent.
     */
    public static function parentConfirmation(array $b, #[\SensitiveParameter] string $token, string $email): bool
    {
        return self::toParent(['email' => $email, 'lang' => I18n::lang()] + $b, 'booked', $token, 'mail.footer.copy');
    }

    private static function toParent(array $b, string $type, #[\SensitiveParameter] ?string $token, string $footer = 'mail.footer'): bool
    {
        $email = (string) ($b['email'] ?? '');
        if ($email === '') {
            return false;
        }
        return (bool) I18n::with((string) $b['lang'], static function () use ($b, $type, $token, $email, $footer): bool {
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
            return Mailer::send($email, t("mail.$type.subject", $vars), $body . "\n\n" . t($footer, $vars), $attachments, $replyTo);
        });
    }
}
