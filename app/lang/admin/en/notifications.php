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
    '{count} E-Mail(s) warten seit über {hours} Stunden auf den Versand (die älteste seit {since}). Erinnerungen, Zusammenfassungen und Bestätigungen, die nicht sofort zugestellt werden konnten, verschickt der Cron-Job: Bitte prüfen Sie, ob er regelmäßig läuft und ob der Mailversand funktioniert. Nach 48 Stunden werden sie verworfen.' => '{count} email(s) have been waiting to be sent for more than {hours} hours (the oldest since {since}). Reminders, summaries and confirmations that could not be delivered at once are sent by the cron job: please check that it runs regularly and that sending mail works. After 48 hours they are discarded.',
    // Changes by the teacher that concern booked parents (ScheduleService): the box in the delete dialogs and forms
    'Eltern per E-Mail informieren' => 'Inform parents by email',
    // Delete dialogs (round.php, user_form.php), with the box ticked
    'Die Eltern erhalten eine Absage per E-Mail.' => 'The parents will receive a cancellation by email.',
    'Die Eltern von {count} Buchung(en) erhalten eine Absage per E-Mail.' => 'The parents of {count} booking(s) will receive a cancellation by email.',
    '{count} Buchung(en) ohne E-Mail an die Eltern – bitte persönlich informieren; die Namen stehen danach in der Meldung.' => '{count} booking(s) without an email to the parents – please inform them in person; their names will be in the message afterwards.',
    'Die Eltern haben zu diesem Eintrag nie eine E-Mail erhalten und werden deshalb nicht benachrichtigt – bitte persönlich informieren.' => 'The parents never received an email about this entry and are therefore not notified – please inform them in person.',
    // Forms: edit day (day_form.php) and round (round_form.php)
    'Die Eltern erfahren davon, wenn Sie ihn umbuchen oder löschen.' => 'The parents learn about it when you move or delete it.',
    'An diesem Tag sind {count} Termin(e) gebucht. Ändern sich Datum, Uhrzeit oder Ort eines Termins, erhalten die Eltern die neuen Daten per E-Mail, solange „Eltern per E-Mail informieren“ angehakt ist.' => '{count} appointment(s) booked on this day. If the date, time or location of an appointment changes, the parents receive the new details by email as long as “Inform parents by email” is ticked.',
    '{count} Buchung(en) ohne E-Mail an die Eltern – bitte persönlich informieren.' => '{count} booking(s) without an email to the parents – please inform them in person.',
    'Ändern sich Ort oder Lehrkraft der Runde, erhalten die Eltern gebuchter Termine die neuen Daten per E-Mail in ihrer Sprache.' => 'If the location or teacher of the round changes, the parents of booked appointments receive the new details by email in their language.',
    // Messages after the change (AdminController)
    'Absage per E-Mail an die Eltern gesendet.' => 'Cancellation sent to the parents by email.',
    'Die Absage per E-Mail konnte gerade nicht gesendet werden. Sie wird automatisch erneut versucht.' => 'The cancellation email could not be sent just now. It will be retried automatically.',
    'Die Eltern („{name}“, {email}) haben zu diesem Eintrag nie eine E-Mail erhalten und wurden deshalb nicht benachrichtigt – bitte persönlich informieren.' => 'The parents (“{name}”, {email}) never received an email about this entry and were therefore not notified – please inform them in person.',
    'Die Eltern von {count} Buchung(en) erhalten die neuen Termindaten per E-Mail in ihrer Sprache.' => 'The parents of {count} booking(s) will receive the new appointment details by email in their language.',
    'Die Eltern von {count} Buchung(en) erhalten eine Absage per E-Mail in ihrer Sprache.' => 'The parents of {count} booking(s) will receive a cancellation by email in their language.',
    'Keine E-Mail an die Eltern von {count} Buchung(en) (keine Adresse hinterlegt oder Eintrag nie per E-Mail bestätigt) – bitte persönlich informieren:' => 'No email to the parents of {count} booking(s) (no address stored, or an entry never confirmed by email) – please inform them in person:',
    'Betroffen:' => 'Affected:',
    'Die Eltern erfahren davon, wenn Sie den Termin umbuchen (mit „Änderung per E-Mail mitteilen“) oder löschen (mit „Eltern per E-Mail informieren“); ohne E-Mail-Adresse bitte persönlich informieren.' => 'The parents learn about it when you move the appointment (with “Notify of the change by email”) or delete it (with “Inform parents by email”); without an email address, please inform them in person.',
    // Limit of change notices per account and hour (ScheduleService::BULK_LIMIT)
    'Keine E-Mail an die Eltern von {count} Buchung(en): Ihr Konto hat die Höchstzahl von Änderungsmitteilungen je Stunde erreicht. Bitte persönlich informieren oder später den Eintrag mit „Änderung per E-Mail mitteilen“ speichern:' => 'No email to the parents of {count} booking(s): your account has reached the maximum number of change notices per hour. Please inform them in person or save their entry later with “Notify of the change by email”:',
    // Conflicts that got a new date, location or teacher (day: after the conflict warning; round: on its own)
    'Bei {count} davon haben sich zugleich Datum, Ort oder Lehrkraft geändert; die Eltern kennen die neuen Daten noch nicht.' => 'For {count} of them, the date, location or teacher changed at the same time; the parents do not know the new details yet.',
    'Achtung: {count} gebuchte(r) Termin(e) sind als Konflikt markiert und haben jetzt einen anderen Ort oder eine andere Lehrkraft; die Eltern kennen die neuen Daten noch nicht.' => 'Attention: {count} booked appointment(s) are marked as a conflict and now have a different location or teacher; the parents do not know the new details yet.',
    // Entry (booking_form.php, AdminController::bookingUpdate()): a new address counts as unknown until a mail from the entry reached it
    'An eine neue Adresse gehen Änderungen, Absagen und Erinnerungen erst nach dieser E-Mail.' => 'A new address receives changes, cancellations and reminders only after this email.',
    'Neue E-Mail-Adresse ohne E-Mail gespeichert: Die Eltern erhalten dort Änderungen, Absagen und Erinnerungen erst, wenn Sie den Eintrag mit „Änderung per E-Mail mitteilen“ speichern.' => 'New email address saved without an email: the parents receive changes, cancellations and reminders there only once you save the entry with “Notify of the change by email”.',
    // Admin log
    'Eltern per E-Mail informiert' => 'parents informed by email',
    'Eltern von {count} Buchung(en) per E-Mail informiert' => 'parents of {count} booking(s) informed by email',
];
