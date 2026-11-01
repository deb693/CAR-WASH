<?php
declare(strict_types=1);
// Development only. Never upload this router or the repository root to public_html.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
$root = realpath(__DIR__ . '/public');
if (str_starts_with($path, '/api/v1/')) {
    $_GET['route'] = substr($path, strlen('/api/v1/'));
    require __DIR__ . '/public/api/index.php';
    return true;
}
if (str_starts_with($path, '/api/')) { http_response_code(404); return true; }
if ($path === '/admin/' || $path === '/admin/index.html' || $path === '/admin') {
    require __DIR__.'/app/bootstrap.php'; security_headers(); header('Cache-Control: no-store');header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__.'/public/admin/index.html');return true;
}
if ($path === '/setup' || $path === '/setup/' || $path === '/setup/index.html') {require __DIR__.'/public/setup/index.php';return true;}
if ($path === '/sitemap.xml' || $path === '/robots.txt') {require __DIR__.'/public/'.($path === '/sitemap.xml'?'sitemap.php':'robots.php');return true;}
if ($path === '/' || str_ends_with($path,'.html')) {$_GET['page']=ltrim($path,'/');require __DIR__.'/public/render.php';return true;}

$candidate = realpath($root . ($path === '/' ? '/index.html' : rawurldecode($path)));
if ($candidate && str_starts_with($candidate, $root . DIRECTORY_SEPARATOR) && is_file($candidate) && !str_starts_with(basename($candidate), '.') && pathinfo($candidate, PATHINFO_EXTENSION) !== 'php') {
    $types = ['html' => 'text/html; charset=utf-8', 'css' => 'text/css', 'js' => 'text/javascript', 'svg' => 'image/svg+xml', 'jpg' => 'image/jpeg', 'png' => 'image/png', 'xml' => 'application/xml', 'txt' => 'text/plain'];
    header('Content-Type: ' . ($types[pathinfo($candidate, PATHINFO_EXTENSION)] ?? 'application/octet-stream'));
    readfile($candidate);
    return true;
}
http_response_code(404);
header('Content-Type: text/html; charset=utf-8');
readfile(__DIR__ . '/public/404.html');
return true;

