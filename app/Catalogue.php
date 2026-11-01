<?php
declare(strict_types=1);

function catalogue(PDO $db): array
{
    $packages = rows($db, 'SELECT p.* FROM packages p JOIN services s ON s.id = p.service_id JOIN vehicle_classes c ON c.id = p.class_id WHERE p.active = 1 AND p.deleted_at IS NULL AND s.active = 1 AND s.deleted_at IS NULL AND c.active = 1 AND c.deleted_at IS NULL ORDER BY p.tier');
    foreach ($packages as &$package) {
        foreach (['tier', 'price_minor', 'duration_minutes'] as $field) $package[$field] = (int)$package[$field];
        $package['inclusions'] = json_decode($package['inclusions'], true, 512, JSON_THROW_ON_ERROR);
    }
    unset($package);
    $extras = rows($db, 'SELECT * FROM add_ons WHERE active = 1 AND deleted_at IS NULL ORDER BY id');
    foreach ($extras as &$extra) {
        $extra['price_minor'] = (int)$extra['price_minor'];
        $extra['duration_minutes'] = (int)$extra['duration_minutes'];
    }
    return [
        'business_name' => setting($db,'business_name','SteamRex'),
        'currency' => setting($db,'currency','DKK'),
        'timezone' => setting($db,'timezone','Europe/Copenhagen'),
        'demo_mode' => demo_mode(),
        'booking_enabled' => (bool)setting($db,'booking_enabled',true),
        'quote_minutes' => (int)setting($db,'quote_minutes',15),
        'notification_mode' => setting($db,'mail_transport','log'),
        'add_on_price_overrides' => rows($db,'SELECT add_on_id,class_id,price_minor FROM add_on_price_overrides WHERE deleted_at IS NULL'),
        'services' => rows($db, 'SELECT id, name, slug, summary, description FROM services WHERE active = 1 AND deleted_at IS NULL ORDER BY sort_order'),
        'classes' => rows($db, 'SELECT id, name FROM vehicle_classes WHERE active = 1 AND deleted_at IS NULL ORDER BY sort_order'),
        'makes' => rows($db, 'SELECT id, name FROM vehicle_makes WHERE active = 1 AND deleted_at IS NULL ORDER BY name'),
        'models' => rows($db, 'SELECT m.id,m.make_id,m.class_id,m.name FROM vehicle_models m JOIN vehicle_makes v ON v.id=m.make_id JOIN vehicle_classes c ON c.id=m.class_id WHERE m.active=1 AND m.deleted_at IS NULL AND v.active=1 AND v.deleted_at IS NULL AND c.active=1 AND c.deleted_at IS NULL ORDER BY m.name'),
        'packages' => $packages,
        'price_overrides' => rows($db, 'SELECT package_id, model_id, price_minor FROM package_price_overrides WHERE deleted_at IS NULL'),
        'add_ons' => $extras,
        'eligibility' => rows($db, 'SELECT package_id, add_on_id FROM package_add_ons'),
    ];
}

function normalize_postcode(string $postcode): string
{
    $normalized = strtoupper(preg_replace('/\s+/', '', trim($postcode)) ?? '');
    if (!preg_match('/^[A-Z0-9-]{2,12}$/', $normalized)) {
        fail(422, 'validation_error', 'Enter a valid postcode.', ['postcode' => 'Use 2–12 letters or numbers.']);
    }
    return $normalized;
}

function coverage(PDO $db, string $postcode): array
{
    $normalized = normalize_postcode($postcode);
    $area = row($db, 'SELECT id, name, surcharge_minor FROM service_areas WHERE postcode = ? AND active = 1 AND deleted_at IS NULL', [$normalized]);
    return ['supported' => $area !== null, 'postcode' => $normalized, 'area' => $area];
}

