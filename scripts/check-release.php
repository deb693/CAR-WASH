<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
$checks=release_readiness(database());$checks['php_version']=PHP_VERSION;$checks['environment']=config('APP_ENV','local');
$checks['extensions']=array_map(fn($name)=>['name'=>$name,'loaded'=>extension_loaded($name)],['pdo_mysql','mbstring','openssl','dom','zlib']);
echo json_encode($checks,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";
if(in_array('--strict',$argv,true) && (!$checks['ready'] || demo_mode()))exit(1);
