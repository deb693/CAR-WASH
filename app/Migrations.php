<?php
declare(strict_types=1);

function migrate_database(PDO $db): void
{
    foreach ([PROJECT_ROOT . '/database/schema.sql', PROJECT_ROOT . '/database/migrations/002-admin.sql', PROJECT_ROOT . '/database/migrations/003-homepage.sql'] as $file) {
        foreach (explode(';', file_get_contents($file)) as $statement) if (trim($statement)) $db->exec($statement);
    }
    $additions = [
        'services' => ['slug'=>"VARCHAR(100) NULL", 'description'=>"TEXT NULL", 'exclusions'=>"TEXT NULL", 'image_path'=>"VARCHAR(250) NOT NULL DEFAULT ''"],
        'packages' => ['exclusions'=>"TEXT NULL", 'badge'=>"VARCHAR(80) NOT NULL DEFAULT ''"],
        'add_ons' => ['description'=>"TEXT NULL"],
        'blocked_dates' => ['starts_at'=>"TIME NULL", 'ends_at'=>"TIME NULL"],
        'bookings' => ['version'=>"INT NOT NULL DEFAULT 1", 'source_enquiry_id'=>"BIGINT NULL", 'proposed_start'=>"DATETIME NULL", 'prior_status'=>"VARCHAR(40) NULL", 'deleted_at'=>'DATETIME NULL'],
        'enquiries' => ['version'=>"INT NOT NULL DEFAULT 1", 'converted_booking_id'=>"BIGINT NULL", 'deleted_at'=>'DATETIME NULL'],
    ];
    foreach (['services','vehicle_classes','vehicle_makes','vehicle_models','packages','add_ons','service_areas','blocked_dates','package_price_overrides'] as $table) {
        $additions[$table] = [...($additions[$table] ?? []), 'deleted_at'=>'DATETIME NULL'];
        if (!in_array($table,['bookings','enquiries'],true)) $additions[$table]['version'] = 'INT NOT NULL DEFAULT 1';
    }
    foreach (['vehicle_classes','vehicle_makes'] as $table) $additions[$table]['active'] = 'BOOLEAN NOT NULL DEFAULT TRUE';
    foreach ($additions as $table=>$columns) foreach ($columns as $column=>$definition) {
        if (!row($db,'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',[$table,$column])) {
            $db->exec("ALTER TABLE `$table` ADD `$column` $definition");
        }
    }
    if (!row($db,"SHOW INDEX FROM services WHERE Key_name = 'service_slug'")) $db->exec('CREATE UNIQUE INDEX service_slug ON services (slug)');
    if (!row($db,"SHOW INDEX FROM bookings WHERE Key_name = 'source_enquiry'")) $db->exec('CREATE UNIQUE INDEX source_enquiry ON bookings (source_enquiry_id)');
    execute($db,"UPDATE services SET slug = CASE WHEN id = 'full' THEN 'complete' ELSE id END WHERE slug IS NULL");
}
