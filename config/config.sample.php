<?php
/**
 * Konfiguration – als config/config.php kopieren und ausfüllen.
 * Diese Datei liegt außerhalb des Webroots und darf NIE ins Git-Repository.
 * (Beim Deployment per GitHub Actions wird sie aus den Repository-Secrets erzeugt.)
 */
return [
    'app_name' => 'TalkSlot',
    'base_url' => 'https://termine.example.org', // ohne abschließenden Schrägstrich
    'force_https' => true,
    'debug' => false,          // im Livebetrieb immer false (keine internen Fehlerdetails)
    'timezone' => 'Europe/Berlin',

    // Zufälliger geheimer Schlüssel, mind. 32 Zeichen, z. B.: php -r 'echo bin2hex(random_bytes(32));'
    'secret' => '',
    // Einmal-Schlüssel für install.php (mind. 16 Zeichen)
    'install_key' => '',

    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => '',
        'user' => '',
        'pass' => '',
        'prefix' => 'tp_',  // Präfix aller Tabellen, falls die Datenbank geteilt wird ('' = keins)
    ],

    'languages' => ['de', 'en', 'pl', 'ru', 'uk', 'tr'],   // Reihenfolge = Anzeige; neue Sprache: app/lang/<code>.php anlegen
    'default_language' => 'de',

    // Fehlermeldungen (Exceptions, Datenbank nicht erreichbar, Mailversand gescheitert …) per Mail
    // an diese Adresse; leer = an die E-Mail-Adresse des Admin-Kontos (dann nicht, wenn die
    // Datenbank ausgefallen ist). Gleiche Fehler höchstens einmal pro Stunde.
    'alert_email' => '',

    // Hoster für die Datenschutzerklärung (ersetzt dort [[HOSTER]]), je Sprache der Vorlagen
    'hosting' => [
        'de' => '', // z. B. 'Beispiel Hosting GmbH, Musterweg 1, 12345 Musterstadt, als Auftragsverarbeiter nach Art. 28 DSGVO. Die Server stehen in Deutschland.'
        'en' => '', // e.g. 'Example Hosting GmbH, Musterweg 1, 12345 Musterstadt, Germany, as processor under Art. 28 GDPR. The servers are located in Germany.'
    ],

    'mail' => [
        'transport' => 'mail',      // mail (PHP mail/sendmail) | smtp (PHPMailer) | none
        'from_address' => '',       // Absender, z. B. termine@example.org (Domain mit SPF/DKIM)
        'from_name' => 'TalkSlot',
        'reply_to' => '',           // optional
        'smtp' => [
            'host' => '',
            'port' => 587,
            'encryption' => 'tls',  // tls (STARTTLS) | ssl | none
            'user' => '',
            'pass' => '',
        ],
    ],


    'session_idle_minutes' => 30,   // Admin: Abmeldung nach Inaktivität
    'session_absolute_hours' => 8,  // Admin: maximale Sitzungsdauer
    'access_link_hours' => 48,      // Gültigkeit von Einladungs- und Passwort-Links

    // Datenlöschung: zusätzlich zum Cronjob bei Seitenaufrufen (höchstens alle N Minuten)
    'cleanup_on_request' => true,
    'cleanup_interval_minutes' => 15,

    // Tagesübersicht für Lehrkräfte wird ab dieser Stunde (Ortszeit) für den Folgetag verschickt
    'summary_evening_hour' => 18,

    // Rate-Limits: [max. Anzahl, Zeitfenster in Sekunden] je gekürzter IP (IPv4 /24, IPv6 /48).
    // Großzügig, weil sich viele Eltern eine gekürzte IP teilen können (Mobilfunk, Schul-WLAN).
    'rate_limits' => [
        'code' => [30, 900], // fehlgeschlagene Eingaben des Rundencodes auf der Startseite (je gekürzter IP)
        'attempt' => [150, 3600],   // alle Buchungsversuche (inkl. Eingabefehler)
        'booking' => [30, 3600],    // erfolgreiche Buchungen
        'manage' => [300, 3600],    // Aufrufe von Änderungslinks
        'login' => [5, 900],        // fehlgeschlagene Admin-Anmeldungen
    ],
    'csrf_min_age_seconds' => 2,    // Formular frühestens nach N Sekunden absendbar (Bot-Bremse)
];
