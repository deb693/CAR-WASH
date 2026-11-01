<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex');

try {
    security_headers();
    $path = $_GET['route'] ?? '';
    if(!is_string($path))fail(400,'invalid_route','Invalid API route.');
    $method = $_SERVER['REQUEST_METHOD'];
    $db = database();
    $data = [];
    $isAdmin=str_starts_with((string)$path,'admin/');
    if ($method !== 'GET') {
        if($isAdmin && $path!=='admin/auth/login'){require_admin($db);require_csrf();}
        if($path==='admin/media' && $method==='POST'){
            $result=admin_dispatch($db,$method,$path,[]);
            echo json_encode_safe(['data'=>$result]);exit;
        }
        if (!str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) fail(415, 'json_required', 'Send a JSON request.');
        $maximum=$isAdmin?262144:16384;
        if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > $maximum) fail(413, 'request_too_large', 'The request is too large.');
        $raw = file_get_contents('php://input', false, null, 0, $maximum+1);
        if (strlen($raw) > $maximum) fail(413, 'request_too_large', 'The request is too large.');
        try { $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
        catch (JsonException) { fail(400, 'invalid_json', 'Invalid JSON body.'); }
        if (!is_array($data) || !str_starts_with(ltrim($raw),'{')) fail(400, 'invalid_json', 'Expected a JSON object.');
        if(!$isAdmin)rate_limit($db, (string)$path, str_contains((string)$path, 'quotes') ? 100 : 30);
    }
    $result = $isAdmin ? admin_dispatch($db,$method,(string)$path,$data) : match ([$method, $path]) {
        ['GET', 'health'] => ['status' => 'ok', 'database' => 'connected', 'demo_mode' => demo_mode()],
        ['GET', 'setup/status'] => setup_status($db),
        ['POST', 'setup/owner'] => setup_owner($db,$data),
        ['GET', 'public/catalogue'] => catalogue($db),
        ['POST', 'public/service-area/check'] => coverage($db, text_field($data, 'postcode', 20)),
        ['POST', 'public/quotes'] => create_quote($db, $data),
        ['GET', 'public/availability'] => ['slots' => available_slots($db, valid_quote($db, (string)($_GET['quote_id'] ?? '')), (string)($_GET['date'] ?? '')), 'timezone' => setting($db,'timezone','Europe/Copenhagen')],
        ['POST', 'public/bookings'] => create_request($db, $data, 'pay_on_completion', $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''),
        ['POST', 'public/enquiries'] => create_request($db, $data, 'send_enquiry', $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ''),
        default => throw new ApiError(404, 'not_found', 'This endpoint does not exist.'),
    };
    if ($method === 'POST' && in_array($path, ['public/bookings', 'public/enquiries'], true)) http_response_code(201);
    echo json_encode_safe(['data' => $result]);
} catch (ApiError $error) {
    http_response_code($error->status);
    echo json_encode_safe(['error' => ['code' => $error->errorCode, 'message' => $error->getMessage(), 'fields' => $error->fields]]);
} catch (Throwable $error) {
    error_log('SteamRex API failure: ' . get_class($error) . ' code=' . $error->getCode());
    http_response_code(503);
    echo json_encode_safe(['error' => ['code' => 'service_unavailable', 'message' => 'The service is temporarily unavailable. Please try again shortly.']]);
}

