<?php
// Sign-in protection and password rules (expert review P0-4). Sign-in itself needs the database: tests/smoke.d/security.sh
echo "Sign-in and passwords\n";

// Usernames: the collation of users.username ignores case and accents, so only plain ASCII names may
// reach the login query (otherwise each spelling counts under a key of its own)
check('username: letters, digits and . _ @ + - accepted',
    App\Auth::validUsername('sabrina') && App\Auth::validUsername('SABRINA') && App\Auth::validUsername('anna.schmidt+test_1@schule-x.example'));
check('username: accent, dotted İ, zero-width space, full width, line break rejected',
    !App\Auth::validUsername('sábrina') && !App\Auth::validUsername('SÁBRİNA') && !App\Auth::validUsername("sabri\u{200B}na")
    && !App\Auth::validUsername('ｓａｂｒｉｎａ') && !App\Auth::validUsername("sabrina\n") && !App\Auth::validUsername('sab rina'));
check('username: 3 to 254 characters', !App\Auth::validUsername('ab') && App\Auth::validUsername('abc')
    && App\Auth::validUsername(str_repeat('a', 254)) && !App\Auth::validUsername(str_repeat('a', 255)));

// Password rules
$rules = App\PasswordRules::class;
check('common passwords (local list) rejected, case-insensitive', $rules::isCommon('sonnenschein') && $rules::isCommon('Schmetterling') && $rules::isCommon('123456789012'));
check('a listed word with digits/symbols around it, also with @ for a and 0 for o',
    $rules::isCommon('Sommer2024!!!!') && $rules::isCommon('iloveyou2024!') && $rules::isCommon('P@ssw0rd-2024') && $rules::isCommon('!!Passwort12345'));
check('passphrases and random passwords are not common', !$rules::isCommon('Kaffee-Wolke-Tisch-17') && !$rules::isCommon('k7#Pq9!zW2mX') && !$rules::isCommon('Ölbaum am Fluss 77'));
check('repetitions and runs rejected', $rules::isPattern('aaaaaaaaaaab') && $rules::isPattern('passwortpasswort') && $rules::isPattern('123456789012')
    && $rules::isPattern('abcdefghijkl') && $rules::isPattern('1q2w3e4r5t6z') && $rules::isPattern('123qweasdzxc') && $rules::isPattern('0987654321zyx')
    && $rules::isPattern('!@#$%^&*()_+') && $rules::isPattern('1234-5678-9012') && $rules::isPattern('QWERTZUIOPÜA'));
check('ordinary passwords are no pattern', !$rules::isPattern('Kaffee-Wolke-Tisch-17') && !$rules::isPattern('k7#Pq9!zW2mX') && !$rules::isPattern('Sehr-Sicheres-Dev-Pw1'));
check('personal values: whole value or a word of 4+ characters before the @, case-insensitive',
    $rules::containsPersonal('Kaffee-SABRINA-17', ['sabrina']) && $rules::containsPersonal('Schmidt-Kaffee-17', ['anna.schmidt@schule.example'])
    && $rules::containsPersonal('x-ANNA.SCHMIDT@SCHULE.EXAMPLE', ['anna.schmidt@schule.example']));
check('personal values: words after the @ and short words do not count on their own',
    !$rules::containsPersonal('Schule-Example-Abc-1', ['anna.schmidt@schule.example']) && !$rules::containsPersonal('Bo-Kaffeetasse-19', ['bo@x.example', '']));
check('personal values: a part of 4 characters only as a word of its own (no false alarm for "Hannah", "Zweites")',
    $rules::containsPersonal('Anna-2024-Kaffee', ['anna.schmidt@schule.example']) && $rules::containsPersonal('kaffee4anna', ['anna@schule.example'])
    && !$rules::containsPersonal('Hannah-Montana-1', ['anna.schmidt@schule.example']) && !$rules::containsPersonal('Zweites-Passwort-123', ['zwei@example.org']));
check('list of common passwords is shipped (> 10,000 entries, lower case)', (static function (): bool {
    $lines = array_filter(file(App\PasswordRules::LIST_FILE, FILE_IGNORE_NEW_LINES) ?: [], static fn ($l) => $l !== '' && $l[0] !== '#');
    return count($lines) > 10000 && !array_filter($lines, static fn ($l) => mb_strtolower($l) !== $l);
})());

App\AdminI18n::set('de');
$policy = static fn (string $pw, string ...$personal) => (string) App\Auth::passwordPolicy($pw, ...$personal);
check('password policy: username or e-mail address in it → rejected',
    str_contains($policy('Sabrina-Kaffee-Wolke-9', 'sabrina', 'lehrer@example.org'), 'weder den Benutzernamen')
    && str_contains($policy('Kaffee-Lehrer-Wolke-9', 'sabrina', 'lehrer@example.org'), 'weder den Benutzernamen'));
App\Config::load(['app_name' => 'Termine Gesamtschule'] + $cfg);
check('password policy: app name (configured and "TalkSlot") in it → rejected',
    str_contains($policy('Gesamtschule-Kaffee-9'), 'Namen der Anwendung') && str_contains($policy('talkslot-kaffee-wolke'), 'Namen der Anwendung'));
App\Config::load($cfg);
check('password policy: common password or pattern → rejected, with a hint',
    str_contains($policy('Sonnenschein2024'), 'zu leicht zu erraten') && str_contains($policy('passwortpasswort'), 'drei oder vier zufällige Wörter'));
check('password policy: passphrase accepted', $policy('Kaffee-Wolke-Tisch-17', 'sabrina', 'lehrer@example.org') === '');

// The installer applies the same rules (before: only the minimum length, so bcrypt cut longer passwords off unnoticed)
$long = str_repeat('Kaffee-Wolke-', 6) . 'x';
check('installer: password over 72 bytes rejected', in_array(App\Auth::passwordPolicy($long), App\Installer::validate('sabrina', $long, $long, ''), true) && strlen($long) > 72);
check('installer: username or e-mail address in the password, common password rejected',
    count(App\Installer::validate('sabrina', 'Sabrina-Kaffee-Wolke-9', 'Sabrina-Kaffee-Wolke-9', '')) === 1
    && count(App\Installer::validate('admin', 'Kaffee-Lehrer-Wolke-9', 'Kaffee-Lehrer-Wolke-9', 'lehrer@example.org')) === 1
    && count(App\Installer::validate('sabrina', 'Sonnenschein2024', 'Sonnenschein2024', '')) === 1);
check('installer: valid input, username with accent rejected',
    App\Installer::validate('sabrina', 'Kaffee-Wolke-Tisch-17', 'Kaffee-Wolke-Tisch-17', 'lehrer@example.org') === []
    && count(App\Installer::validate('sábrina', 'Kaffee-Wolke-Tisch-17', 'Kaffee-Wolke-Tisch-17', '')) === 1);

// Known-device cookie: signed per account, expires, cannot be moved to another account
$device = App\TrustedDevice::class;
$nonce = 'AbCdEfGhIjKlMnOpQrStUv';
$_COOKIE = [$device::cookieName(7) => $device::sign(7, $nonce, time() - 86400)];
check('device cookie: name per account, without the account ID in plain text',
    (bool) preg_match('/^tp_device_[0-9a-f]{16}$/', $device::cookieName(7)) && $device::cookieName(7) !== $device::cookieName(8)
    && $device::cookieName(7) === $device::cookieName(7));
check('device cookie: valid → its own rate limit subject', $device::subject(7) === 'device:' . $nonce);
$_COOKIE[$device::cookieName(8)] = $device::sign(7, $nonce, time());
check('device cookie: one of another account does not count', $device::subject(8) === null);
$cookie = static function (string $value) use ($device): ?string { $_COOKIE = [$device::cookieName(7) => $value]; return $device::subject(7); };
$signed = $device::sign(7, $nonce, time());
check('device cookie: changed signature, nonce or time rejected',
    $cookie(substr($signed, 0, -1) . (str_ends_with($signed, '0') ? '1' : '0')) === null
    && $cookie(str_replace($nonce, strrev($nonce), $signed)) === null
    && $cookie(preg_replace('/\.\d+\./', '.' . (time() - 5) . '.', $device::sign(7, $nonce, time()))) === null);
check('device cookie: expired after ' . $device::DAYS . ' days, not from the future',
    $cookie($device::sign(7, $nonce, time() - $device::DAYS * 86400 + 60)) !== null && $cookie($device::sign(7, $nonce, time() - $device::DAYS * 86400 - 60)) === null
    && $cookie($device::sign(7, $nonce, time() + 3600)) === null);
$_COOKIE = [$device::cookieName(7) => ['x']];
check('device cookie: malformed or missing → none', $device::subject(7) === null && $cookie('kaputt') === null && $cookie('') === null
    && (($_COOKIE = []) === [] && $device::subject(7) === null));
$_COOKIE = [];
