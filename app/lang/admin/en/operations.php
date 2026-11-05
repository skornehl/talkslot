<?php
/** English texts of the admin area: German original => English (see App\AdminI18n). Operations: deleting a round, automatic deletion of expired rounds. */
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
];
