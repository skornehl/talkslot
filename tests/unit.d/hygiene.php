<?php
// Code hygiene (expert review P3-4): anchors, date output in templates, unused code
echo "Code hygiene\n";
// "$" also matches before a final line break: /admin/round/1%0A reached the round page. Routes end with \z.
$hyRoutes = array_column((new ReflectionClassConstant(App\Controller\AdminController::class, 'ROUTES'))->getValue(), 1);
preg_match_all("/preg_match\\('(#\\^[^']*)'/", (string) file_get_contents(dirname(__DIR__, 2) . '/app/src/Controller/PublicController.php'), $hyPublic);
preg_match_all("/preg_match\\('(#\\^[^']*)'/", (string) file_get_contents(dirname(__DIR__, 2) . '/app/src/Controller/AdminController.php'), $hyAdmin);
$hyBad = array_filter(array_merge($hyRoutes, $hyPublic[1], $hyAdmin[1]), static fn (string $p): bool => !str_ends_with($p, '\z#'));
check('routes (admin table, invitation, public pages) end with \z, not $' . ($hyBad ? ' (' . implode(' ', $hyBad) . ')' : ''),
    count($hyRoutes) > 40 && count($hyPublic[1]) >= 2 && count($hyAdmin[1]) >= 1 && !$hyBad);
$hyNl = "\n";
check('a value with a line break at the end is not well-formed: token, access token, table prefix',
    !App\Token::isWellFormed(str_repeat('a', 43) . $hyNl) && App\Token::isWellFormed(str_repeat('a', 43))
    && !App\RoundAccess::validToken(['id' => 1, 'code' => 'K7MP3QXA'], App\RoundAccess::token(['id' => 1, 'code' => 'K7MP3QXA']) . $hyNl)
    && App\RoundAccess::validToken(['id' => 1, 'code' => 'K7MP3QXA'], App\RoundAccess::token(['id' => 1, 'code' => 'K7MP3QXA']))
    && App\ConfigSchema::errors(['db' => ['prefix' => 'tp_' . $hyNl], 'secret' => str_repeat('s', 32)]) !== []
    && App\ConfigSchema::errors(['db' => ['prefix' => 'tp_'], 'secret' => str_repeat('s', 32)]) === []);
check('fmt_datetime(): date and time of a DATETIME, the date alone with a format, empty for nothing or nonsense (not 01.01.1970)',
    fmt_datetime('2026-08-17 14:05:09') === '17.08.2026 14:05' && fmt_datetime('2026-08-17', 'd.m.Y') === '17.08.2026'
    && fmt_datetime(null) === '' && fmt_datetime('') === '' && fmt_datetime('kein Datum') === '');
// strtotime() gives false for an unreadable value, which date() shows as 01.01.1970
$hyTemplates = implode("\n", array_map('file_get_contents', array_merge(glob(dirname(__DIR__, 2) . '/app/templates/*.php'), glob(dirname(__DIR__, 2) . '/app/templates/*/*.php'))));
check('templates format DATETIME values with fmt_datetime(), no strtotime() of their own', !str_contains($hyTemplates, 'strtotime('));
check('unused RateLimiter::clear() removed', !method_exists(App\RateLimiter::class, 'clear'));
