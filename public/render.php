<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
$requested='/'.ltrim((string)($_GET['page']??''),'/');
try {render_website($requested);}
catch(Throwable $error){
    error_log('Website render unavailable: '.get_class($error));http_response_code(503);security_headers();header('Content-Type: text/html; charset=utf-8');header('X-Robots-Tag: noindex');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Temporarily unavailable | SteamRex</title><meta name="robots" content="noindex"><link rel="stylesheet" href="/assets/css/site.css"></head><body><main class="wrap section"><h1>We will be back shortly.</h1><p>The website is temporarily unavailable. Please try again shortly.</p></main></body></html>';
}
