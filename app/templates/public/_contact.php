<?php /** Who parents can ask when they are stuck (App\ParentContact); nothing if no contact is set. @var string $contact */ ?>
<?php if (($contact ?? '') !== ''): ?><p class="contact" data-contact><?= e(t('contact', ['contact' => $contact])) ?></p><?php endif; ?>
