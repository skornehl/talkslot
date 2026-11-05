<?php
/** English texts of the admin area: German original => English (see App\AdminI18n). Operations: deleting a round, automatic deletion of expired rounds, checks of the server and the configuration. */
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

    // Checks of the server and the configuration (dashboard.php, App\ConfigSchema, App\Health, App\Installer)
    'Die Einrichtung des Servers braucht Aufmerksamkeit (Aufgabe der technischen Betreuung, die Einstellungen stehen in config/config.php):' => 'The server setup needs attention (a task for whoever runs the server; the settings are in config/config.php):',
    'Der Datenbankserver wird nicht unterstützt: {server}. TalkSlot braucht MariaDB {min} oder neuer; mit MySQL oder einer älteren MariaDB schlagen die Updates der Datenbank fehl. Bitte wählen Sie beim Webhoster eine aktuelle MariaDB-Datenbank.' => 'The database server is not supported: {server}. TalkSlot needs MariaDB {min} or newer; with MySQL or an older MariaDB, the updates of the database fail. Please choose a current MariaDB database at your web host.',
    'unbekannt' => 'unknown',
    'Das Verzeichnis {dir} fehlt oder ist für PHP nicht beschreibbar. Dort liegen Sitzungen, Sperrdatei, Fehlerprotokoll und der Zähler für Fehlermeldungen. Bitte legen Sie es an bzw. erlauben Sie PHP das Schreiben (z. B. Rechte 750 für den PHP-Benutzer) und versuchen Sie es erneut.' => 'The directory {dir} is missing or PHP cannot write to it. It holds the sessions, the lock file, the error log and the counter of error alerts. Please create it or let PHP write to it (e.g. permissions 750 for the PHP user) and try again.',
    '„{key}“ ist keine Einstellung von TalkSlot und wirkt nicht.' => '"{key}" is not a TalkSlot setting and has no effect.',
    'Gemeint ist vermutlich „{suggestion}“.' => 'Did you mean "{suggestion}"?',
    '„{key}“ hat keinen gültigen Wert, erwartet wird {expected}.' => '"{key}" has an invalid value; expected is {expected}.',
    'eine Liste von Einstellungen in eckigen Klammern wie in config.sample.php' => 'a list of settings in square brackets as in config.sample.php',
    'eine Adresse wie https://termine.example.org' => 'an address like https://termine.example.org',
    'eine E-Mail-Adresse oder \'\'' => 'an e-mail address or \'\'',
    'true oder false (ohne Anführungszeichen)' => 'true or false (without quotes)',
    'eine ganze Zahl ab {min}' => 'a whole number from {min}',
    'eine ganze Zahl von {min} bis {max}' => 'a whole number from {min} to {max}',
    'einer dieser Werte: {values}' => 'one of these values: {values}',
    '[Anzahl, Sekunden] mit ganzen Zahlen ab 1' => '[count, seconds], whole numbers from 1',
    'Texte je Sprache, z. B. [\'de\' => \'…\']' => 'texts per language, e.g. [\'de\' => \'…\']',
    'ein Text in Anführungszeichen' => 'a text in quotes',
    '„default_language“ ist {value}, diese Sprache steht aber nicht unter „languages“. Stattdessen gilt {fallback}.' => '"default_language" is {value}, but that language is not listed under "languages". {fallback} is used instead.',
    '„mail.from_address“ fehlt: Ohne Absenderadresse verschickt TalkSlot keine E-Mails.' => '"mail.from_address" is missing: without a sender address TalkSlot sends no e-mails.',
    '„mail.transport“ ist „mail“, aber die PHP-Funktion mail() ist auf diesem Server abgeschaltet: So gehen keine E-Mails hinaus. Bitte stellen Sie auf „smtp“ um.' => '"mail.transport" is "mail", but the PHP function mail() is disabled on this server, so no e-mails go out. Please switch to "smtp".',
];
