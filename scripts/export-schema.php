<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
$db=database();$output="-- SteamRex installation schema. Import into an empty MySQL 8 database.\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n";
foreach(rows($db,'SHOW TABLES') as $item){$table=array_values($item)[0];if(!preg_match('/^[a-z_]+$/D',$table))throw new RuntimeException('Unexpected table.');$definition=$db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];$definition=preg_replace('/ AUTO_INCREMENT=\d+/','',$definition);$output.=$definition.";\n\n";}
$output.="SET FOREIGN_KEY_CHECKS=1;\n";file_put_contents(PROJECT_ROOT.'/database/install.sql',$output);echo "Complete empty installation schema generated. No customer data or credentials included.\n";
