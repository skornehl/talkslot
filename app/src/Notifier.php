<?php
declare(strict_types=1);

namespace App;

/**
 * Email notifications to parents – only if they voluntarily provided an address.
 * Replies go to the teacher (Reply-To, see replyTo()). Summaries for teachers: see Summaries.
 * Mails to the address stored with the booking record their result there (BookingService::mailResult()).
 * Changes by the teacher (ScheduleService) send change notices and cancellations; a cancellation is sent once
 * the booking is gone, from what cancellationData() kept of it.
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

    /**
     * What the cancellation by the teacher needs once the booking is deleted (kept in the outbox until it is
     * sent): address, language, name and appointment, the title already in the parents' language (the
     * translations may go with the round). No link and no token: the booking is gone.
     * @param array $b booking from BookingService::findById() (DETAIL_SQL)
     */
    public static function cancellationData(array $b): array
    {
        $lang = (string) $b['lang'];
        $title = Translator::localize($b, (int) $b['round_id'], $lang);
        return [
            'booking_id' => (int) $b['id'], 'round_id' => (int) $b['round_id'], 'email' => (string) $b['email'], 'lang' => $lang,
            'name' => (string) $b['name'], 'title' => (string) $title['title'], 'auto_translated' => (bool) $title['auto_translated'],
            'source_lang' => (string) $b['source_lang'], 'teacher_name' => (string) ($b['teacher_name'] ?? ''),
            'teacher_email' => (string) self::replyTo($b), 'day_date' => (string) $b['day_date'],
            'start_time' => (string) $b['start_time'], 'end_time' => (string) $b['end_time'],
            'location' => trim((string) ($b['location'] ?? '')), 'created_at' => (string) $b['created_at'],
        ];
    }

    /**
     * Where the parents' replies go (Reply-To): the account's reply address for parents, else its e-mail address,
     * each only if it is valid and allowed_account_domains permits it (AccountDomains). A reply address from
     * before the restriction thus falls back to an allowed account address. Null: config mail.reply_to applies.
     * @param array $b booking from DETAIL_SQL, or cancellationData() (teacher_email is then this address)
     */
    public static function replyTo(array $b): ?string
    {
        foreach ([(string) ($b['teacher_reply_to'] ?? ''), (string) ($b['teacher_email'] ?? '')] as $address) {
            if (filter_var($address, FILTER_VALIDATE_EMAIL) && AccountDomains::permits($address)) {
                return $address;
            }
        }
        return null;
    }

    /**
     * Cancellation by the teacher (booking, day or round deleted), from cancellationData(). If the round still
     * takes bookings, the mail offers its page to book another appointment (the public link, no token).
     */
    public static function teacherCancelled(array $data): bool
    {
        $round = Db::one('SELECT r.slug FROM rounds r JOIN users u ON u.id = r.user_id WHERE r.id = ? AND r.is_active = 1 AND u.is_active = 1', [(int) ($data['round_id'] ?? 0)]);
        return self::toParent(['id' => $data['booking_id'] ?? 0, 'localized' => true, 'rebook' => $round['slug'] ?? null] + $data, 'cancelled_teacher', null);
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
            if (empty($b['localized'])) {
                $b = Translator::localize($b, (int) $b['round_id'], I18n::lang()); // title in the parents' language (also in the .ics)
            }
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
                // The privacy notice in the parents' language (footer)
                'privacy' => abs_url('/datenschutz', ['lang' => I18n::lang()]),
            ];
            $body = t("mail.$type.body", $vars);
            if (in_array($type, ['booked', 'changed', 'reminder'], true)) {
                // Until when the link still changes or cancels the appointment (date and time), or that it no longer does
                $body .= "\n\n" . BookingService::changeNotice($b);
            }
            if (!empty($b['rebook'])) {
                $body .= "\n\n" . t('mail.cancelled_teacher.rebook', ['url' => abs_url('/' . $b['rebook'], ['lang' => I18n::lang()])]);
            }
            if (!empty($b['auto_translated'])) {
                $body .= "\n\n" . t('round.auto_translated', ['source' => I18n::name((string) $b['source_lang'])]);
            }
            $attachments = [];
            if ($type === 'booked' || $type === 'changed') {
                // Appointment as calendar file; title = name of the round and teacher, description = personal link
                $description = $link !== '' ? t('mail.ics.description', ['link' => $link]) : '';
                $attachments[] = [
                    'name' => 'termin.ics',
                    'type' => 'text/calendar; charset=utf-8; method=PUBLISH',
                    'content' => IcsExport::forParent($b, $description),
                ];
                $body .= "\n\n" . t('mail.ics.hint');
            } elseif ($type === 'cancelled' || $type === 'cancelled_teacher') {
                // Same UID as the appointment's calendar file: calendars remove it or mark it as cancelled
                $attachments[] = [
                    'name' => 'termin.ics',
                    'type' => 'text/calendar; charset=utf-8; method=CANCEL',
                    'content' => IcsExport::cancelForParent($b),
                ];
                $body .= "\n\n" . t('mail.ics.cancel_hint');
            }
            return Mailer::send($email, t("mail.$type.subject", $vars), $body . "\n\n" . t($footer, $vars), $attachments, self::replyTo($b));
        });
    }
}
