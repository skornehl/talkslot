<?php
declare(strict_types=1);

namespace App;

/**
 * Sends plain-text emails via PHP mail() or SMTP (bundled PHPMailer).
 * Switchable via config "mail.transport": mail | smtp | none.
 * Errors are logged without the recipient address.
 */
final class Mailer
{
    /**
     * @param list<array{name:string,type:string,content:string}> $attachments e.g. an .ics file
     * @param ?string $replyTo reply address (e.g. the teacher); otherwise config mail.reply_to
     */
    public static function send(string $to, string $subject, string $body, array $attachments = [], ?string $replyTo = null): bool
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $from = (string) Config::get('mail.from_address', '');
        if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
            ErrorPage::writeLog('Mailer: mail.from_address ist nicht gesetzt – keine Mail versendet');
            return false;
        }
        $subject = str_replace(["\r", "\n"], ' ', $subject);
        try {
            $ok = match ((string) Config::get('mail.transport', 'mail')) {
                'smtp' => self::viaSmtp($to, $subject, $body, $from, $attachments, $replyTo),
                'none' => true,
                default => self::viaMail($to, $subject, $body, $from, $attachments, $replyTo),
            };
        } catch (\Throwable $e) {
            $reason = get_class($e) . ': ' . ErrorPage::sanitize($e->getMessage());
            ErrorPage::writeLog('Mailer: Versand fehlgeschlagen (' . $reason . ')');
            Alert::notify('mail', 'Mailversand fehlgeschlagen', ['Mailversand fehlgeschlagen: ' . $reason]);
            return false;
        }
        if (!$ok) {
            ErrorPage::writeLog('Mailer: Versand fehlgeschlagen');
            Alert::notify('mail', 'Mailversand fehlgeschlagen', ['Mailversand fehlgeschlagen (Transport meldet Fehler, keine Details).']);
        }
        return $ok;
    }

    private static function viaMail(string $to, string $subject, string $body, string $from, array $attachments, ?string $replyTo): bool
    {
        $name = (string) Config::get('mail.from_name', '');
        $headers = [
            'From' => $name !== '' ? mb_encode_mimeheader($name, 'UTF-8', 'Q') . ' <' . $from . '>' : $from,
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => 'quoted-printable',
            'Auto-Submitted' => 'auto-generated',
        ];
        $replyTo = $replyTo ?: (string) Config::get('mail.reply_to', '');
        if (filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $headers['Reply-To'] = $replyTo;
        }
        $lines = preg_split('/\R/', $body) ?: [];
        $encoded = implode("\n", array_map(
            static fn (string $l) => str_replace("=\r\n", "=\n", quoted_printable_encode($l)),
            $lines
        ));
        if ($attachments) {
            // multipart/mixed: text part + attachments (base64)
            $boundary = 'tp-' . bin2hex(random_bytes(12));
            $textType = $headers['Content-Type'];
            unset($headers['Content-Transfer-Encoding']);
            $headers['Content-Type'] = 'multipart/mixed; boundary="' . $boundary . '"';
            $parts = ["--$boundary\nContent-Type: $textType\nContent-Transfer-Encoding: quoted-printable\n\n$encoded\n"];
            foreach ($attachments as $a) {
                $name = (string) preg_replace('/[^A-Za-z0-9._-]/', '_', $a['name']);
                $parts[] = "--$boundary\nContent-Type: {$a['type']}; name=\"$name\"\n"
                    . "Content-Disposition: attachment; filename=\"$name\"\nContent-Transfer-Encoding: base64\n\n"
                    . rtrim(chunk_split(base64_encode($a['content']), 76, "\n")) . "\n";
            }
            $encoded = implode('', $parts) . "--$boundary--\n";
        }
        return mail($to, mb_encode_mimeheader($subject, 'UTF-8', 'Q'), $encoded, $headers, '-f' . $from);
    }

    private static function viaSmtp(string $to, string $subject, string $body, string $from, array $attachments, ?string $replyTo): bool
    {
        $dir = APP_ROOT . '/app/vendor/phpmailer/src/';
        require_once $dir . 'Exception.php';
        require_once $dir . 'PHPMailer.php';
        require_once $dir . 'SMTP.php';

        $m = new \PHPMailer\PHPMailer\PHPMailer(true);
        $m->isSMTP();
        $m->Host = (string) Config::get('mail.smtp.host', '');
        $m->Port = (int) Config::get('mail.smtp.port', 587);
        $user = (string) Config::get('mail.smtp.user', '');
        $m->SMTPAuth = $user !== '';
        $m->Username = $user;
        $m->Password = (string) Config::get('mail.smtp.pass', '');
        // Only "none" disables encryption; anything else except "ssl" means STARTTLS
        $enc = (string) Config::get('mail.smtp.encryption', 'tls');
        $enc = in_array($enc, ['ssl', 'none'], true) ? $enc : 'tls';
        $m->Timeout = 15; // don't hang for minutes if the mail server does not respond
        if ($enc === 'ssl') {
            $m->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($enc === 'tls') {
            $m->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $m->SMTPSecure = '';
            $m->SMTPAutoTLS = false;
        }
        $m->CharSet = 'UTF-8';
        $m->Encoding = 'quoted-printable';
        $m->XMailer = ' ';
        $m->setFrom($from, (string) Config::get('mail.from_name', ''));
        $replyTo = $replyTo ?: (string) Config::get('mail.reply_to', '');
        if (filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
            $m->addReplyTo($replyTo);
        }
        $m->addAddress($to);
        $m->addCustomHeader('Auto-Submitted', 'auto-generated');
        $m->isHTML(false);
        $m->Subject = $subject;
        $m->Body = $body;
        foreach ($attachments as $a) {
            $m->addStringAttachment($a['content'], $a['name'], \PHPMailer\PHPMailer\PHPMailer::ENCODING_BASE64, $a['type']);
        }
        return $m->send();
    }
}
