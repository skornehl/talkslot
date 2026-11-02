<?php
/**
 * Front-Controller. Alle Anfragen (außer vorhandenen Dateien) landen hier.
 *
 * app_root.php (vom Deployment hochgeladen, Vorlage bin/app-root.php) nennt das Verzeichnis der
 * aktiven Version außerhalb des Webroots; lokal liegt app/ direkt neben public/.
 */
declare(strict_types=1);

define('PUBLIC_ROOT', __DIR__);
$appRoot = is_file(__DIR__ . '/app_root.php') ? require __DIR__ . '/app_root.php' : dirname(__DIR__);
require $appRoot . '/app/bootstrap.php';

App\App::run();
