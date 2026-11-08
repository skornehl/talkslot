<?php
declare(strict_types=1);

namespace App;

/**
 * Every setting of config/config.php in one place: type, default and allowed values.
 *
 * - errors(): mistakes the app cannot run with (secret, time zone, languages, table prefix). app/bootstrap.php
 *   stops with a plain message, like it always did for a missing secret.
 * - warnings(): settings that do not work as written (unknown keys such as a typo, values of the wrong type
 *   or out of range). They are listed for admins on the dashboard, as the app keeps running with them.
 *
 * The defaults are those of the places that read a setting (Config::get('summary_evening_hour', 18)); a
 * unit test checks that both agree, that config/config.sample.php lists exactly these keys and that every
 * key the code reads is listed here.
 */
final class ConfigSchema
{
    /**
     * Dot path => [type, default, options]. Types: string, url, email, bool, int (options: min, max or null),
     * enum (options: the allowed values), limit ([count, seconds] of a rate limit), map (texts per language),
     * domains (a list of mail domains), and secret, timezone, languages, language, prefix, which errors() or
     * warnings() check on their own.
     */
    public const KEYS = [
        'app_name' => ['string', 'TalkSlot'],
        'base_url' => ['url', ''],
        'force_https' => ['bool', true],
        'debug' => ['bool', false],
        'timezone' => ['timezone', 'Europe/Berlin'],
        'secret' => ['secret', ''],
        'install_key' => ['string', ''],
        'db.host' => ['string', 'localhost'],
        'db.port' => ['int', 3306, 1, 65535],
        'db.name' => ['string', ''],
        'db.user' => ['string', ''],
        'db.pass' => ['string', ''],
        'db.prefix' => ['prefix', ''],
        'db.ssl_ca' => ['string', ''],
        'languages' => ['languages', ['de']],
        'default_language' => ['language', ''],
        'alert_email' => ['email', ''],
        'hosting' => ['map', []],
        'mail.transport' => ['enum', 'mail', ['mail', 'smtp', 'none']],
        'mail.from_address' => ['email', ''],
        'mail.from_name' => ['string', ''],
        'mail.reply_to' => ['email', ''],
        'mail.smtp.host' => ['string', ''],
        'mail.smtp.port' => ['int', 587, 1, 65535],
        'mail.smtp.encryption' => ['enum', 'tls', ['tls', 'ssl', 'none']],
        'mail.smtp.user' => ['string', ''],
        'mail.smtp.pass' => ['string', ''],
        'session_idle_minutes' => ['int', 30, 1, null],
        'session_absolute_hours' => ['int', 8, 1, null],
        'access_link_hours' => ['int', 48, 1, null],
        'cleanup_on_request' => ['bool', true],
        'cleanup_interval_minutes' => ['int', 15, 1, null],
        'cron_alert_hours' => ['int', CronMonitor::DEFAULT_HOURS, 0, null],
        'mail_queue.batch' => ['int', 100, 1, null],
        'mail_queue.seconds' => ['int', 20, 1, null],
        'mail_queue.hourly_limit' => ['int', 0, 0, null],
        'mail_queue.after_response' => ['bool', true],
        'summary_evening_hour' => ['int', 18, 0, 23],
        'summary_names' => ['bool', false],
        'allowed_account_domains' => ['domains', []],
        'admin_language' => ['enum', 'de', AdminI18n::LANGUAGES],
        'audit_log_days' => ['int', 90, 1, null],
        'security_events_days' => ['int', 30, 1, null],
        'rate_limits.code' => ['limit', [30, 900]],
        'rate_limits.attempt' => ['limit', [150, 3600]],
        'rate_limits.booking' => ['limit', [30, 3600]],
        'rate_limits.manage' => ['limit', [300, 3600]],
        'rate_limits.move' => ['limit', [10, 3600]],
        'rate_limits.confirm' => ['limit', [2, 86400]],
        'rate_limits.login' => ['limit', [5, 900]],
        'rate_limits.login_user' => ['limit', [20, 3600]],
        'rate_limits.login_device' => ['limit', [20, 3600]],
        'rate_limits.login_notice' => ['limit', [1, 86400]],
        'rate_limits.login_alarm' => ['limit', [3, 3600]],
        'rate_limits.reauth' => ['limit', [5, 900]],
        'rate_limits.invite' => ['limit', [20, 3600]],
        'rate_limits.forgot' => ['limit', [5, 3600]],
        'rate_limits.teacher_mail' => ['limit', [30, 3600]],
        'rate_limits.teacher_bulk' => ['limit', [ScheduleService::BULK_LIMIT, 3600]],
        'csrf_min_age_seconds' => ['int', 2, 0, null],
        'csrf_max_age_seconds' => ['int', 4 * 3600, 60, null],
    ];

    /**
     * Mistakes the app cannot run with, in English (command line) and German (with English on the web page,
     * see Platform::stop()).
     * @return list<array{en: string, de: string}>
     */
    public static function errors(array $config): array
    {
        $errors = [];
        if (strlen(self::text(self::value($config, 'secret'))) < 32) {
            $errors[] = [
                'en' => 'Configuration incomplete: "secret" is missing or shorter than 32 characters.',
                'de' => 'Die Konfiguration ist unvollständig: „secret“ fehlt oder ist kürzer als 32 Zeichen.',
            ];
        }
        // A misspelt time zone used to fall back to UTC without a word: every deadline, the reminders at
        // 6 pm and the clean-up at midnight one or two hours off
        $timezone = self::value($config, 'timezone');
        if (!self::validTimezone($timezone)) {
            $shown = self::shown($timezone);
            $errors[] = [
                'en' => "Configuration error: \"timezone\" is not a valid time zone ($shown). Use a name like Europe/Berlin.",
                'de' => "Fehler in der Konfiguration: „timezone“ ist keine gültige Zeitzone ($shown). Erwartet wird ein Name wie Europe/Berlin.",
            ];
        }
        // Without its file a language shows the raw text keys
        $languages = (array) self::value($config, 'languages');
        $missing = array_filter($languages, static fn ($code): bool => !is_string($code) || !preg_match('/^[a-z]{2,3}$/', $code)
            || !is_file(APP_ROOT . '/app/lang/' . $code . '.php'));
        if (!$languages || $missing) {
            $shown = $missing ? implode(', ', array_map(self::shown(...), $missing)) : '–';
            $errors[] = [
                'en' => "Configuration error: \"languages\" must name at least one language, each with a file in app/lang/ (not found: $shown).",
                'de' => "Fehler in der Konfiguration: „languages“ muss mindestens eine Sprache nennen, jede mit einer Datei in app/lang/ (nicht gefunden: $shown).",
            ];
        }
        $prefix = self::value($config, 'db.prefix');
        if (!is_string($prefix) || ($prefix !== '' && !preg_match('/^[A-Za-z0-9_]{1,20}$/', $prefix))) {
            $errors[] = [
                'en' => 'Configuration error: "db.prefix" may only contain letters, digits and _ (at most 20 characters).',
                'de' => 'Fehler in der Konfiguration: „db.prefix“ darf nur Buchstaben, Ziffern und _ enthalten (höchstens 20 Zeichen).',
            ];
        }
        return $errors;
    }

    /**
     * Settings that do not work as written, as sentences for admins in the admin language. Values are never
     * repeated (they may be passwords), only the key and what is expected.
     * @return list<string>
     */
    public static function warnings(array $config): array
    {
        $warnings = [];
        self::walk($config, '', $warnings);
        $default = self::value($config, 'default_language');
        $languages = array_values((array) self::value($config, 'languages'));
        if (is_string($default) && $default !== '' && $languages && !in_array($default, $languages, true)) {
            $warnings[] = __('„default_language“ ist {value}, diese Sprache steht aber nicht unter „languages“. Stattdessen gilt {fallback}.',
                ['value' => self::shown($default), 'fallback' => self::shown($languages[0])]);
        }
        $transport = self::value($config, 'mail.transport');
        if ($transport !== 'none' && self::text(self::value($config, 'mail.from_address')) === '') {
            $warnings[] = __('„mail.from_address“ fehlt: Ohne Absenderadresse verschickt TalkSlot keine E-Mails.');
        }
        if ($transport === 'mail' && !function_exists('mail')) {
            $warnings[] = __('„mail.transport“ ist „mail“, aber die PHP-Funktion mail() ist auf diesem Server abgeschaltet: So gehen keine E-Mails hinaus. Bitte stellen Sie auf „smtp“ um.');
        }
        return $warnings;
    }

    /** Value of a setting as Config::get() returns it, with the default of this schema. */
    public static function value(array $config, string $key): mixed
    {
        $value = $config;
        foreach (explode('.', $key) as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return self::KEYS[$key][1] ?? null;
            }
            $value = $value[$part];
        }
        return $value;
    }

    /** Settings that hold further settings: "db", "mail", "mail.smtp", "mail_queue", "rate_limits". */
    public static function sections(): array
    {
        $sections = [];
        foreach (array_keys(self::KEYS) as $key) {
            for ($i = strpos($key, '.'); $i !== false; $i = strpos($key, '.', $i + 1)) {
                $sections[substr($key, 0, $i)] = true;
            }
        }
        return array_keys($sections);
    }

    /** The time zone as date_default_timezone_set() takes it (also stricter than new DateTimeZone(): "+01:00", "CET"). */
    public static function validTimezone(mixed $timezone): bool
    {
        if (!is_string($timezone) || $timezone === '') {
            return false;
        }
        $current = date_default_timezone_get();
        $valid = @date_default_timezone_set($timezone);
        date_default_timezone_set($current);
        return $valid;
    }

    /** @param list<string> $warnings */
    private static function walk(array $values, string $prefix, array &$warnings): void
    {
        foreach ($values as $name => $value) {
            $key = $prefix . $name;
            if (isset(self::KEYS[$key])) {
                if (!self::valid(self::KEYS[$key], $value)) {
                    $warnings[] = __('„{key}“ hat keinen gültigen Wert, erwartet wird {expected}.', ['key' => $key, 'expected' => self::expected(self::KEYS[$key])]);
                }
            } elseif (in_array($key, self::sections(), true)) {
                if (is_array($value)) {
                    self::walk($value, $key . '.', $warnings);
                } else {
                    $warnings[] = __('„{key}“ hat keinen gültigen Wert, erwartet wird {expected}.', ['key' => $key, 'expected' => __('eine Liste von Einstellungen in eckigen Klammern wie in config.sample.php')]);
                }
            } else {
                $suggestion = self::suggestion($key);
                $warnings[] = __('„{key}“ ist keine Einstellung von TalkSlot und wirkt nicht.', ['key' => $key])
                    . ($suggestion !== null ? ' ' . __('Gemeint ist vermutlich „{suggestion}“.', ['suggestion' => $suggestion]) : '');
            }
        }
    }

    private static function valid(array $spec, mixed $value): bool
    {
        $int = is_int($value) ? $value : (is_string($value) && preg_match('/^-?\d{1,9}$/', $value) ? (int) $value : null);
        return match ($spec[0]) {
            'string', 'language' => is_string($value),
            'url' => is_string($value) && preg_match('~^https?://[^/\s?#]+(/\S*)?$~i', $value) === 1,
            'email' => is_string($value) && ($value === '' || is_valid_email($value)),
            'bool' => is_bool($value) || $value === 0 || $value === 1,
            'int' => $int !== null && $int >= $spec[2] && ($spec[3] === null || $int <= $spec[3]),
            'enum' => in_array($value, $spec[2], true),
            // RateLimiter::limits() takes any two values and casts them: [0, 900] would block every request
            'limit' => is_array($value) && array_keys($value) === [0, 1] && is_int($value[0]) && is_int($value[1]) && min($value) >= 1,
            'map' => is_array($value) && !array_filter($value, static fn ($text): bool => !is_string($text)),
            'domains' => is_array($value) && array_is_list($value) && !array_filter($value, static fn ($domain): bool => !is_string($domain) || !AccountDomains::valid($domain)),
            default => true, // secret, timezone, languages, prefix: errors() stops the app instead
        };
    }

    /** What a setting of this type expects, for the warning. */
    private static function expected(array $spec): string
    {
        return match ($spec[0]) {
            'url' => __('eine Adresse wie https://termine.example.org'),
            'email' => __('eine E-Mail-Adresse oder \'\''),
            'bool' => __('true oder false (ohne Anführungszeichen)'),
            'int' => $spec[3] === null ? __('eine ganze Zahl ab {min}', ['min' => $spec[2]])
                : __('eine ganze Zahl von {min} bis {max}', ['min' => $spec[2], 'max' => $spec[3]]),
            'enum' => __('einer dieser Werte: {values}', ['values' => implode(', ', array_map(static fn (string $v): string => "'$v'", $spec[2]))]),
            'limit' => __('[Anzahl, Sekunden] mit ganzen Zahlen ab 1'),
            'map' => __('Texte je Sprache, z. B. [\'de\' => \'…\']'),
            'domains' => __('eine Liste von Domains, z. B. [\'schule.example.org\']'),
            default => __('ein Text in Anführungszeichen'),
        };
    }

    /** The known key closest to a misspelt one (at most three letters off), e.g. "sumary_evening_hour". */
    private static function suggestion(string $key): ?string
    {
        $best = null;
        $bestDistance = 4;
        foreach ([...array_keys(self::KEYS), ...self::sections()] as $known) {
            $distance = levenshtein($key, $known);
            if ($distance < $bestDistance && $distance < strlen($key) / 2) {
                [$best, $bestDistance] = [$known, $distance];
            }
        }
        return $best;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /** A configured value for a message: a short text as it is, anything else by its type. */
    private static function shown(mixed $value): string
    {
        return is_string($value) ? '"' . mb_substr($value, 0, 40) . '"' : get_debug_type($value);
    }
}
