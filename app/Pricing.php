<?php
declare(strict_types=1);

function calculate_quote(PDO $db, array $input): array
{
    $modelId = text_field($input, 'vehicle_model_id', 64);
    $model = row($db, 'SELECT m.* FROM vehicle_models m JOIN vehicle_makes v ON v.id = m.make_id JOIN vehicle_classes c ON c.id = m.class_id WHERE m.id = ? AND m.active = 1 AND m.deleted_at IS NULL AND v.active = 1 AND v.deleted_at IS NULL AND c.active = 1 AND c.deleted_at IS NULL', [$modelId]);
    if (!$model) fail(422, 'unknown_vehicle', 'Please choose a listed vehicle or send an enquiry.');
    $serviceId = text_field($input, 'service_id', 64);
    $service = row($db, 'SELECT * FROM services WHERE id = ? AND active = 1 AND deleted_at IS NULL', [$serviceId]);
    if (!$service) fail(422, 'service_unavailable', 'This service is currently unavailable.');
    $packages = rows($db, 'SELECT * FROM packages WHERE service_id = ? AND class_id = ? AND active = 1 AND deleted_at IS NULL ORDER BY tier', [$serviceId, $model['class_id']]);
    if (count($packages) !== 3 || array_column($packages, 'tier') !== [1, 2, 3]) {
        fail(409, 'packages_unavailable', 'Packages for this vehicle are being updated. Please send an enquiry.');
    }
    $packageId = text_field($input, 'package_id', 64);
    $package = null;
    foreach ($packages as $candidate) if ($candidate['id'] === $packageId) $package = $candidate;
    if (!$package) fail(422, 'invalid_package', 'Choose a package for your selected service and vehicle.');
    $area = coverage($db, text_field($input, 'postcode', 20));
    if (!$area['supported']) fail(422, 'outside_service_area', 'We do not currently cover this postcode. You can send an enquiry instead.');
    $ids = $input['add_on_ids'] ?? [];
    if (!is_array($ids) || count($ids) > 100 || count(array_filter($ids, 'is_string')) !== count($ids)) {
        fail(422, 'invalid_add_ons', 'Choose valid extras.');
    }
    $ids = array_values(array_unique($ids));
    sort($ids);
    $override = row($db, 'SELECT price_minor FROM package_price_overrides WHERE package_id = ? AND model_id = ? AND deleted_at IS NULL', [$packageId, $modelId]);
    $amount = (int)($override['price_minor'] ?? $package['price_minor']);
    $duration = (int)$package['duration_minutes'];
    $lineItems = [['label' => $package['name'], 'amount_minor' => $amount]];
    $extras = [];
    foreach ($ids as $id) {
        $extra = row($db, 'SELECT a.* FROM add_ons a JOIN package_add_ons e ON e.add_on_id = a.id WHERE e.package_id = ? AND a.id = ? AND a.active = 1 AND a.deleted_at IS NULL', [$packageId, $id]);
        if (!$extra) fail(422, 'invalid_add_ons', 'An extra is not available for this package.');
        $classPrice=row($db,'SELECT price_minor FROM add_on_price_overrides WHERE add_on_id = ? AND class_id = ? AND deleted_at IS NULL',[$id,$model['class_id']]);
        if($classPrice)$extra['price_minor']=(int)$classPrice['price_minor'];
        $amount += (int)$extra['price_minor'];
        $duration += (int)$extra['duration_minutes'];
        $lineItems[] = ['label' => $extra['name'], 'amount_minor' => (int)$extra['price_minor']];
        $extras[] = $extra;
    }
    $surcharge = (int)$area['area']['surcharge_minor'];
    if ($surcharge) { $amount += $surcharge; $lineItems[] = ['label' => 'Travel surcharge', 'amount_minor' => $surcharge]; }
    $subtotal=$amount;$rate=(int)setting($db,'tax_rate_basis_points',2500);$taxMode=(string)setting($db,'tax_mode','inclusive');
    $tax=$taxMode==='exclusive'?intdiv($amount*$rate+5000,10000):($taxMode==='inclusive'?intdiv($amount*$rate+intdiv(10000+$rate,2),10000+$rate):0);
    if($taxMode==='exclusive' && $tax){$amount+=$tax;$lineItems[]=['label'=>(string)setting($db,'tax_label','VAT'),'amount_minor'=>$tax];}
    $selection = ['service_id' => $serviceId, 'vehicle_model_id' => $modelId, 'package_id' => $packageId, 'add_on_ids' => $ids, 'postcode' => $area['postcode']];
    $snapshot = [
        'service' => $service['name'], 'vehicle' => $model['name'], 'class_id' => $model['class_id'],
        'package' => $package['name'], 'inclusions' => json_decode($package['inclusions'], true),
        'line_items' => $lineItems, 'total_minor' => $amount, 'currency' => setting($db,'currency','DKK'),
        'duration_minutes' => $duration, 'subtotal_minor'=>$subtotal, 'tax_minor'=>$tax, 'tax_mode'=>$taxMode,
        'tax_note' => demo_mode() ? 'Sample prices for local testing.' : ($taxMode==='inclusive'?'Includes ':($taxMode==='exclusive'?'Includes added ':'No ')).(string)setting($db,'tax_label','VAT').($taxMode==='none'?' charged.':'.'),
    ];
    // Snapshot includes every customer-visible commercial input; a relevant edit invalidates old quotes.
    $fingerprint = hash('sha256', json_encode_safe([$selection, $snapshot, $package['updated_at'], $extras, $area]));
    return ['selection' => $selection, 'snapshot' => $snapshot, 'fingerprint' => $fingerprint];
}

function create_quote(PDO $db, array $input): array
{
    $quote = calculate_quote($db, $input);
    $id = bin2hex(random_bytes(24));
    $expires = gmdate('Y-m-d H:i:s', time() + (int)setting($db,'quote_minutes',15)*60);
    execute($db, 'INSERT INTO quotes (id, selection_json, snapshot_json, fingerprint, expires_at) VALUES (?, ?, ?, ?, ?)',
        [$id, json_encode_safe($quote['selection']), json_encode_safe($quote['snapshot']), $quote['fingerprint'], $expires]);
    return ['quote_id' => $id, 'expires_at' => str_replace(' ', 'T', $expires) . 'Z', ...$quote['snapshot']];
}

function valid_quote(PDO $db, string $id): array
{
    $saved = row($db, 'SELECT * FROM quotes WHERE id = ?', [$id]);
    if (!$saved || strtotime($saved['expires_at'] . ' UTC') <= time()) fail(409, 'quote_expired', 'Your quote has expired. Refresh your price before continuing.');
    try{$current = calculate_quote($db, json_decode($saved['selection_json'], true, 512, JSON_THROW_ON_ERROR));}
    catch(ApiError $error){if(in_array($error->errorCode,['unknown_vehicle','service_unavailable','packages_unavailable','invalid_package','invalid_add_ons','outside_service_area'],true))fail(409,'quote_changed','Service availability or pricing changed. Refresh your selection before continuing.');throw $error;}
    if (!hash_equals($saved['fingerprint'], $current['fingerprint'])) fail(409, 'quote_changed', 'The price or service details have changed. Please review a fresh quote.');
    return ['id' => $id, ...$current];
}

