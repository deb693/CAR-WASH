<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
$options=getopt('',['file:','confirm-database:']);
if(empty($options['file']) || ($options['confirm-database']??'')!==config('DB_DATABASE')){fwrite(STDERR,"Supply --file=backup.jsonl.gz and --confirm-database=the-configured-empty-database.\n");exit(1);}
try{echo json_encode_safe(['restored'=>restore_backup(database(),$options['file'])])."\n";}
catch(Throwable $error){fwrite(STDERR,$error->getMessage()."\n");exit(1);}
