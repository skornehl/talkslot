<?php
// Request for an interpreter, asked per round (expert review P3-1); with the database: tests/smoke.d/interpreter.sh
echo "Request for an interpreter\n";
$inValid = static fn (array $extra): array => App\Controller\PublicController::validate(['name' => 'Mia Test', 'email' => ''] + $extra, false);
check('the language is optional: nothing entered, or a language of up to 60 characters (not bytes)',
    $inValid([]) === [] && $inValid(['interpreter_lang' => '']) === [] && $inValid(['interpreter_lang' => 'Arabisch']) === []
    && $inValid(['interpreter_lang' => str_repeat('ü', 60)]) === []);
check('longer than 60 characters, or a link: refused (a language, not a place for messages)',
    $inValid(['interpreter_lang' => str_repeat('x', 61)]) === ['interpreter_lang' => t('err.interpreter')]
    && $inValid(['interpreter_lang' => 'https://example.org']) === ['interpreter_lang' => t('err.interpreter')]
    && $inValid(['interpreter_lang' => 'www.example.org']) === ['interpreter_lang' => t('err.interpreter')]);
check('stored without control characters and surplus spaces; blank = no interpreter requested',
    App\Controller\PublicController::cleanLanguage("  Dari \t (Afghanistan)\n") === 'Dari (Afghanistan)'
    && App\Controller\PublicController::cleanLanguage('') === null && App\Controller\PublicController::cleanLanguage(" \t\n") === null
    && App\Controller\PublicController::cleanLanguage("\u{200B}") === null && App\Controller\PublicController::cleanLanguage('0') === '0');

$inSteps = (new ReflectionClassConstant(App\Migrations::class, 'STEPS'))->getValue();
$inSchema = (string) file_get_contents(dirname(__DIR__, 2) . '/app/schema.sql');
check('migration 28 only adds columns, off by default; schema.sql has the same',
    App\Migrations::LATEST >= 28 && count($inSteps[28]) === 2
    && str_contains($inSteps[28][0], 'ADD COLUMN IF NOT EXISTS ask_interpreter TINYINT(1) NOT NULL DEFAULT 0')
    && str_contains($inSteps[28][1], 'ADD COLUMN IF NOT EXISTS interpreter_lang VARCHAR(60) NULL')
    && str_contains($inSchema, 'ask_interpreter TINYINT(1) NOT NULL DEFAULT 0') && str_contains($inSchema, 'interpreter_lang VARCHAR(60) NULL'));

// Not the language of the interface (which would hint at the parents' origin): only what the parents entered, and only
// where it is needed to prepare the meeting. A new place that shows or sends it has to be added here on purpose.
$inRoot = dirname(__DIR__, 2) . '/app/';
$inUsed = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($inRoot . 'src', FilesystemIterator::SKIP_DOTS)) as $inFile) {
    if (str_contains((string) file_get_contents((string) $inFile), 'interpreter_lang')) {
        $inUsed[] = substr((string) $inFile, strlen($inRoot));
    }
}
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($inRoot . 'templates', FilesystemIterator::SKIP_DOTS)) as $inFile) {
    if (str_contains((string) file_get_contents((string) $inFile), 'interpreter_lang')) {
        $inUsed[] = substr((string) $inFile, strlen($inRoot));
    }
}
sort($inUsed);
check('the wish appears only in the forms, the list, the printout, the access request and the page of the booking, in no mail',
    $inUsed === ['src/BookingService.php', 'src/Controller/AdminController.php', 'src/Controller/PublicController.php', 'src/Migrations.php',
        'templates/admin/access_request.php', 'templates/admin/booking_form.php', 'templates/admin/print.php', 'templates/admin/round.php',
        'templates/public/manage.php', 'templates/public/round.php']);

$inMissing = [];
foreach (['de', 'en', 'pl', 'ru', 'tr', 'uk'] as $inLang) {
    $inTexts = require $inRoot . "lang/$inLang.php";
    foreach (['form.interpreter', 'form.interpreter.hint', 'privacy.summary.data_interpreter', 'err.interpreter', 'manage.interpreter'] as $inKey) {
        if (trim((string) ($inTexts[$inKey] ?? '')) === '') {
            $inMissing[] = "$inLang:$inKey";
        }
    }
}
check('the texts for parents exist in all six languages', $inMissing === []);

$inMarks = ['de' => 'Wunsch nach einer Dolmetschung', 'en' => 'request for an interpreter', 'pl' => 'prośba o tłumacza', 'ru' => 'запрос на переводчика', 'tr' => 'tercüman isteği', 'uk' => 'запит на перекладача'];
$inNotices = [];
foreach ($inMarks as $inLang => $inMark) {
    foreach (['privacy', 'privacy-private'] as $inKind) {
        if (!str_contains((string) file_get_contents($inRoot . "templates/pages/$inKind.$inLang.md"), "**$inMark")) {
            $inNotices[] = "$inKind.$inLang";
        }
    }
}
check('the privacy notice of both operator types names it, in all six languages', $inNotices === []);
