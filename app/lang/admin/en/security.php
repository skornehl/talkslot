<?php
/** English texts of the admin area: German original => English (see App\AdminI18n). Sign-in protection and password rules. */
return [
    // Password rules (Auth::passwordPolicy)
    'Das Passwort darf weder den Benutzernamen noch die E-Mail-Adresse oder den Namen der Anwendung enthalten.' => 'The password must not contain the username, the email address or the name of the application.',
    'Dieses Passwort ist zu leicht zu erraten (häufig verwendet, ein Wort mit Ziffern, Wiederholungen oder Tastenfolgen). Gut geeignet sind z. B. drei oder vier zufällige Wörter.' => 'This password is too easy to guess (commonly used, a word with digits, repetitions or keyboard runs). Three or four random words, for example, work well.',
    // Email to the account holder when the per-account login limit is reached
    'Viele fehlgeschlagene Anmeldeversuche bei {app}' => 'Many failed sign-in attempts at {app}',
    'für Ihr Konto bei {app} ({url}) gab es viele fehlgeschlagene Anmeldeversuche (mehr als {count}). Zum Schutz Ihres Kontos ist die Anmeldung deshalb für bis zu {minutes} Minuten nur auf Geräten möglich, auf denen Sie sich schon einmal angemeldet haben.' => 'there have been many failed sign-in attempts for your account at {app} ({url}) (more than {count}). To protect your account, for up to {minutes} minutes you can only sign in on devices you have signed in with before.',
    'Auf einem neuen Gerät hilft „Passwort vergessen?“ auf der Anmeldeseite: Wenn Sie über den Link ein neues Passwort festlegen, können Sie sich in diesem Browser sofort anmelden.' => 'On a new device, use “Forgot your password?” on the sign-in page: once you have set a new password through the link, you can sign in in that browser straight away.',
    'Waren Sie das nicht, versucht vermutlich jemand, Ihr Passwort zu erraten. Solange Sie es nirgends sonst verwenden, müssen Sie nichts tun. Bei Fragen wenden Sie sich an einen Admin.' => 'If this was not you, someone is probably trying to guess your password. As long as you do not use it anywhere else, you do not need to do anything. If you have questions, contact an admin.',
];
