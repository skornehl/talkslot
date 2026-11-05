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
    'Betroffen:' => 'Concerned:',
    'Die Eltern erfahren davon, wenn Sie den Termin umbuchen (mit „Änderung per E-Mail mitteilen“) oder löschen (mit „Eltern per E-Mail informieren“); ohne E-Mail-Adresse bitte persönlich informieren.' => 'The parents learn about it when you move the appointment (with “Notify of the change by email”) or delete it (with “Inform parents by email”); without an email address, please inform them in person.',
    // Admin log
    'Eltern per E-Mail informiert' => 'parents informed by email',
    'Eltern von {count} Buchung(en) per E-Mail informiert' => 'parents of {count} booking(s) informed by email',
    // Summaries for teachers without names unless the school allows them (Summaries, expert review P1-4)
    '1 Termin um {from}' => '1 appointment at {from}',
    '{count} Termine zwischen {from} und {to}' => '{count} appointments between {from} and {to}',
    'Auffällig: {notes}' => 'Worth a look: {notes}',
    '{count}× gleicher Name' => '{count}× the same name',
    '{count}× gleiche E-Mail-Adresse' => '{count}× the same email address',
    '{count}× ohne E-Mail-Adresse' => '{count}× without an email address',
    '{count}× E-Mail nicht zugestellt' => '{count}× email not delivered',
    '{count}× passt nicht mehr zum Zeitplan' => '{count}× no longer fits the schedule',
    'Diese Zusammenfassung enthält Namen. Bitte löschen Sie sie, sobald Sie sie nicht mehr brauchen, spätestens zum Ende des Schuljahres. Abbestellen können Sie sie im Profil.' => 'This summary contains names. Please delete it as soon as you no longer need it, at the latest at the end of the school year. You can unsubscribe from it in your profile.',
    'Diese Zusammenfassung enthält keine Namen: Die Terminliste sehen Sie nach der Anmeldung über den Link. Abbestellen können Sie sie im Profil.' => 'This summary contains no names: you will find the list of appointments behind the link after signing in. You can unsubscribe from it in your profile.',
    // Dashboard: notice for accounts from before the summaries without names (dashboard.php)
    'Neu: Die Kalenderdatei Ihrer Zusammenfassungen enthält keine Namen mehr. Ob die E-Mail selbst die Namen enthält, können Sie jetzt im Profil wählen; bis dahin bleibt es wie bisher.' => 'New: the calendar file of your summaries no longer contains names. Whether the email itself contains the names you can now choose in your profile; until then it stays as before.',
    'Neu: Ihre Zusammenfassungen per E-Mail enthalten keine Namen und keine Kalenderdatei mehr, sondern je Tag die Zahl der Termine, Auffälliges und den Link zur Terminliste. So liegen keine Namenslisten in Postfächern und Kalendern, für die die Löschregeln der Schule nicht gelten.' => 'New: your summaries by email no longer contain names or a calendar file, but for each day the number of appointments, anything worth a look and the link to the list of appointments. This way no lists of names end up in mailboxes and calendars that the school\'s deletion rules do not reach.',
    'Namen kann die Schule mit „summary_names“ in config/config.php freigeben, wenn alle Konten dienstliche Adressen verwenden.' => 'The school can allow names with “summary_names” in config/config.php if all accounts use work addresses.',
    'Namen kann nur die Schule freigeben.' => 'Only the school can allow names.',
    'Zum Profil' => 'To the profile',
    'Verstanden' => 'Got it',
    // Profile (profile.php): summaries, reply address for parents, allowed domains
    'Am Abend vor einem Termintag die Termine des nächsten Tages.' => 'On the evening before a day with appointments, the appointments of the next day.',
    'Die E-Mails nennen je Tag die Zahl der Termine, Auffälliges (z. B. denselben Namen mehrmals) und den Link zur Terminliste, aber nie E-Mail-Adressen der Eltern.' => 'For each day the emails give the number of appointments, anything worth a look (e.g. the same name more than once) and the link to the list of appointments, but never parents\' email addresses.',
    'Mit Namen:' => 'With names:',
    'zusätzlich Uhrzeit und Name jedes Termins und eine Kalenderdatei der Termine (ohne Namen). Ihre Schule hat das freigegeben. Bitte löschen Sie diese E-Mails, sobald Sie sie nicht mehr brauchen, spätestens zum Ende des Schuljahres.' => 'in addition the time and name of each appointment and a calendar file of the appointments (without names). Your school has allowed this. Please delete these emails as soon as you no longer need them, at the latest at the end of the school year.',
    'Für Ihre Zusammenfassungen und Links zum Zurücksetzen des Passworts. Eltern sehen sie nicht; ihre Antworten auf E-Mails zu ihrem Termin gehen an diese Adresse, wenn Sie keine Antwortadresse angeben.' => 'For your summaries and links to reset your password. Parents do not see it; their replies to emails about their appointment go to this address unless you enter a reply address.',
    'Die Schule erlaubt nur Adressen bei {domains}.' => 'The school only allows addresses at {domains}.',
    'Antwortadresse für Eltern' => 'Reply address for parents',
    '(optional)' => '(optional)',
    'Antworten der Eltern auf E-Mails zu ihrem Termin gehen an diese Adresse statt an Ihre E-Mail-Adresse, z. B. an Ihre dienstliche Adresse. Eltern sehen sie, sobald sie antworten.' => 'Parents\' replies to emails about their appointment go to this address instead of your email address, e.g. to your work address. Parents see it as soon as they reply.',
    '(nur nötig, wenn Sie eine der beiden Adressen ändern)' => '(only needed if you change one of the two addresses)',
    'Die Antwortadresse für Eltern ist ungültig.' => 'The reply address for parents is invalid.',
    'Antwortadresse für Eltern:' => 'Reply address for parents:',
    'Zum Ändern der Antwortadresse für Eltern bitte Ihr aktuelles Passwort eingeben.' => 'To change the reply address for parents, please enter your current password.',
    'Ihre E-Mail-Adresse ist keine dienstliche Adresse bei {domains}, wie die Schule es vorgibt – bitte ändern. Bis dahin erhalten Sie keine Zusammenfassungen.' => 'Your email address is not a work address at {domains}, as the school requires – please change it. Until then you will not receive summaries.',
    'Bitte eine Adresse bei {domains} angeben: Die Schule erlaubt für Konten nur ihre dienstlichen Adressen.' => 'Please enter an address at {domains}: the school only allows its work addresses for accounts.',
    'Aktive Konten mit einer Adresse außerhalb von „allowed_account_domains“: {count}. Sie erhalten keine Zusammenfassungen, und Antworten der Eltern gehen nicht an sie. Bitte ändern Sie die Adressen.' => 'Active accounts with an address outside “allowed_account_domains”: {count}. They receive no summaries, and parents\' replies do not go to them. Please change the addresses.',
    'eine Liste von Domains, z. B. [\'schule.example.org\']' => 'a list of domains, e.g. [\'schule.example.org\']',
    // Link from another site (e.g. a webmailer) to a page of the admin area: interim page (AdminController::signInFirst())
    'Weiterleitung' => 'Redirecting',
    'Die Seite öffnet sich gleich. Falls nicht, wählen Sie „Weiter“.' => 'The page will open in a moment. If it does not, choose “Continue”.',
    'Weiter' => 'Continue',
];
