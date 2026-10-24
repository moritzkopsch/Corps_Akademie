<?php
declare(strict_types=1);

// Router for PHP's local development server only. Never serve the project root.
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI']??'/',PHP_URL_PATH) ?: '/');
$file = realpath(__DIR__.$path);
if ($file!==false && str_starts_with($file,__DIR__.'/assets/') && is_file($file)
    && in_array(strtolower(pathinfo($file,PATHINFO_EXTENSION)),['css','js','svg','png','jpg','webp','ico'],true)) {
    return false;
}
require __DIR__.'/index.php';
