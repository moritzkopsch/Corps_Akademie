<?php
declare(strict_types=1);
ini_set('display_errors','0');
ini_set('log_errors','1');

// CORPSAKADEMIE CPANEL ENTRY
// Generated deployment entry. The placeholder is replaced with a quoted,
// absolute private base path, never with request or environment input.
$deploymentBase=__CORPS_DEPLOYMENT_BASE__;
$maintenance=$deploymentBase.'/shared/maintenance.flag';
$lock=@fopen($deploymentBase.'/shared/runtime.lock','r+');
if ($lock===false || !flock($lock,LOCK_SH|LOCK_NB) || is_file($maintenance)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=UTF-8');
    header('Cache-Control: no-store');
    header('Retry-After: 60');
    exit('Die Website wird gerade aktualisiert. Bitte versuchen Sie es gleich erneut.');
}
try {
    clearstatcache(true);
    $release=realpath($deploymentBase.'/current');
    if ($release===false || !str_starts_with($release,$deploymentBase.'/releases/')) {
        http_response_code(503);
        header('Cache-Control: no-store');
        exit('Die Website ist noch nicht freigeschaltet.');
    }
    require $release.'/public/index.php';
} finally {
    flock($lock,LOCK_UN);
    fclose($lock);
}
