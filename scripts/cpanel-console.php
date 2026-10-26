<?php
declare(strict_types=1);
ini_set('display_errors','0');

// Cron uses this stable wrapper so it shares the deployment lock with HTTP.
// Optional first argument: --base=/absolute/private/application/directory.
$arguments=$argv;
array_shift($arguments);
$base=rtrim((string)getenv('HOME'),'/').'/apps/corpsakademie';
if (isset($arguments[0]) && str_starts_with($arguments[0],'--base=')) { $base=substr(array_shift($arguments),7); }
if (!str_starts_with($base,'/') || !is_dir($base.'/shared')) { fwrite(STDERR,"Private Installation fehlt.\n"); exit(1); }
$base=realpath($base);
$lock=@fopen($base.'/shared/runtime.lock','r+');
if ($lock===false || !flock($lock,LOCK_SH|LOCK_NB) || is_file($base.'/shared/maintenance.flag')) { fwrite(STDERR,"Bereitstellung/Wartung aktiv; CLI-Lauf übersprungen.\n"); exit(75); }
clearstatcache(true);
$release=realpath($base.'/current');
if ($release===false || !str_starts_with($release,$base.'/releases/')) { fwrite(STDERR,"Keine aktive Veröffentlichung.\n"); exit(1); }
$argv=[$release.'/bin/console',...$arguments];
$argc=count($argv);
$_SERVER['argv']=$argv;
$_SERVER['argc']=$argc;
// The OS releases the shared lock on normal completion and on exit().
require $release.'/bin/console';
