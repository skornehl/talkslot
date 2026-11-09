<?php
/**
 * Constants of app/bootstrap.php and public/index.php for PHPStan (tests/phpstan.neon). The bootstrap itself is
 * not loaded: it would read config/config.php and install the app's error handlers in the analyser. The values
 * do not matter, tests/phpstan.neon declares the constants as dynamic.
 */
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('RELEASE', '');
define('DATA_ROOT', APP_ROOT);
define('PUBLIC_ROOT', APP_ROOT . '/public');
