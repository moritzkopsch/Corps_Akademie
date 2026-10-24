<?php
declare(strict_types=1);

use Corpsakademie\Container;
use Corpsakademie\Http\Application;
use Corpsakademie\Infrastructure\Config;
use Symfony\Component\HttpFoundation\Request;

ini_set('display_errors','0');
ini_set('log_errors','1');
$root = dirname(__DIR__);
if (!is_file($root.'/vendor/autoload.php')) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    exit('Die Anwendung ist noch nicht installiert.');
}
require $root.'/vendor/autoload.php';
try {
    $config = Config::load($root);
    $app = new Application(new Container($config));
} catch (Throwable) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    exit('Die Anwendung ist noch nicht eingerichtet oder vorübergehend nicht verfügbar.');
}
$app->handle(Request::createFromGlobals())->send();
