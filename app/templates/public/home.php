<?php /** @var list<string> $errors @var string $code @var string $contact who parents can ask, after a wrong code */ ?>
<h1><?= e(t('home.title')) ?></h1>
<p><?= e(t('home.text')) ?></p>
<?php require __DIR__ . '/_errors.php'; ?>
<?php require __DIR__ . '/_code_form.php'; ?>
<?php require __DIR__ . '/_my_appointments.php'; ?>
<?php if (!empty($errors)): $contactFallback = true; require __DIR__ . '/_contact.php'; endif; ?>
