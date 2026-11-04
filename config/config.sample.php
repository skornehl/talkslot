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

    // Data deletion: in addition to the cron job, on page requests (at most every N minutes)
    'cleanup_on_request' => true,
    'cleanup_interval_minutes' => 15,

    // Daily summary for teachers and reminders to parents are sent for the next day from this hour on (local time)
    'summary_evening_hour' => 18,

    // Language of the admin area for accounts without their own choice and before signing in
    // (de or en; everybody can change it under "Mein Profil")
    'admin_language' => 'de',

    // Log of the changes made by admins (Protokoll): entries are deleted after this many days
    'audit_log_days' => 90,

    // Rate limits: [max. count, time window in seconds] per truncated IP (IPv4 /24, IPv6 /48).
    // Generous, because many parents may share a truncated IP (mobile network, school Wi-Fi).
    'rate_limits' => [
        'code' => [30, 900], // failed round code entries on the home page (per truncated IP)
        'attempt' => [150, 3600],   // all booking attempts (incl. input errors)
        'booking' => [30, 3600],    // successful bookings
        'manage' => [300, 3600],    // requests to change links
        'login' => [5, 900],        // failed admin logins
        'reauth' => [5, 900],       // wrong passwords when confirming admin rights, e-mail changes, ending all sessions (per account)
    ],
    'csrf_min_age_seconds' => 2,    // form can be submitted after N seconds at the earliest (bot brake)
];
