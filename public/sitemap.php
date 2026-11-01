<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
header('Content-Type: application/xml; charset=utf-8');security_headers();
try{
    $db=database();$base=rtrim((string)setting($db,'site_url',''),'/');
    echo '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
    foreach(website_routes($db) as $route){echo '<url><loc>'.html_escape($base.$route['path']).'</loc>'.($route['modified']?'<lastmod>'.gmdate(DateTimeInterface::ATOM,strtotime($route['modified'].' UTC')).'</lastmod>':'').'</url>';}
    echo '</urlset>';
}catch(Throwable){http_response_code(503);echo '<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"/>';}
