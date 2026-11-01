<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';header('Content-Type: text/plain; charset=utf-8');security_headers();
try{
    $db=database();$index=!demo_mode() && config('APP_ENV','local')==='production' && setting($db,'search_indexing',false);
    echo $index?"User-agent: *\nAllow: /\nDisallow: /admin/\nDisallow: /api/\nDisallow: /book.html\nSitemap: ".rtrim((string)setting($db,'site_url',''),'/')."/sitemap.xml\n":"User-agent: *\nDisallow: /\n";
}catch(Throwable){echo "User-agent: *\nDisallow: /\n";}
