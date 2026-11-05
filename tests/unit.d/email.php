<?php
// E-mail addresses with umlauts in the domain (expert review P3-3): one rule for input, storage and sending
echo "E-mail addresses (App\\Email)\n";
// Expected values: Python's RFC 3492 codec ("müller".encode("punycode")); ß stays ß as in browsers and IDNA2008
// (idn_to_ascii() only with IDNA_NONTRANSITIONAL_TO_ASCII, its default maps it to ss)
$emPuny = [
    'müller.de' => 'xn--mller-kva.de', 'MÜLLER.DE' => 'xn--mller-kva.de', 'bücher.example' => 'xn--bcher-kva.example',
    'straße.de' => 'xn--strae-oqa.de', 'öö.de' => 'xn--ndaa.de', 'ünicode-ü.de' => 'xn--nicode--m2ah.de',
    'türkçe.com.tr' => 'xn--trke-2oa7j.com.tr', 'україна.укр' => 'xn--80aa2annq7l.xn--j1amh',
    'пример.испытание' => 'xn--e1afmkfd.xn--80akhbyknj4f', '例え.テスト' => 'xn--r8jz45g.xn--zckzah',
    'ελληνικά.gr' => 'xn--hxargifdar.gr', "例え\u{3002}テスト" => 'xn--r8jz45g.xn--zckzah', 'example.org' => 'example.org',
];
$emWrong = array_filter($emPuny, static fn (string $want, string $in): bool => App\Email::domainToAscii($in) !== $want, ARRAY_FILTER_USE_BOTH);
check('domains with umlauts and other scripts become punycode (RFC 3492), ASCII domains stay as they are'
    . ($emWrong ? ' (wrong: ' . implode(', ', array_keys($emWrong)) . ')' : ''), !$emWrong);
check('domains that cannot be converted: empty label, invalid UTF-8, a label longer than 63 characters once encoded',
    App\Email::domainToAscii('müller..de') === null && App\Email::domainToAscii("m\xFCller.de") === null
    && App\Email::domainToAscii(str_repeat('ä', 60) . '.de') === null && App\Email::domainToAscii('.müller.de') === null);
// ü typed as u + combining diaeresis: the same domain as ü where intl's Normalizer exists, refused without it
$emCombined = App\Email::domainToAscii("mu\u{0308}ller.de");
check('a combining mark: NFC with the Normalizer (' . (class_exists(Normalizer::class) ? 'present' : 'missing') . '), otherwise refused',
    class_exists(Normalizer::class) ? $emCombined === 'xn--mller-kva.de' : $emCombined === null);
check('normalize(): the address as it is stored and sent; a domain with umlauts in lower case and as punycode',
    App\Email::normalize(' Eltern@Müller.de ') === 'Eltern@xn--mller-kva.de' && App\Email::normalize('a.b+c@example.org') === 'a.b+c@example.org'
    && App\Email::normalize('x@xn--mller-kva.de') === 'x@xn--mller-kva.de' && App\Email::normalize('x@Example.ORG') === 'x@Example.ORG');
check('normalize(): umlauts before the @, no @, nothing before it, special forms and more than 254 characters are refused',
    App\Email::normalize('jürgen@example.org') === null && App\Email::normalize('example.org') === null && App\Email::normalize('@example.org') === null
    && App\Email::normalize('"a b"@example.org') === null && App\Email::normalize('a@b@müller.de') === null && App\Email::normalize('') === null
    && App\Email::normalize(str_repeat('a', 64) . '@' . str_repeat('b', 63) . '.' . str_repeat('c', 63) . '.' . str_repeat('d', 61) . '.de') === null);
check('isValid() and is_valid_email(): only the stored form (ASCII), as before for special forms',
    App\Email::isValid('x@xn--mller-kva.de') && !App\Email::isValid('x@müller.de') && !is_valid_email('x@müller.de') && is_valid_email('x@example.org')
    && !is_valid_email('a<b>@example.org') && !is_valid_email('a b@example.org') && !is_valid_email(''));
check('input(): from a form, the stored form if usable, otherwise as typed (so that the validation rejects it)',
    App\Email::input(' x@Müller.de ') === 'x@xn--mller-kva.de' && App\Email::input(' jürgen@example.org ') === 'jürgen@example.org' && App\Email::input('') === '');
check('booking form: an address with umlauts in the domain is accepted, umlauts before the @ are not',
    App\Controller\PublicController::validate(['name' => 'Familie Müller', 'email' => 'eltern@müller.de'], false) === []
    && App\Controller\PublicController::validate(['name' => 'Familie Müller', 'email' => 'jürgen@example.org'], false) === [t('err.email')]);
// Account domains: an entry with umlauts as it is or as punycode, compared with the stored form
$emCfg = App\Config::all();
App\Config::load(['allowed_account_domains' => ['Grundschule-Münster.de', '@xn--bcher-kva.example', 'kein domain']] + $emCfg);
check('account domains: entries with umlauts as they are or as punycode; invalid ones are ignored',
    App\AccountDomains::allowed() === ['xn--grundschule-mnster-y6b.de', 'xn--bcher-kva.example']
    && App\AccountDomains::permits('a@xn--grundschule-mnster-y6b.de') && App\AccountDomains::permits('a@XN--BCHER-KVA.example')
    && !App\AccountDomains::permits('a@grundschule-munster.de')
    && App\AccountDomains::valid('münster.de') && App\AccountDomains::valid('@münster.de') && !App\AccountDomains::valid('münster') && !App\AccountDomains::valid('mü nster.de'));
App\Config::load($emCfg);
check('configuration: a sender with umlauts in the domain is no warning (the mailer sends it as punycode), a broken one is',
    !array_filter(App\ConfigSchema::warnings(['mail' => ['from_address' => 'termine@grundschule-münster.de'] + (array) $emCfg['mail']] + $emCfg), static fn (string $w): bool => str_contains($w, 'mail.from_address'))
    && array_filter(App\ConfigSchema::warnings(['mail' => ['from_address' => 'termine@'] + (array) $emCfg['mail']] + $emCfg), static fn (string $w): bool => str_contains($w, 'mail.from_address')) !== []);
check('copied addresses: spaces and invisible characters at the ends go, full-width letters become ASCII',
    App\Email::normalize("x@gmail.com\u{00A0}") === 'x@gmail.com' && App\Email::normalize("\u{FEFF}x@example.org\u{200B}") === 'x@example.org'
    && App\Email::normalize('x@ｅｘａｍｐｌｅ.de') === 'x@example.de' && App\Email::normalize("x@b\xFF.de") === null);
check('labels with umlauts: letters, digits, inner hyphens only (no invisible characters, emoji, outer hyphens)',
    App\Email::normalize("x@gm\u{200B}ail.com") === null && App\Email::normalize("x@mül\u{00AD}ler.de") === null
    && App\Email::normalize("x@\u{1F600}.de") === null && App\Email::normalize('x@-müller.de') === null && App\Email::normalize('x@müller-.de') === null
    && App\Email::normalize('x@mül-ler.de') === 'x@xn--ml-ler-3ya.de');
$emStart = hrtime(true);
$emLong = App\Email::normalize('x@' . implode('', array_map(static fn (int $c): string => mb_chr($c, 'UTF-8'), range(0x4E00, 0x4E00 + 19999))) . '.de');
check('a long address is refused before encoding (60 KB of distinct characters in well under a second)',
    $emLong === null && (hrtime(true) - $emStart) < 200_000_000 && App\Email::normalize('x@' . str_repeat('ä', 60) . '.de') === null);
App\Config::load(['allowed_account_domains' => ['Grundschule-Münster.de', '@xn--bcher-kva.example']] + $emCfg);
check('account domains in messages: as the admin wrote them, compared in punycode', App\AccountDomains::shown() === ['grundschule-münster.de', 'xn--bcher-kva.example']
    && str_contains((string) App\AccountDomains::error('a@example.org'), 'grundschule-münster.de'));
App\Config::load($emCfg);
