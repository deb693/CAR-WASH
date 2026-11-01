<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
if(!in_array(config('DB_DATABASE'),['carcare_test','carcare_restore_test'],true))exit(1);
$db=database();$action=$argv[1]??'';
if($action==='worker'){echo json_encode_safe(process_notifications($db,(int)($argv[2]??100)));}
elseif($action==='past')execute($db,'UPDATE bookings SET starts_at = DATE_SUB(UTC_TIMESTAMP(),INTERVAL 2 HOUR),ends_at = DATE_SUB(UTC_TIMESTAMP(),INTERVAL 1 HOUR) WHERE id = ?',[(int)$argv[2]]);
elseif($action==='empty-restore'){
    if(config('DB_DATABASE')!=='carcare_restore_test')exit(1);$db->exec('SET FOREIGN_KEY_CHECKS=0');foreach(rows($db,'SHOW TABLES') as $item){$table=array_values($item)[0];if(!preg_match('/^[a-z_]+$/D',$table))exit(1);$db->exec("DROP TABLE `$table`");}$db->exec('SET FOREIGN_KEY_CHECKS=1');echo 'Empty isolated restore database.';
}elseif($action==='counts'){
    $counts=[];foreach(rows($db,'SHOW TABLES') as $item){$table=array_values($item)[0];if(!preg_match('/^[a-z_]+$/D',$table))exit(1);$counts[$table]=(int)row($db,"SELECT COUNT(*) AS n FROM `$table`")['n'];}echo json_encode_safe($counts);
}elseif($action==='erasure-check'){
    $id=(int)$argv[2];$record=row($db,'SELECT * FROM bookings WHERE id = ?',[$id]);$customer=json_decode($record['customer_json'],true);$selection=json_decode($record['selection_json'],true);
    if($record['address']!=='' || $record['postcode']!=='' || $record['message']!=='' || $customer['email']!=='' || $customer['phone']!=='' || isset($selection['postcode']))throw new RuntimeException('Personal request data survived erasure.');
    if(row($db,"SELECT id FROM request_history WHERE entity_type = 'booking' AND entity_id = ? AND reason != 'Personal details removed'",[$id]))throw new RuntimeException('Free-text history survived erasure.');
    foreach(['notifications','admin_notes'] as $table)if(row($db,"SELECT id FROM $table WHERE entity_type = 'booking' AND entity_id = ?",[$id]))throw new RuntimeException('Private request payload survived erasure.');
    echo 'Erased contact, address, nested postcode, free-text history, notes and mail.';
}elseif($action==='import-install'){
    if(config('DB_DATABASE')!=='carcare_restore_test' || rows($db,'SHOW TABLES'))exit(1);
    foreach(explode(';',file_get_contents($argv[2])) as $statement)if(trim($statement))$db->exec($statement);
    echo 'Fresh installation SQL imported into the isolated empty database.';
}elseif($action==='production-preview'){
    $_SERVER['HTTPS']='on';$_SERVER['HTTP_HOST']='steamrex.example';$_SERVER['REQUEST_URI']='/';
    set_setting($db,'site_url','https://steamrex.example');set_setting($db,'demo_mode',false);set_setting($db,'search_indexing',true);render_website('/');
}else exit(1);
