<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
$watch=in_array('--watch',$argv,true);
do{
    try{$result=process_notifications(database(),20);if($result['sent'] || $result['failed'])echo json_encode_safe($result)."\n";}
    catch(Throwable $error){fwrite(STDERR,'Notification worker unavailable: '.get_class($error)."\n");if(!$watch)exit(1);}
    if($watch)sleep(5);
}while($watch);
