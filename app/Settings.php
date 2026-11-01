<?php
declare(strict_types=1);

function settings_defaults(): array
{
    return [
        'business_name'=>config('BUSINESS_NAME','SteamRex'), 'currency'=>config('CURRENCY','DKK'),
        'timezone'=>config('BUSINESS_TIMEZONE','Europe/Copenhagen'), 'site_url'=>config('SITE_URL','http://127.0.0.1:8080'),
        'demo_mode'=>filter_var(config('DEMO_MODE','true'),FILTER_VALIDATE_BOOLEAN), 'booking_enabled'=>config('APP_ENV','local')==='local',
        'phone'=>'+45 40 90 99 99', 'email'=>'info@steamrex.com', 'business_email'=>'erhverv@steamrex.com',
        'contact_hours'=>'Monday–Friday, 08:00–16:00', 'service_regions'=>'Zealand (Sjælland), Funen (Fyn) and Jutland (Jylland)',
        'notification_email'=>'admin@example.test', 'buffer_minutes'=>(int)config('SCHEDULE_BUFFER_MINUTES',15),
        'lead_minutes'=>(int)config('SCHEDULE_LEAD_MINUTES',60), 'booking_horizon_days'=>60, 'quote_minutes'=>15,
        'tax_mode'=>'inclusive', 'tax_rate_basis_points'=>2500, 'tax_label'=>'VAT',
        'search_indexing'=>false, 'legal_approved'=>false, 'terms_version'=>'1', 'privacy_version'=>'1',
        'mail_transport'=>config('MAIL_TRANSPORT','log'), 'mail_from'=>config('MAIL_FROM','noreply@example.test'),
        'smtp_host'=>config('SMTP_HOST','websmtp.simply.com'), 'smtp_port'=>(int)config('SMTP_PORT',587),
        'smtp_security'=>config('SMTP_SECURITY','starttls'), 'smtp_username'=>config('SMTP_USERNAME',''),
        'retention_days'=>365, 'hero_image'=>'/assets/images/hero-car.jpg',
        'hero_video_enabled'=>true,'hero_video'=>'/assets/video/steamrex-spring.mp4',
    ];
}

function setting(PDO $db, string $key, mixed $fallback = null): mixed
{
    $id=spl_object_id($db);
    if(!isset($GLOBALS['setting_cache'][$id])){
        $GLOBALS['setting_cache'][$id]=[];
        foreach(rows($db,'SELECT setting_key,value_json FROM settings') as $saved)$GLOBALS['setting_cache'][$id][$saved['setting_key']]=json_decode($saved['value_json'],true,512,JSON_THROW_ON_ERROR);
    }
    return $GLOBALS['setting_cache'][$id][$key] ?? (settings_defaults()[$key] ?? $fallback);
}

function clear_settings_cache(PDO $db): void {unset($GLOBALS['setting_cache'][spl_object_id($db)]);}

function set_setting(PDO $db,string $key,mixed $value): void
{
    execute($db,'INSERT INTO settings (setting_key,value_json) VALUES (?,?) ON DUPLICATE KEY UPDATE value_json = VALUES(value_json)',[$key,json_encode_safe($value)]);
    clear_settings_cache($db);
}

function initialize_application(PDO $db): void
{
    if (setting($db,'initialized',false)) return;
    foreach (settings_defaults() as $key=>$value) execute($db,'INSERT IGNORE INTO settings (setting_key,value_json) VALUES (?,?)',[$key,json_encode_safe($value)]);
    for ($day=1;$day<=6;$day++) execute($db,'INSERT IGNORE INTO availability_rules (id,weekday,opens_at,closes_at,capacity) VALUES (?,?,?,?,?)',['day-'.$day,$day,'09:00:00','17:00:00',(int)config('SCHEDULE_CAPACITY',1)]);
    set_setting($db,'initialized',true);
}

function encrypted_secret(string $plaintext): string
{
    $key = hash('sha256',(string)config('APP_KEY',''),true);
    if (strlen((string)config('APP_KEY','')) < 32) fail(503,'configuration_required','APP_KEY must be configured.');
    $iv=random_bytes(12);$tag='';
    $encrypted=openssl_encrypt($plaintext,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag);
    if($encrypted===false)throw new RuntimeException('Encryption unavailable.');
    return base64_encode($iv.$tag.$encrypted);
}

function decrypted_secret(string $saved): string
{
    if($saved==='')return (string)config('SMTP_PASSWORD','');
    $raw=base64_decode($saved,true);
    if($raw===false || strlen($raw)<28)throw new RuntimeException('Invalid encrypted secret.');
    $value=openssl_decrypt(substr($raw,28),'aes-256-gcm',hash('sha256',(string)config('APP_KEY',''),true),OPENSSL_RAW_DATA,substr($raw,0,12),substr($raw,12,16));
    if($value===false)throw new RuntimeException('Cannot decrypt SMTP secret; verify APP_KEY.');
    return $value;
}

function audit(PDO $db, ?int $adminId, string $action, string $entity, string|int $id, array $details=[]): void
{
    foreach(['password','password_hash','smtp_password','smtp_password_encrypted'] as $key)unset($details[$key]);
    execute($db,'INSERT INTO audit_logs (admin_id,action,entity_type,entity_id,details_json) VALUES (?,?,?,?,?)',[$adminId,$action,$entity,(string)$id,json_encode_safe($details)]);
}

function request_history(PDO $db,string $type,int $id,?string $from,string $to,?int $adminId,string $reason,array $details=[]): void
{
    execute($db,'INSERT INTO request_history (entity_type,entity_id,from_status,to_status,admin_id,reason,details_json) VALUES (?,?,?,?,?,?,?)',[$type,$id,$from,$to,$adminId,$reason,json_encode_safe($details)]);
}

function security_headers(?string $nonce=null): void
{
    if(PHP_SAPI!=='cli' && config('APP_ENV','local')==='production'){
        try{$origin=rtrim((string)setting(database(),'site_url',''),'/');}catch(Throwable){$origin=rtrim((string)config('SITE_URL',''),'/');}
        $parts=parse_url($origin);$expected=isset($parts['host'])?strtolower($parts['host']).(isset($parts['port'])?':'.$parts['port']:''):'';
        if(($parts['scheme']??'')!=='https' || $expected===''){http_response_code(503);header('X-Robots-Tag: noindex');exit('The production HTTPS website URL needs configuration.');}
        if(($_SERVER['HTTPS']??'')!=='on' || strtolower((string)($_SERVER['HTTP_HOST']??''))!==$expected){
            header('Location: '.$origin.($_SERVER['REQUEST_URI']??'/'),true,308);exit;
        }
    }
    header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    $noncePolicy=$nonce ? " 'nonce-$nonce'" : '';
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'$noncePolicy; connect-src 'self'; font-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'none'; form-action 'self'");
    if(($_SERVER['HTTPS']??'')==='on')header('Strict-Transport-Security: max-age=31536000');
}
