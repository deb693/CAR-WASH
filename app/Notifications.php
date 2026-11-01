<?php
declare(strict_types=1);

function mail_capture_directory(): string
{
    return PROJECT_ROOT.'/storage/mail/'.substr(hash('sha256',(string)config('DB_HOST','').'|'.(string)config('DB_DATABASE','')),0,16);
}

function queue_request_notifications(PDO $db,string $type,int $id,string $event,int $version): void
{
    $table=$type==='booking'?'bookings':'enquiries';$record=row($db,"SELECT * FROM $table WHERE id = ?",[$id]);
    if(!$record)return;$customer=json_decode($record['customer_json'],true);
    $name=(string)setting($db,'business_name','SteamRex');
    $status=str_replace('_',' ',$record['status']);
    $intro=$type==='enquiry'?($record['status']==='enquiry_closed'?'Your enquiry has been closed. No appointment has been reserved. Contact us if you need more help.':'Your enquiry is with us. No appointment has been reserved.'):($record['status']==='confirmed'?'Your appointment is confirmed.':($record['status']==='pending_confirmation'?'Your booking request is received and awaits confirmation. It is not a confirmed appointment.':'Your appointment status has changed to '.$status.'.'));
    $body="Hello ".$customer['full_name'].",\n\n$intro\nReference: ".$record['reference']."\nStatus: $status\n";
    if($type==='booking'){
        $snapshot=json_decode($record['snapshot_json'],true);
        $time=(new DateTimeImmutable($record['starts_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($record['timezone']));
        $body.="Requested / agreed start: ".$time->format('D j M Y H:i T')."\nService: ".$snapshot['service']." / ".$snapshot['package']."\nQuote: ".number_format($snapshot['total_minor']/100,2,'.','')." ".$snapshot['currency']."\nPayment preference: after service; no online payment collected.\n";
        if($record['status']==='reschedule_requested' && $record['proposed_start']){$proposed=(new DateTimeImmutable($record['proposed_start'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($record['timezone']));$body.='Proposed new start: '.$proposed->format('D j M Y H:i T')."\nThe existing reservation is kept until the new time is agreed and accepted.\n";}
    }
    $body.="\nContact: ".setting($db,'email','')." / ".setting($db,'phone','')."\n$name\n";
    $recipients=[['customer',$customer['email'],$body],['admin',(string)setting($db,'notification_email',''),"$name — $event\n\n".$body."\nCustomer phone: ".$customer['phone'].($type==='booking'?"\nLocation: ".$record['address'].', '.$record['postcode']:'')]];
    foreach($recipients as [$role,$recipient,$message]){
        if(!filter_var($recipient,FILTER_VALIDATE_EMAIL))continue;
        execute($db,'INSERT IGNORE INTO notifications (event_key,entity_type,entity_id,recipient,subject,body,available_at) VALUES (?,?,?,?,?,?,UTC_TIMESTAMP())',
            ["$type:$id:$event:$version:$role",$type,$id,$recipient,"$name — ".$record['reference']." — $status",$message]);
    }
}

function smtp_response($socket,array $expected): string
{
    $last='';
    do{
        $line=fgets($socket,2048);
        if($line===false)throw new RuntimeException('SMTP connection timed out or closed.');
        $last=$line;
    }while(strlen($line)>3 && $line[3]==='-');
    if(!in_array((int)substr($last,0,3),$expected,true))throw new RuntimeException('SMTP rejected the current delivery step (code '.(int)substr($last,0,3).').');
    return $last;
}

function smtp_command($socket,string $command,array $expected): void
{
    if(fwrite($socket,$command."\r\n")===false)throw new RuntimeException('SMTP write failed.');smtp_response($socket,$expected);
}

function message_source(PDO $db,array $job): string
{
    $from=(string)setting($db,'mail_from','');
    if(!filter_var($from,FILTER_VALIDATE_EMAIL) || !filter_var($job['recipient'],FILTER_VALIDATE_EMAIL))throw new RuntimeException('Invalid mail address.');
    $host=preg_replace('/[^A-Za-z0-9.-]/','',(string)parse_url((string)setting($db,'site_url',''),PHP_URL_HOST))?:'steamrex.invalid';
    $subject='=?UTF-8?B?'.base64_encode(str_replace(["\r","\n"],' ',$job['subject'])).'?=';
    $body=preg_replace('/\r\n|\r|\n/',"\r\n",$job['body']);
    $reply=(string)setting($db,'email','');$replyHeader=filter_var($reply,FILTER_VALIDATE_EMAIL)?'Reply-To: '.$reply."\r\n":'';
    return 'From: '.$from."\r\n".$replyHeader.'To: '.$job['recipient']."\r\nSubject: $subject\r\nDate: ".gmdate('D, d M Y H:i:s +0000')."\r\nMessage-ID: <steamrex-".$job['id']."@$host>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n".chunk_split(base64_encode($body),76,"\r\n");
}

function deliver_notification(PDO $db,array $job): string
{
    $transport=(string)setting($db,'mail_transport','log');$source=message_source($db,$job);
    if($transport==='log'){
        if(config('APP_ENV','local')!=='local')throw new RuntimeException('Local capture is disabled on production. Configure SMTP.');
        $directory=mail_capture_directory();if(!is_dir($directory))mkdir($directory,0700,true);
        if(file_put_contents($directory.'/'.$job['id'].'.eml',$source)===false)throw new RuntimeException('Mail capture storage is not writable.');
        return 'log';
    }
    if($transport!=='smtp')throw new RuntimeException('Email transport is not configured.');
    $host=(string)setting($db,'smtp_host','');$port=(int)setting($db,'smtp_port',587);$security=(string)setting($db,'smtp_security','starttls');
    if(!preg_match('/^[A-Za-z0-9.-]+$/D',$host))throw new RuntimeException('Invalid SMTP host.');
    if($security==='none' && config('APP_ENV','local')!=='local')throw new RuntimeException('Production SMTP requires TLS.');
    $context=stream_context_create(['ssl'=>['verify_peer'=>true,'verify_peer_name'=>true,'peer_name'=>$host,'allow_self_signed'=>false]]);
    $socket=@stream_socket_client(($security==='tls'?'tls://':'tcp://').$host.':'.$port,$errno,$error,10,STREAM_CLIENT_CONNECT,$context);
    if(!$socket)throw new RuntimeException('Cannot connect to SMTP server.');
    stream_set_timeout($socket,10);
    try{
        smtp_response($socket,[220]);smtp_command($socket,'EHLO steamrex',[250]);
        if($security==='starttls'){
            smtp_command($socket,'STARTTLS',[220]);
            if(stream_socket_enable_crypto($socket,true,STREAM_CRYPTO_METHOD_TLS_CLIENT)!==true)throw new RuntimeException('SMTP TLS negotiation failed.');
            smtp_command($socket,'EHLO steamrex',[250]);
        }
        $username=(string)setting($db,'smtp_username','');
        if($username!==''){
            smtp_command($socket,'AUTH LOGIN',[334]);smtp_command($socket,base64_encode($username),[334]);
            smtp_command($socket,base64_encode(decrypted_secret((string)setting($db,'smtp_password_encrypted',''))),[235]);
        }
        smtp_command($socket,'MAIL FROM:<'.setting($db,'mail_from','').'>',[250]);smtp_command($socket,'RCPT TO:<'.$job['recipient'].'>',[250,251]);smtp_command($socket,'DATA',[354]);
        $source=preg_replace('/(^|\r\n)\./','$1..',rtrim($source,"\r\n"));
        fwrite($socket,$source."\r\n.\r\n");smtp_response($socket,[250]);
        @fwrite($socket,"QUIT\r\n");return 'smtp';
    }finally{fclose($socket);}
}

function process_notifications(PDO $db,int $limit=20): array
{
    clear_settings_cache($db);
    set_setting($db,'worker_heartbeat',gmdate(DateTimeInterface::ATOM));$counts=['sent'=>0,'failed'=>0];
    for($i=0;$i<min(100,$limit);$i++){
        $db->beginTransaction();
        $job=row($db,"SELECT * FROM notifications WHERE (status = 'pending' AND available_at <= UTC_TIMESTAMP()) OR (status = 'processing' AND locked_until < UTC_TIMESTAMP()) ORDER BY id LIMIT 1 FOR UPDATE SKIP LOCKED");
        if(!$job){$db->commit();break;}
        execute($db,"UPDATE notifications SET status = 'processing',attempts = attempts + 1,locked_until = DATE_ADD(UTC_TIMESTAMP(),INTERVAL 5 MINUTE) WHERE id = ?",[$job['id']]);$db->commit();
        try{
            $transport=deliver_notification($db,$job);
            execute($db,"UPDATE notifications SET status = 'sent',transport = ?,sent_at = UTC_TIMESTAMP(),locked_until = NULL,last_error = NULL WHERE id = ?",[$transport,$job['id']]);$counts['sent']++;
        }catch(Throwable $e){
            $attempt=(int)$job['attempts']+1;$next=gmdate('Y-m-d H:i:s',time()+min(3600,30*(2**min(7,$attempt))));
            execute($db,'UPDATE notifications SET status = ?,available_at = ?,locked_until = NULL,last_error = ? WHERE id = ?',[$attempt>=5?'failed':'pending',$next,mb_substr($e->getMessage(),0,400),$job['id']]);$counts['failed']++;
        }
    }
    return $counts;
}
