<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
$db=database();$days=(int)setting($db,'retention_days',365);
execute($db,'DELETE FROM rate_limits WHERE expires_at < UTC_TIMESTAMP()');
execute($db,'DELETE FROM quotes WHERE expires_at < DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 DAY)');
execute($db,'DELETE FROM idempotency WHERE created_at < DATE_SUB(UTC_TIMESTAMP(),INTERVAL 30 DAY)');
$counts=['booking'=>0,'enquiry'=>0];
foreach(['booking'=>'bookings','enquiry'=>'enquiries'] as $type=>$table){
    $states=$type==='booking'?"'completed','cancelled','rejected'":"'enquiry_closed','enquiry_converted'";
    foreach(rows($db,"SELECT id,version FROM $table WHERE deleted_at IS NULL AND status IN ($states) AND created_at < DATE_SUB(UTC_TIMESTAMP(),INTERVAL $days DAY) LIMIT 100") as $record){erase_request($db,['id'=>null],$type,(int)$record['id'],['version'=>$record['version']]);$counts[$type]++;}
}
echo json_encode_safe(['expired_private_data_removed'=>$counts])."\n";
