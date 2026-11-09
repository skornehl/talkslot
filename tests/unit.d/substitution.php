<?php
// Taking over a round to see its parents' data, structured log entries (expert review P2-10). The pages and the
// mail need the database: tests/smoke.d/substitution.sh
echo "Substitution and structured log\n";
$_SESSION = [];
$suBefore = App\Substitution::active(7, 3);
App\Substitution::start(7, 3);
check('substitution: per round and its teacher, in the session of this sign-in', !$suBefore && App\Substitution::active(7, 3) && !App\Substitution::active(8, 3)
    && !App\Substitution::active(7, 4) && array_keys($_SESSION) === ['substitutions']);
App\Substitution::clear();
check('… ends with the own new password or when all sessions are ended', !App\Substitution::active(7, 3));
$_SESSION = [];
check('log params: only the allowed keys with plain values, as JSON; nothing left: none',
    App\Audit::params(['count' => 4, 'date' => '2026-11-12', 'name' => 'Erika Muster', 'email' => 'e@example.org']) === '{"count":4,"date":"2026-11-12"}'
    && App\Audit::params(['name' => 'x']) === null && App\Audit::params([]) === null && App\Audit::params(['count' => ['a']]) === null);
check('log params: no key that could hold data of parents', !array_filter(App\Audit::PARAM_KEYS, static fn (string $k): bool => (bool) preg_match('/name|mail|parent|child|note|text|title/i', $k)));
