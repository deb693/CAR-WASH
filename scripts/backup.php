<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
$directory=PROJECT_ROOT.'/storage/backups';if(!is_dir($directory))mkdir($directory,0700,true);
$file=$argv[1]??$directory.'/steamrex-'.gmdate('Ymd-His').'-'.bin2hex(random_bytes(4)).'.jsonl.gz';
try{$counts=create_backup(database(),$file);echo json_encode_safe(['file'=>$file,'tables'=>$counts])."\n";}
catch(Throwable $error){fwrite(STDERR,$error->getMessage()."\n");exit(1);}
