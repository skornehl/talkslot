<?php /** @var list<string> $errors @var string $code */ ?>
<h1><?= e(t('home.title')) ?></h1>
<p><?= e(t('home.text')) ?></p>
<?php require __DIR__ . '/_errors.php'; ?>
<?php require __DIR__ . '/_code_form.php'; ?>
