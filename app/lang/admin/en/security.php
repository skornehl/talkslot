<?php
/** English texts of the admin area: German original => English (see App\AdminI18n). Account security: confirming the password, security notices, ending all sessions, configuration warnings, form posts from other websites. */
return [
    // Confirming the password (Auth::confirmPassword) and the account forms
    'Zu viele falsche Passwörter. Bitte versuchen Sie es in {minutes} Minuten erneut oder melden Sie sich ab und wieder an.' => 'Too many wrong passwords. Please try again in {minutes} minutes or sign out and in again.',
    'Zur Bestätigung bitte Ihr Passwort eingeben (nötig bei Admin-Rechten und E-Mail-Adressen).' => 'Please enter your password to confirm (needed for admin rights and email addresses).',
    'Zum Löschen eines Admin-Kontos bitte Ihr Passwort eingeben.' => 'Please enter your password to delete an admin account.',
    'Ihr Passwort (zur Bestätigung)' => 'Your password (to confirm)',
    'Nur nötig, wenn Sie Admin-Rechte vergeben oder entziehen, ein Admin-Konto deaktivieren oder wieder aktivieren oder die E-Mail-Adresse ändern. Dann geht eine E-Mail hinaus: bei Admin-Rechten an alle Admins und die betroffene Person, bei einer neuen E-Mail-Adresse an die bisherige.' => 'Only needed when you grant or remove admin rights, deactivate or reactivate an admin account or change the email address. An email then goes out: for admin rights to all admins and the person concerned, for a new email address to the previous one.',
    'Beim Speichern erhält die Person eine Einladung per E-Mail mit einem Link, über den sie ihr Passwort selbst festlegt (einmalig nutzbar, {hours} Stunden gültig). Danach kann sie ihre Terminrunden selbst verwalten; Sie können Runden auch für sie anlegen.' => 'When you save, the person receives an invitation by email with a link for setting their password themselves (single use, valid for {hours} hours). After that they can manage their rounds themselves; you can also create rounds for them.',
    '{what} konnte nicht an {email} gesendet werden. Bitte prüfen Sie den Mailversand (unter „Texte“) und senden Sie den Link danach über „Bearbeiten“ erneut.' => '{what} could not be sent to {email}. Please check the email delivery (under “Texts”) and then send the link again via “Edit”.',
    // Users list and overview: sign-ins only in coarse steps
    'in den letzten 12 Monaten' => 'within the last 12 months',
    'vor über 12 Monaten' => 'more than 12 months ago',
    'Konten, die seit über 12 Monaten nicht genutzt wurden: {count}. Bitte prüfen Sie, ob sie noch gebraucht werden, und deaktivieren Sie die Konten ausgeschiedener Lehrkräfte.' => 'Accounts not used for more than 12 months: {count}. Please check whether they are still needed and deactivate the accounts of teachers who have left.',
    'Zu den Benutzern' => 'Go to the users',
    // Ending all sessions
    'Alle Sitzungen beenden' => 'End all sessions',
    'Meldet alle Konten auf allen Geräten ab, nur diese Sitzung bleibt bestehen. Für den Notfall, z. B. wenn ein Gerät verloren gegangen ist oder Sie einen Missbrauch vermuten. Die Passwörter bleiben unverändert; alle Admins erhalten eine E-Mail.' => 'Signs out all accounts on all devices; only this session stays. For emergencies, e.g. when a device has been lost or you suspect misuse. The passwords stay the same; all admins receive an email.',
    'Alle Konten auf allen Geräten abmelden?' => 'Sign out all accounts on all devices?',
    'Zum Beenden aller Sitzungen bitte Ihr Passwort eingeben.' => 'Please enter your password to end all sessions.',
    'Alle Sitzungen beendet' => 'All sessions ended',
    'Alle Sitzungen beendet: Alle Konten müssen sich auf allen Geräten neu anmelden, nur diese Sitzung bleibt bestehen.' => 'All sessions ended: all accounts have to sign in again on every device; only this session stays.',
    // Security notices by email (App\AccountSecurity, AccountLinks)
    'Ihr Passwort wurde geändert' => 'Your password has been changed',
    'Ihr Passwort wurde festgelegt' => 'Your password has been set',
    'für Ihr Konto „{username}“ bei {app} ({url}) wurde soeben das Passwort festgelegt.' => 'the password for your account “{username}” at {app} ({url}) has just been set.',
    'für Ihr Konto „{username}“ bei {app} ({url}) wurde soeben ein neues Passwort festgelegt. Andere Anmeldungen des Kontos wurden dabei beendet.' => 'a new password has just been set for your account “{username}” at {app} ({url}). Other sessions of the account have been ended.',
    'Waren Sie das nicht, legen Sie bitte über „Passwort vergessen?“ auf der Anmeldeseite ein neues Passwort fest und wenden Sie sich umgehend an einen Admin.' => 'If this was not you, please set a new password via “Forgot your password?” on the sign-in page and contact an admin immediately.',
    'Ihr Benutzername wurde geändert' => 'Your username has been changed',
    'für Ihr Konto bei {app} ({url}) wurde Folgendes geändert:' => 'the following has been changed for your account at {app} ({url}):',
    'für Ihr Konto bei {app} ({url}) hat {actor} (Admin) Folgendes geändert:' => '{actor} (admin) has changed the following for your account at {app} ({url}):',
    '– E-Mail-Adresse jetzt: {email}' => '– email address now: {email}',
    '– Benutzername jetzt: {username}' => '– username now: {username}',
    'Links zum Festlegen eines neuen Passworts gehen ab jetzt an die neue Adresse.' => 'Links for setting a new password now go to the new address.',
    'War das nicht abgesprochen, wenden Sie sich bitte umgehend an einen anderen Admin.' => 'If this was not agreed with you, please contact another admin immediately.',
    'für Ihr Konto bei {app} hat {admin} (Admin) das Passwort zurückgesetzt. Ihr bisheriges Passwort gilt nicht mehr. Bitte legen Sie über diesen Link ein neues fest:' => '{admin} (admin) has reset the password of your account at {app}. Your previous password no longer works. Please set a new one using this link:',
    'Sicherheitshinweis: Änderung im Verwaltungsbereich von {app}' => 'Security notice: change in the admin area of {app}',
    'im Verwaltungsbereich von {app} ({url}) wurde gerade Folgendes geändert:' => 'the following has just been changed in the admin area of {app} ({url}):',
    '„{account}“ ({username}) wurde als Admin eingeladen.' => '“{account}” ({username}) has been invited as an admin.',
    '„{account}“ ({username}) hat Admin-Rechte erhalten.' => '“{account}” ({username}) has been given admin rights.',
    '„{account}“ ({username}) hat keine Admin-Rechte mehr.' => '“{account}” ({username}) no longer has admin rights.',
    'Das Admin-Konto „{account}“ ({username}) wurde deaktiviert.' => 'The admin account “{account}” ({username}) has been deactivated.',
    'Das Admin-Konto „{account}“ ({username}) wurde wieder aktiviert.' => 'The admin account “{account}” ({username}) has been reactivated.',
    'Das Admin-Konto „{account}“ ({username}) wurde gelöscht.' => 'The admin account “{account}” ({username}) has been deleted.',
    'Alle Sitzungen wurden beendet: Alle Konten müssen sich neu anmelden.' => 'All sessions have been ended: all accounts have to sign in again.',
    'Durch: {actor}' => 'By: {actor}',
    'Diese Nachricht geht an alle Admins und an das betroffene Konto. War das nicht abgesprochen, prüfen Sie bitte unter „Benutzer“ die Konten und unter „Protokoll“ die letzten Änderungen. Bei Verdacht auf Missbrauch beenden Sie unter „Benutzer“ alle Sitzungen und ändern Sie die Passwörter.' => 'This message goes to all admins and to the account concerned. If this was not agreed, please check the accounts under “Users” and the latest changes under “Log”. If you suspect misuse, end all sessions under “Users” and change the passwords.',
    'Diese Nachricht geht an alle Admins. War das nicht abgesprochen, prüfen Sie bitte unter „Protokoll“ die letzten Änderungen und ändern Sie Ihr Passwort.' => 'This message goes to all admins. If this was not agreed, please check the latest changes under “Log” and change your password.',
    // Dashboard (admins): config.php readable for every user of the server ({files}: paths relative to the
    // installation with their permissions, e.g. "releases/<version>/config/config.php (0644)")
    'Die Konfiguration ist für alle Benutzer des Servers lesbar: {files}. Sie enthält die Passwörter für Datenbank und Mailversand sowie den geheimen Schlüssel. Bitte setzen Sie die Rechte auf 600 (oder 640), z. B. im FTP-Programm, oder geben Sie diesen Hinweis an die technische Betreuung weiter.' => 'The configuration can be read by every user of the server: {files}. It contains the passwords for the database and for sending mail as well as the secret key. Please set the permissions to 600 (or 640), e.g. in your FTP program, or pass this notice on to whoever looks after the server.',
    // Form posts from other websites (App::run)
    'Anfrage abgelehnt' => 'Request refused',
    'Das Formular wurde von einer anderen Website aus abgeschickt und deshalb nicht verarbeitet. Bitte öffnen Sie die Seite direkt und versuchen Sie es dort erneut.'
        => 'The form was sent from another website and was therefore not processed. Please open the page directly and try again there.',
];
