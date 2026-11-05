<?php
/** English texts of the admin area: German original => English (see App\AdminI18n). Operations: deleting a round, automatic deletion of expired rounds, monitoring of the cron job. */
return [
    // Deleting a round (round.php, AdminController::roundDelete)
    'Löschen …' => 'Delete …',
    'Runde löschen' => 'Delete round',
    'Löscht die Runde {bold}. Das kann nicht rückgängig gemacht werden.' => 'Deletes the round {bold}. This cannot be undone.',
    'mit allen Tagen, Pausen, Übersetzungen und Buchungen' => 'with all its days, breaks, translations and bookings',
    'Sollen nur keine neuen Buchungen mehr möglich sein? Dann die Runde stattdessen deaktivieren: Tage und Buchungen bleiben erhalten, Eltern können über ihren Link weiterhin absagen.' => 'Do you only want to stop new bookings? Then deactivate the round instead: days and bookings are kept, and parents can still cancel via their link.',
    'Stattdessen deaktivieren' => 'Deactivate instead',
    'In dieser Runde gibt es {count} Buchung(en). Bitte das Löschen ausdrücklich bestätigen.' => 'There are {count} booking(s) in this round. Please confirm the deletion explicitly.',

    // Automatic deletion of expired rounds (round.php, dashboard.php, App\Cleanup::deletionAt)
    'Abgelaufen: Die Runde wird am {date} automatisch gelöscht, mit allen Tagen und Einstellungen. Zum Behalten einen Tag ab heute hinzufügen oder unter „Bearbeiten“ das Feld „Runde löschen nach Ablauf“ leeren.' => 'Expired: the round will be deleted automatically on {date}, with all its days and settings. To keep it, add a day from today on, or clear “Delete round after it ends” under “Edit”.',
    'Runde wird am {date} gelöscht ({hours} h nach Ablauf)' => 'Round will be deleted on {date} ({hours} h after it ends)',
    'Noch nie aktiv: wird nicht automatisch gelöscht' => 'Never active yet: not deleted automatically',
    'wird am {date} gelöscht' => 'to be deleted on {date}',

    // Cron job late or never run (dashboard.php, App\CronMonitor)
    'Der Cronjob ist seit dem {time} Uhr nicht mehr vollständig gelaufen. Ohne ihn werden Buchungen vergangener Tage verspätet gelöscht und Zusammenfassungen und Erinnerungen verspätet oder gar nicht verschickt. Bitte prüfen Sie den Cronjob auf dem Server bzw. den GitHub-Workflow „Cron“: GitHub schaltet geplante Workflows in öffentlichen Repositorys nach 60 Tagen ohne Aktivität ab, unter „Actions“ lässt er sich wieder einschalten.' => 'The cron job has not completed a run since {time}. Without it, bookings of past days are deleted late and summaries and reminders are sent late or not at all. Please check the cron job on the server or the GitHub workflow “Cron”: GitHub disables scheduled workflows in public repositories after 60 days without activity; it can be enabled again under “Actions”.',
    'Es ist noch kein vollständiger Lauf des Cronjobs verzeichnet. Bitte richten Sie ihn ein (stündlich bin/cron.php oder der Web-Cron, z. B. über den GitHub-Workflow „Cron“), damit Buchungen pünktlich gelöscht und Zusammenfassungen und Erinnerungen rechtzeitig verschickt werden; ist er schon eingerichtet, zeigt „php bin/cron.php -v“, woran er scheitert. Wer bewusst ohne Cronjob arbeitet, schaltet diesen Hinweis mit cron_alert_hours = 0 in der Konfiguration ab.' => 'No completed run of the cron job has been recorded yet. Please set it up (bin/cron.php every hour, or the web cron, e.g. through the GitHub workflow “Cron”) so that bookings are deleted and summaries and reminders are sent on time; if it is set up already, “php bin/cron.php -v” shows where it fails. If you run TalkSlot without a cron job on purpose, set cron_alert_hours = 0 in the configuration to turn this notice off.',
    'Zur Anleitung' => 'Open the guide',
];
