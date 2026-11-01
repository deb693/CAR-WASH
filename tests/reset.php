<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
if(config('DB_DATABASE')!=='carcare_test'){fwrite(STDERR,"Only carcare_test can be reset.\n");exit(1);}
$db=database();migrate_database($db);$db->exec('SET FOREIGN_KEY_CHECKS=0');
foreach(rows($db,'SHOW TABLES') as $item){$table=array_values($item)[0];if(!preg_match('/^[a-z_]+$/D',$table))exit(1);$db->exec("TRUNCATE TABLE `$table`");}
$db->exec('SET FOREIGN_KEY_CHECKS=1');clear_settings_cache($db);initialize_application($db);initialize_content($db);
execute($db,'INSERT INTO admins (name,email,password_hash,role) VALUES (?,?,?,?)',['Test owner','owner@example.test',password_hash('TestOnlySteamRex!2026',PASSWORD_DEFAULT),'super_admin']);
echo "Isolated test database reset.\n";
