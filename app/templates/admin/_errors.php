<?php if (!empty($errors)): ?>
<div class="alert alert-error" role="alert" id="form-errors" tabindex="-1">
  <ul><?php foreach ($errors as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul>
</div>
<?php endif; ?>
