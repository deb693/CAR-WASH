<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/bootstrap.php';
if (!demo_mode()) { echo "Live catalogue preserved. Demo seed not run.\n"; exit; }
$db = database();
if (setting($db,'demo_seeded',false)) { echo "Existing demo catalogue preserved; use admin to make changes.\n"; exit; }
$db->beginTransaction();
try {
    $services = [
        ['full', 'Complete clean', 'A considered clean, inside and out.', 1],
        ['interior', 'Interior care', 'A fresh start for the space you spend time in.', 2],
        ['exterior', 'Exterior care', 'Bring a little brilliance back to every journey.', 3],
    ];
    foreach ($services as $service) execute($db, 'INSERT IGNORE INTO services (id,name,summary,sort_order) VALUES (?,?,?,?)', $service);
    foreach ([['small','Small / hatchback',1],['medium','Medium / saloon',2],['large','Large / SUV',3]] as $class) execute($db, 'INSERT IGNORE INTO vehicle_classes (id,name,sort_order) VALUES (?,?,?)', $class);
    foreach ([['bmw','BMW'],['tesla','Tesla'],['toyota','Toyota'],['volkswagen','Volkswagen']] as $make) execute($db, 'INSERT IGNORE INTO vehicle_makes (id,name) VALUES (?,?)', $make);
    $models = [['bmw-3','bmw','medium','3 Series'],['bmw-x5','bmw','large','X5'],['tesla-3','tesla','medium','Model 3'],['tesla-y','tesla','large','Model Y'],['toyota-yaris','toyota','small','Yaris'],['toyota-corolla','toyota','medium','Corolla'],['vw-golf','volkswagen','small','Golf'],['vw-tiguan','volkswagen','large','Tiguan']];
    foreach ($models as $model) execute($db, 'INSERT IGNORE INTO vehicle_models (id,make_id,class_id,name) VALUES (?,?,?,?)', $model);
    $tiers = [1 => ['Essential','The everyday refresh.', ['Careful surface clean','Glass cleaned','Finishing inspection']], 2 => ['Signature','A little more attention. A lot more feel-good.', ['Everything in Essential','Detailed crevice cleaning','Surface conditioning','Finishing inspection']], 3 => ['Complete','Our most considered clean, from every angle.', ['Everything in Signature','Deeper treatment of high-use areas','Protective finishing treatment','Extended finishing inspection']]];
    foreach ($services as $service) foreach (['small'=>0,'medium'=>1000,'large'=>2000] as $class=>$extra) foreach ($tiers as $tier=>$details) {
        $price = [1=>4900,2=>7900,3=>11900][$tier] + $extra + ($service[0] === 'full' ? 2000 : 0);
        $duration = [1=>45,2=>75,3=>105][$tier] + ($service[0] === 'full' ? 30 : 0);
        execute($db, 'INSERT IGNORE INTO packages (id,service_id,class_id,tier,name,summary,price_minor,duration_minutes,inclusions) VALUES (?,?,?,?,?,?,?,?,?)',
            [$service[0].'-'.$class.'-'.$tier,$service[0],$class,$tier,$details[0],$details[1],$price,$duration,json_encode_safe($details[2])]);
    }
    foreach ([['pet-hair','Extra pet-hair removal',1500,20],['seat-care','Seat spot treatment',2500,30]] as $extra) execute($db, 'INSERT IGNORE INTO add_ons (id,name,price_minor,duration_minutes) VALUES (?,?,?,?)', $extra);
    foreach (rows($db, "SELECT id FROM packages WHERE service_id != 'exterior'") as $package) foreach (['pet-hair','seat-care'] as $extra) execute($db, 'INSERT IGNORE INTO package_add_ons (package_id,add_on_id) VALUES (?,?)', [$package['id'],$extra]);
    foreach (['1000','2000','3000'] as $postcode) execute($db, 'INSERT IGNORE INTO service_areas (postcode,name) VALUES (?,?)', [$postcode,'Development test area']);
    execute($db,"UPDATE services SET slug = CASE WHEN id = 'full' THEN 'complete' ELSE id END WHERE slug IS NULL");
    set_setting($db,'demo_seeded',true);
    $db->commit();
    echo "Sample catalogue ready. Test postcodes: 1000, 2000, 3000. Prices are illustrative DKK amounts.\n";
} catch (Throwable $e) { $db->rollBack(); throw $e; }
