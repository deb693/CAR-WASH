<?php
declare(strict_types=1);
require dirname(__DIR__,2).'/app/bootstrap.php';security_headers();
header('Cache-Control: no-store');header('X-Robots-Tag: noindex, nofollow');header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__.'/index.html');
