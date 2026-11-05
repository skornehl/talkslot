<?php
/** English texts of the admin area: German original => English (see App\AdminI18n). Usability: ways on for parents, guidance for admins. */
return [
    // "Bereit für den Start?" (expert review P2-13)
    'Bereit für den Start?' => 'Ready to start?',
    'Erledigt' => 'Done',
    'Bitte prüfen' => 'Please check',
    'Noch offen' => 'Still to do',
    '{done} von {total} Punkten erledigt.' => '{done} of {total} items done.',
    'Alle Punkte mit Anleitung ansehen' => 'See all items with instructions',
    'Alles bereit: Legen Sie Ihre erste Runde an und aktivieren Sie sie.' => 'All set: create your first round and activate it.',
    'Einrichtung prüfen: Bereit für den Start?' => 'Check the setup: Ready to start?',
    'Bereit für den Start? Alle Punkte der Einrichtung auf einen Blick' => 'Ready to start? All setup items at a glance',
    'Hier sehen Sie, ob alles für die Terminvergabe bereit ist: {done} von {total} Punkten erledigt.'
        => 'Here you see whether everything is ready for booking: {done} of {total} items done.',
    'Ihre Terminvergabe ist in Betrieb; Warnungen erscheinen außerdem oben unter „Alle Runden“.'
        => 'Your booking site is in use; warnings also appear at the top of “All rounds”.',
    'Bis Sie die erste Runde aktivieren, steht diese Liste auch oben unter „Alle Runden“.'
        => 'Until you activate the first round, this list also appears at the top of “All rounds”.',
    'Impressum und Datenschutzerklärung' => 'Imprint and privacy policy',
    'Hier fehlt noch etwas (siehe unten). Bis dahin lassen sich keine Runden aktivieren.' => 'Something is still missing here (see below). Until then, no round can be activated.',
    'Runden lassen sich aktivieren. Bitte prüfen Sie trotzdem diese Hinweise:' => 'Rounds can be activated, but please check these notes:',
    'Angaben des Betreibers, Betriebsart und beide Texte sind vollständig.' => 'The operator details, the operator type and both texts are complete.',
    'Datenschutzbeauftragte der Schule' => 'The school’s data protection officer',
    'In der Datenschutzerklärung ({languages}) fehlen noch die Kontaktdaten der bzw. des Datenschutzbeauftragten der Schule. Die Schule oder der Schulträger nennt sie Ihnen.'
        => 'The privacy policy ({languages}) still lacks the contact details of the school’s data protection officer. The school or the school authority can give them to you.',
    'In der Datenschutzerklärung eingetragen.' => 'Entered in the privacy policy.',
    'Datenschutzerklärung bearbeiten' => 'Edit the privacy policy',
    'Versand über {transport} von {from}.' => 'Sent via {transport} from {from}.',
    '{count} E-Mail(s) warten seit über {hours} Stunden auf den Versand. Bitte prüfen Sie den Mailversand und den Cronjob (Aufgabe der technischen Betreuung).'
        => '{count} email(s) have been waiting for more than {hours} hours to be sent. Please check the mail delivery and the cron job (a task for whoever runs the server).',
    'In der Konfiguration fehlt die Absenderadresse (mail.from_address, Aufgabe der technischen Betreuung). Ohne sie bekommen Eltern keine Bestätigung.'
        => 'The configuration lacks the sender address (mail.from_address, a task for whoever runs the server). Without it, parents get no confirmation.',
    'E-Mails kommen an: Eine eingeladene Person hat sich angemeldet.' => 'Emails arrive: an invited person has signed in.',
    'Ob E-Mails ankommen, zeigt ein Versuch: Laden Sie unter „Benutzer“ eine Lehrkraft ein. Kommt die Einladung an (auch im Spam-Ordner nachsehen) und meldet sie sich an, klappt der Versand.'
        => 'Only a test shows whether emails arrive: invite a teacher under “Users”. If the invitation arrives (check the spam folder too) and they sign in, mail delivery works.',
    'Cronjob' => 'Cron job',
    'Seit dem {time} Uhr nicht mehr vollständig gelaufen (Aufgabe der technischen Betreuung). Ohne ihn gehen Erinnerungen und Zusammenfassungen verspätet oder gar nicht hinaus.'
        => 'It has not completed a run since {time} (a task for whoever runs the server). Without it, reminders and summaries go out late or not at all.',
    'Zuletzt vollständig gelaufen am {time} Uhr.' => 'Last completed run on {time}.',
    'Zuletzt vollständig gelaufen am {time} Uhr, die Überwachung ist abgeschaltet (cron_alert_hours = 0). Bitte prüfen, ob er noch läuft.'
        => 'Last completed run on {time}; the monitoring is switched off (cron_alert_hours = 0). Please check whether it still runs.',
    'Noch kein Lauf verzeichnet, und die Überwachung ist abgeschaltet (cron_alert_hours = 0). Ohne Cronjob gehen Erinnerungen und Zusammenfassungen nur hinaus, wenn jemand die Seite aufruft.'
        => 'No run recorded yet, and the monitoring is switched off (cron_alert_hours = 0). Without a cron job, reminders and summaries only go out when somebody opens the site.',
    'Noch kein vollständiger Lauf verzeichnet. Bitte richten Sie ihn ein (stündlich bin/cron.php oder der Web-Cron, Aufgabe der technischen Betreuung), damit Erinnerungen und Zusammenfassungen pünktlich hinausgehen.'
        => 'No completed run recorded yet. Please set it up (bin/cron.php every hour, or the web cron; a task for whoever runs the server), so that reminders and summaries go out on time.',
    'Läuft er über den GitHub-Workflow „Cron“: GitHub schaltet geplante Workflows in öffentlichen Repositorys nach 60 Tagen ohne Aktivität ab, unter „Actions“ lässt er sich wieder einschalten.'
        => 'If it runs as the GitHub workflow “Cron”: GitHub disables scheduled workflows in public repositories after 60 days without activity; it can be enabled again under “Actions”.',
    '„php bin/cron.php -v“ auf dem Server zeigt, woran er scheitert.' => '“php bin/cron.php -v” on the server shows why it fails.',
    'Server und Konfiguration' => 'Server and configuration',
    'Diese Einstellungen in config/config.php wirken nicht wie geschrieben (Aufgabe der technischen Betreuung):'
        => 'These settings in config/config.php do not work as written (a task for whoever runs the server):',
    'PHP {php}, Datenbank {database}; die Konfiguration ist in Ordnung.' => 'PHP {php}, database {database}; the configuration is fine.',
    'Rechte der Konfigurationsdatei' => 'Configuration file permissions',
    'Für alle Benutzer des Servers lesbar: {files}. Sie enthält Passwörter und den geheimen Schlüssel. Bitte setzen Sie die Rechte auf 600 (oder 640), z. B. im FTP-Programm, oder geben Sie das an die technische Betreuung weiter.'
        => 'Readable by every user on the server: {files}. It contains passwords and the secret key. Please set the permissions to 600 (or 640), e.g. in your FTP program, or pass this on to whoever runs the server.',
    'Nur für den Webserver lesbar.' => 'Readable by the web server only.',
    // "Was ist neu" after updates (expert review P2-13)
    'Neu seit dem letzten Update' => 'New since the last update',
    'Ändert ein Update die Vorlage für Impressum oder Datenschutzerklärung, steht unter „Texte“ beim Text „Vorlage geändert“. Beim Bearbeiten sehen Sie die geänderten Abschnitte und können sie mit „Abschnitte aus der Vorlage übernehmen“ einfügen. Ihre eigenen Änderungen werden nie automatisch überschrieben.'
        => 'When an update changes the template for the imprint or the privacy policy, “Texts” shows “template changed” next to the text. When editing it, you see the changed sections and can insert them with “Take over the sections from the template”. Your own changes are never overwritten automatically.',
    'Unter „Mein Profil“ können Sie eine eigene Antwortadresse für Eltern angeben, z. B. Ihre dienstliche Adresse. Antworten der Eltern auf E-Mails zu ihrem Termin gehen dann dorthin statt an Ihre E-Mail-Adresse.'
        => 'Under “My profile” you can set your own reply-to address for parents, e.g. your work address. Parents’ replies to the emails about their appointment then go there instead of to your email address.',
    '„Bereit für den Start?“ zeigt auf einer Seite, ob Impressum und Datenschutzerklärung, E-Mail-Versand, Cronjob und Server bereit sind.'
        => '“Ready to start?” shows on one page whether the imprint and privacy policy, mail delivery, cron job and server are ready.',
];
