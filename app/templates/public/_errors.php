<?php /** @var array<int|string, string> $errors a string key is the id of the field: the message links to it */ ?>
<?php if (!empty($errors)): ?>
<div class="alert alert-error" role="alert" id="form-errors" tabindex="-1">
  <p><strong><?= e(t('err.title')) ?></strong></p>
  <ul><?php foreach ($errors as $field => $err): ?><li><?php if (is_string($field)): ?><a href="#<?= e($field) ?>"><?= e($err) ?></a><?php else: ?><?= e($err) ?><?php endif; ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>
