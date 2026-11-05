<?php
/**
 * Configuration – copy to config/config.php and fill in.
 * This file lives outside the web root and must NEVER be committed to the Git repository.
 * (When deploying via GitHub Actions it is generated from the repository secrets.)
 */
return [
    'app_name' => 'TalkSlot',
    'base_url' => 'https://termine.example.org', // without trailing slash
    'force_https' => true,
    'debug' => false,          // always false in production (no internal error details)
    'timezone' => 'Europe/Berlin',

    // Random secret key, at least 32 characters, e.g.: php -r 'echo bin2hex(random_bytes(32));'
    'secret' => '',
    // One-time key for install.php (at least 16 characters)
    'install_key' => '',

    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => '',
        'user' => '',
        'pass' => '',
        'prefix' => 'tp_',  // prefix for all tables, in case the database is shared ('' = none)
    ],

    'languages' => ['de', 'en', 'pl', 'ru', 'uk', 'tr'],   // order = display order; new language: create app/lang/<code>.php
    'default_language' => 'de', // shown when the visitor has not chosen a language; must be in 'languages'

    // Error reports (exceptions, database unreachable, mail delivery failed …) are mailed to this
    // address; empty = to the email address of the admin account (not possible while the database
    // is down). The same error is reported at most once per hour.
    'alert_email' => '',

    // Hosting provider for the privacy policy (replaces [[HOSTER]] there), per template language
    'hosting' => [
        'de' => '', // e.g. 'Beispiel Hosting GmbH, Musterweg 1, 12345 Musterstadt, als Auftragsverarbeiter nach Art. 28 DSGVO. Die Server stehen in Deutschland.'
        'en' => '', // e.g. 'Example Hosting GmbH, Musterweg 1, 12345 Musterstadt, Germany, as processor under Art. 28 GDPR. The servers are located in Germany.'
    ],

    'mail' => [
        'transport' => 'mail',      // mail (PHP mail/sendmail) | smtp (PHPMailer) | none
        'from_address' => '',       // sender, e.g. termine@example.org (domain with SPF/DKIM)
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


    'session_idle_minutes' => 30,   // admin: logout after inactivity
    'session_absolute_hours' => 8,  // admin: maximum session length
    'access_link_hours' => 48,      // validity of invitation and password links

    // Data deletion: in addition to the cron job, on page requests (at most every N minutes). Database
    // steps only: summaries and reminders are queued there, never sent while a visitor waits
    'cleanup_on_request' => true,
    'cleanup_interval_minutes' => 15,

    // Mail queue (outbox): reminders, summaries, mails to parents after a change by a teacher, and
    // confirmations that could not be sent at once. The cron job sends them, at most 'batch' mails within
    // about 'seconds' per run, retrying failed ones for up to 48 hours. With PHP-FPM or LiteSpeed a page
    // request also sends up to 10 due mails after its response has gone out ('after_response').
    // 'hourly_limit': at most this many reminders, summaries and mails to many parents after one change by
    // a teacher per hour, e.g. the hosting quota (0 = no limit); single mails to parents always go out first.
    'mail_queue' => [
        'batch' => 100,
        'seconds' => 20,
        'hourly_limit' => 0,
        'after_response' => true,
    ],

    // Cron monitoring: without a completed cron run for this many hours, admins see a warning on the dashboard
    // and the operator gets an alert mail, repeated daily while it lasts. 0 = off. 12 suits the GitHub workflow
    // "Cron", which GitHub often starts only every 4 to 8 hours; with the server's own hourly cron job, 3 fits.
    'cron_alert_hours' => 12,

    // Daily summary for teachers and reminders to parents are sent for the next day from this hour on (local time)
    'summary_evening_hour' => 18,

    // Language of the admin area for accounts without their own choice and before signing in
    // (de or en; everybody can change it under "Mein Profil")
    'admin_language' => 'de',

    // Log of the changes made by admins (Protokoll): entries are deleted after this many days
    'audit_log_days' => 90,

    // Rate limits: [max. count, time window in seconds] per truncated IP (IPv4 /24, IPv6 /48), unless noted.
    // Generous, because many parents may share a truncated IP (mobile network, school Wi-Fi).
    'rate_limits' => [
        'code' => [30, 900], // failed round code entries on the home page (per truncated IP)
        'attempt' => [150, 3600],   // all booking attempts (incl. input errors)
        'booking' => [30, 3600],    // successful bookings
        'manage' => [300, 3600],    // requests to change links
        'move' => [10, 3600],       // rebookings by parents (each sends a mail), per booking
        'confirm' => [2, 86400],    // confirmation mails sent from the appointment page, per booking (at most 24 hours)
        'login' => [5, 900],        // failed admin logins
        'login_user' => [20, 3600], // failed logins per account from devices that never signed in to it
        'login_device' => [20, 3600], // failed logins per known device (instead of login_user)
        'login_notice' => [1, 86400], // e-mails to an account whose login_user limit was reached
        'reauth' => [5, 900],       // wrong passwords when confirming admin rights, e-mail changes, ending all sessions and changing the own password (per sign-in)
        'invite' => [20, 3600],     // invalid invitation and password links
        'forgot' => [5, 3600],      // "Passwort vergessen" requests
        'teacher_mail' => [30, 3600], // mails to parents from entries by a teacher (confirmation, change notice), per account
        'teacher_bulk' => [200, 3600], // change notices to parents after a teacher changed a day or round, per account
    ],
    'csrf_min_age_seconds' => 2,    // form can be submitted after N seconds at the earliest (bot brake)
    'csrf_max_age_seconds' => 14400, // public forms and access by round code stay valid this long (4 hours)

    // Every key above is known to App\ConfigSchema: admins see a hint on the dashboard for unknown keys (e.g. a
    // typo) and invalid values; a missing secret, an invalid time zone or language stop the app with a message.
];
