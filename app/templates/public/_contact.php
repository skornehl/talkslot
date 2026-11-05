<?php
/**
 * Who parents can ask (App\ParentContact). The contact is shown as the teacher or admin wrote it, so it is marked
 * with the default language on pages in another language (screen readers). Without a contact: nothing, or on a
 * dead end ($contactFallback) the way to the operator via the legal notice.
 * @var string $contact @var bool $contactFallback
 */
$contactText = (string) ($contact ?? '');
?>
<?php if ($contactText !== ''): $parts = explode('{contact}', t('contact'), 2); $own = App\I18n::defaultLanguage(); ?>
  <p class="contact" data-contact><?= e($parts[0]) ?><?= $own !== App\I18n::lang() ? '<span lang="' . e($own) . '">' . e($contactText) . '</span>' : e($contactText) ?><?= e($parts[1] ?? '') ?></p>
<?php elseif ($contactFallback ?? false): ?>
  <p class="contact" data-contact-none><?= str_replace('{imprint}', '<a href="' . e(url('/impressum')) . '">' . e(t('footer.imprint')) . '</a>', e(t('contact.none'))) ?></p>
<?php endif; ?>
