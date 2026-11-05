<?php
/** English texts of the admin area: German original => English (see App\AdminI18n). Usability: ways on for parents, guidance for admins. */
return [
    // "Bereit für den Start?" (expert review P2-13)
    'Bereit für den Start?' => 'Ready to start?',
    'Erledigt' => 'Done',
    'Bitte prüfen' => 'Please check',
    'Noch offen' => 'Still to do',
    '{done} von {total} Punkten erledigt.' => '{done} of {total} points done.',
    'Zur Übersicht „Bereit für den Start?“' => 'To the overview “Ready to start?”',
    'Bereit für den Start? Alle Punkte der Einrichtung auf einen Blick' => 'Ready to start? All points of the setup at a glance',
    'Diese Übersicht zeigt, ob alles für die Terminvergabe bereit ist: {done} von {total} Punkten erledigt.'
        => 'This overview shows whether everything is ready for booking: {done} of {total} points done.',
    'Ihre Terminvergabe ist bereits in Betrieb; Warnungen erscheinen weiterhin auch auf der Übersicht.'
        => 'Your booking site is already in use; warnings still appear on the overview as well.',
    'Bis die erste Runde aktiv war, fasst die Übersicht der Runden diese Punkte zusammen.'
        => 'Until the first round has been active, the overview of the rounds sums up these points.',
    'Impressum und Datenschutzerklärung' => 'Imprint and privacy policy',
    'Bis das erledigt ist, lassen sich keine Runden aktivieren.' => 'Until this is done, no round can be activated.',
    'Runden lassen sich aktivieren. Bitte prüfen Sie trotzdem diese Hinweise:' => 'Rounds can be activated. Please check these notes all the same:',
    'Angaben des Betreibers, Betriebsart und beide Texte sind vollständig.' => 'The operator’s details, the operator type and both texts are complete.',
    'Datenschutzbeauftragte der Schule' => 'The school’s data protection officer',
    'In der Datenschutzerklärung ({languages}) fehlen noch die Kontaktdaten der bzw. des Datenschutzbeauftragten der Schule. Die Schule oder der Schulträger nennt sie Ihnen.'
        => 'The privacy policy ({languages}) still lacks the contact details of the school’s data protection officer. The school or the school authority can give them to you.',
    'In der Datenschutzerklärung eingetragen.' => 'Entered in the privacy policy.',
    'Datenschutzerklärung bearbeiten' => 'Edit the privacy policy',
    '{count} E-Mail(s) warten seit über {hours} Stunden auf den Versand. Bitte prüfen Sie den Mailversand und den Cronjob.'
        => '{count} e-mail(s) have been waiting for more than {hours} hours to be sent. Please check the mail delivery and the cron job.',
    'In der Konfiguration fehlt die Absenderadresse (mail.from_address). Ohne sie bekommen Eltern keine Bestätigung.'
        => 'The configuration lacks the sender address (mail.from_address). Without it, parents get no confirmation.',
    'Versand über {transport} von {from}. Ob die E-Mails ankommen, zeigt nur ein Versuch: Buchen Sie einmal selbst einen Termin mit Ihrer eigenen Adresse und prüfen Sie auch den Spam-Ordner.'
        => 'Sent via {transport} from {from}. Only a try shows whether the e-mails arrive: book an appointment yourself once with your own address, and check the spam folder too.',
    'Cronjob' => 'Cron job',
    'Seit dem {time} Uhr nicht mehr vollständig gelaufen. Ohne ihn gehen Erinnerungen und Zusammenfassungen verspätet oder gar nicht hinaus.'
        => 'Not completed since {time}. Without it, reminders and summaries go out late or not at all.',
    'Zuletzt vollständig gelaufen am {time} Uhr.' => 'Last completed on {time}.',
    'Noch kein Lauf verzeichnet, und die Überwachung ist abgeschaltet (cron_alert_hours = 0). Ohne Cronjob gehen Erinnerungen und Zusammenfassungen nur hinaus, wenn jemand die Seite aufruft.'
        => 'No run recorded yet, and the monitoring is switched off (cron_alert_hours = 0). Without a cron job, reminders and summaries only go out when somebody opens the site.',
    'Noch kein vollständiger Lauf verzeichnet. Bitte richten Sie ihn ein (stündlich bin/cron.php oder der Web-Cron), damit Erinnerungen und Zusammenfassungen pünktlich hinausgehen.'
        => 'No completed run recorded yet. Please set it up (bin/cron.php every hour, or the web cron), so that reminders and summaries go out on time.',
    'Server und Konfiguration' => 'Server and configuration',
    'Diese Einstellungen in config/config.php wirken nicht wie geschrieben (Aufgabe der technischen Betreuung):'
        => 'These settings in config/config.php do not work as written (a task for whoever runs the server):',
    'PHP {php}, Datenbank {database}; die Konfiguration ist in Ordnung.' => 'PHP {php}, database {database}; the configuration is fine.',
    'Rechte der Konfigurationsdatei' => 'Permissions of the configuration file',
    'Für alle Benutzer des Servers lesbar: {files}. Sie enthält Passwörter und den geheimen Schlüssel. Bitte setzen Sie die Rechte auf 600 (oder 640), z. B. im FTP-Programm, oder geben Sie das an die technische Betreuung weiter.'
        => 'Readable for every user of the server: {files}. It contains passwords and the secret key. Please set the permissions to 600 (or 640), e.g. in your FTP program, or pass this on to whoever runs the server.',
    'Nur für den Webserver lesbar.' => 'Readable for the web server only.',
    // "Was ist neu" after updates (expert review P2-13)
    'Was ist neu' => 'What’s new',
    'Impressum und Datenschutzerklärung: Ändert ein Update eine Vorlage, zeigt „Texte“ beim jeweiligen Text, welche Abschnitte sich geändert haben, und Sie können sie übernehmen. Bearbeitete Texte werden weiterhin nie automatisch ersetzt.'
        => 'Imprint and privacy policy: when an update changes a template, “Texts” shows on each text which sections have changed, and you can take them over. Edited texts are still never replaced automatically.',
    'Unter „Mein Profil“ können Sie eine eigene Antwortadresse für Eltern angeben, z. B. Ihre dienstliche Adresse. Antworten der Eltern auf E-Mails zu ihrem Termin gehen dann dorthin statt an Ihre E-Mail-Adresse.'
        => 'Under “My profile” you can give a reply address of your own for parents, e.g. your work address. Parents’ replies to the e-mails about their appointment then go there instead of to your e-mail address.',
    '„Bereit für den Start?“ zeigt auf einer Seite, ob Rechtstexte, E-Mail-Versand, Cronjob und Server bereit sind; Sie finden die Seite unter „Texte“.'
        => '“Ready to start?” shows on one page whether legal texts, mail delivery, cron job and server are ready; you find the page under “Texts”.',
];
