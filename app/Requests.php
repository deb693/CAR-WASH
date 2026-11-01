<?php
declare(strict_types=1);

function rate_limit(PDO $db, string $operation, int $limit): void
{
    $key = config('APP_KEY', '');
    if (strlen((string)$key) < 32) fail(503, 'configuration_required', 'The service needs its private configuration.');
    $bucket = hash_hmac('sha256', ($_SERVER['REMOTE_ADDR'] ?? 'cli') . '|' . $operation . '|' . gmdate('Y-m-d-H'), (string)$key);
    execute($db, 'INSERT INTO rate_limits (bucket, hits, expires_at) VALUES (?, 1, ?) ON DUPLICATE KEY UPDATE hits = hits + 1', [$bucket, gmdate('Y-m-d H:i:s', time() + 7200)]);
    if ((int)row($db, 'SELECT hits FROM rate_limits WHERE bucket = ?', [$bucket])['hits'] > $limit) fail(429, 'rate_limited', 'Too many attempts. Please try again later.');
}

function customer_details(array $input): array
{
    $customer = $input['customer'] ?? null;
    if (!is_array($customer)) fail(422, 'validation_error', 'Enter your contact details.');
    $name = text_field($customer, 'full_name', 100);
    $email = text_field($customer, 'email', 254);
    $phone = text_field($customer, 'phone', 30);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail(422, 'validation_error', 'Enter a valid email address.', ['email' => 'Enter a valid email address.']);
    if (!preg_match('/^[+0-9 ().-]{7,30}$/', $phone)) fail(422, 'validation_error', 'Enter a valid phone number.', ['phone' => 'Enter a valid phone number.']);
    if (($input['consent'] ?? false) !== true) fail(422, 'consent_required', 'Please accept the terms and privacy notice.');
    if (($input['website'] ?? '') !== '') fail(422, 'validation_error', 'Unable to submit this request.');
    return ['full_name' => $name, 'email' => $email, 'phone' => $phone];
}

function create_request(PDO $db, array $input, string $intent, string $key): array
{
    if (!preg_match('/^[A-Za-z0-9_-]{16,100}$/', $key)) fail(422, 'idempotency_required', 'A submission key is required. Please refresh the page.');
    if (!(bool)setting($db,'booking_enabled',true)) fail(503,'booking_closed','Online requests are currently closed. Please contact the team.');
    if(config('APP_ENV','local')==='production' && (demo_mode() || release_readiness($db)['blocking']))fail(503,'launch_configuration_required','The booking service is being configured. Please contact the team.');
    $hash = hash('sha256', json_encode_safe($input));
    $customer = customer_details($input);
    $message = text_field($input, 'message', 2000, false);
    $db->beginTransaction();
    try {
        execute($db, 'INSERT IGNORE INTO idempotency (operation, request_key, payload_hash) VALUES (?, ?, ?)', [$intent, $key, $hash]);
        $saved = row($db, 'SELECT * FROM idempotency WHERE operation = ? AND request_key = ? FOR UPDATE', [$intent, $key]);
        if (!hash_equals($saved['payload_hash'], $hash)) fail(409, 'idempotency_conflict', 'This submission key has already been used with different details.');
        if ($saved['response_json']) { $db->commit(); return json_decode($saved['response_json'], true, 512, JSON_THROW_ON_ERROR); }
        $reference = 'SR-' . strtoupper(bin2hex(random_bytes(8)));
        if ($intent === 'pay_on_completion') {
            $quote = valid_quote($db, text_field($input, 'quote_id', 100));
            $address = text_field($input, 'address', 500);
            [$start, $end] = allocate_slot($db, $quote, text_field($input, 'starts_at', 60));
            execute($db, 'INSERT INTO bookings (reference, status, intent, customer_json, address, postcode, selection_json, snapshot_json, starts_at, ends_at, timezone, message, consent_version, consent_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                [$reference, 'pending_confirmation', $intent, json_encode_safe($customer), $address, $quote['selection']['postcode'], json_encode_safe($quote['selection']), json_encode_safe($quote['snapshot']), $start, $end, setting($db,'timezone','Europe/Copenhagen'), $message, 'terms:'.setting($db,'terms_version','1').';privacy:'.setting($db,'privacy_version','1')]);
            $status = 'pending_confirmation';
        } else {
            $service = text_field($input, 'service_id', 64);
            if (!row($db, 'SELECT id FROM services WHERE id = ? AND active = 1 AND deleted_at IS NULL', [$service])) fail(422, 'invalid_service', 'Choose an available service.');
            $context = ['service_id' => $service, 'vehicle' => text_field($input, 'vehicle', 200), 'postcode' => text_field($input, 'postcode', 20, false), 'address' => text_field($input, 'address', 500, false)];
            if ($message === '') fail(422, 'validation_error', 'Tell us what you would like help with.', ['message' => 'Enter a short message.']);
            execute($db, 'INSERT INTO enquiries (reference, status, customer_json, context_json, message, consent_version, consent_at) VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())',
                [$reference, 'new_enquiry', json_encode_safe($customer), json_encode_safe($context), $message, 'terms:'.setting($db,'terms_version','1').';privacy:'.setting($db,'privacy_version','1')]);
            $status = 'new_enquiry';
        }
        $entityType=$intent==='pay_on_completion'?'booking':'enquiry';
        $entityId=(int)$db->lastInsertId();
        request_history($db,$entityType,$entityId,null,$status,null,'Customer submitted request');
        queue_request_notifications($db,$entityType,$entityId,'received',1);
        $response = ['reference' => $reference, 'status' => $status, 'intent' => $intent, 'notification_status' => 'queued', 'demo_mode' => demo_mode()];
        execute($db, 'UPDATE idempotency SET response_json = ? WHERE operation = ? AND request_key = ?', [json_encode_safe($response), $intent, $key]);
        $db->commit();
        return $response;
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

