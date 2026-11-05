<?php
/** English texts of the admin area: German original => English (see App\AdminI18n). Mails to parents and the mail queue. */
return [
    // Entry with "send confirmation" / "send change by e-mail" when the mail server did not take the mail
    'Die E-Mail konnte gerade nicht gesendet werden. Sie wird automatisch erneut versucht; bis dahin steht beim Eintrag „E-Mail nicht zugestellt“.' => 'The email could not be sent just now. It will be retried automatically; until then the entry says “Email not delivered”.',
    // Booking list (round.php): a mail to the parents' address could not be sent
    'E-Mail nicht zugestellt – neuer Versuch folgt' => 'Email not delivered – will be retried',
    'E-Mail nicht zugestellt' => 'Email not delivered',
    'Neuer Versuch folgt automatisch. Erhalten die Eltern sie nicht, informieren Sie sie bitte persönlich oder prüfen Sie die Adresse.' => 'It will be retried automatically. If the parents do not receive it, please inform them in person or check the address.',
    'Es folgt kein weiterer Versuch. Bitte informieren Sie die Eltern persönlich oder prüfen Sie die Adresse.' => 'It will not be retried. Please inform the parents in person or check the address.',
    // Overview for admins (dashboard.php): mails waiting in the queue for too long
    '{count} E-Mail(s) warten seit über {hours} Stunden auf den Versand (die älteste seit {since}). Erinnerungen, Zusammenfassungen und Bestätigungen, die nicht sofort zugestellt werden konnten, verschickt der Cron-Job: Bitte prüfen Sie, ob er stündlich läuft und ob der Mailversand funktioniert. Nach 48 Stunden werden sie verworfen.' => '{count} email(s) have been waiting to be sent for more than {hours} hours (the oldest since {since}). Reminders, summaries and confirmations that could not be delivered at once are sent by the cron job: please check that it runs every hour and that sending mail works. After 48 hours they are discarded.',
];
