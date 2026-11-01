<?php
// Test fixture copied into a packaged release. The actual host uses Apache .htaccess.
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$public=__DIR__.'/public_html';
if(str_starts_with($path,'/api/v1/')){$_GET['route']=substr($path,8);require $public.'/api/index.php';return true;}
if(in_array($path,['/admin','/admin/','/admin/index.html'],true)){require $public.'/admin/index.php';return true;}
if(in_array($path,['/setup','/setup/','/setup/index.html'],true)){require $public.'/setup/index.php';return true;}
if($path==='/sitemap.xml'){require $public.'/sitemap.php';return true;}
if($path==='/robots.txt'){require $public.'/robots.php';return true;}
if($path==='/' || str_ends_with($path,'.html')){$_GET['page']=ltrim($path,'/');require $public.'/render.php';return true;}
$file=realpath($public.$path);
if($file && str_starts_with($file,realpath($public).DIRECTORY_SEPARATOR) && is_file($file) && !str_starts_with(basename($file),'.') && pathinfo($file,PATHINFO_EXTENSION)!=='php')return false;
http_response_code(404);echo 'Not found';return true;
