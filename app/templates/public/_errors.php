<?php /** @var list<string> $errors */ ?>
<?php if (!empty($errors)): ?>
<div class="alert alert-error" role="alert" id="form-errors" tabindex="-1">
  <p><strong><?= e(t('err.title')) ?></strong></p>
  <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>
