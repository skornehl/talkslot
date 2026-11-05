<?php
/**
 * Front controller. All requests (except existing files) end up here.
 *
 * app_root.php (uploaded by the deployment, template bin/app-root.php) names the directory of the
 * active version outside the web root; locally app/ sits right next to public/.
 */
declare(strict_types=1);

define('PUBLIC_ROOT', __DIR__);
$appRoot = is_file(__DIR__ . '/app_root.php') ? require __DIR__ . '/app_root.php' : dirname(__DIR__);
require $appRoot . '/app/bootstrap.php';
App\Platform::enforce(true); // needs the database: also pdo_mysql

App\App::run();
