<?php
declare(strict_types=1);

function setting_fields(): array
{
    return [
        'business_name'=>field('text','Business name'),'site_url'=>field('url','Website URL'),
        'currency'=>field('text','Currency (ISO code, e.g. DKK)',true,['max'=>3]),'timezone'=>field('text','Business timezone (e.g. Europe/Copenhagen)'),
        'phone'=>field('text','Public telephone'),'email'=>field('email','Public customer email'),'business_email'=>field('email','Business enquiries email'),
        'contact_hours'=>field('text','Customer-service hours'),'service_regions'=>field('textarea','Service-area description'),
        'notification_email'=>field('email','Admin notification email'),'hero_image'=>field('image','Homepage fallback image'),
        'hero_video_enabled'=>field('boolean','Play homepage background video',false),
        'hero_video'=>field('text','Homepage video path (/assets/video/filename.mp4)'),
        'buffer_minutes'=>field('integer','Travel buffer (minutes)',true,['min'=>0,'max'=>240]),
        'lead_minutes'=>field('integer','Minimum booking lead time (minutes)',true,['min'=>0,'max'=>43200]),
        'booking_horizon_days'=>field('integer','Maximum advance booking (days)',true,['min'=>1,'max'=>365]),
        'quote_minutes'=>field('integer','Quote validity (minutes)',true,['min'=>5,'max'=>120]),
        'tax_mode'=>field('select','Tax treatment',true,['options'=>['inclusive'=>'Prices include tax','exclusive'=>'Add tax to listed prices','none'=>'No tax charged']]),
        'tax_rate_basis_points'=>field('integer','Tax rate (hundredths of a percent)',true,['min'=>0,'max'=>10000]),'tax_label'=>field('text','Tax label'),
        'demo_mode'=>field('boolean','Show development preview notices',false),'booking_enabled'=>field('boolean','Accept new requests',false),
        'search_indexing'=>field('boolean','Allow search-engine indexing on production',false),'legal_approved'=>field('boolean','Privacy and service terms reviewed and ready',false),
        'terms_version'=>field('text','Service terms version'),'privacy_version'=>field('text','Privacy notice version'),
        'retention_days'=>field('integer','Customer record retention (days)',true,['min'=>30,'max'=>3650]),
        'mail_transport'=>field('select','Email delivery',true,['options'=>['log'=>'Local capture — do not send','smtp'=>'SMTP delivery']]),
        'mail_from'=>field('email','Sender email address'),'smtp_host'=>field('text','SMTP hostname'),
        'smtp_port'=>field('integer','SMTP port',true,['min'=>1,'max'=>65535]),
        'smtp_security'=>field('select','SMTP encryption',true,['options'=>['starttls'=>'STARTTLS (usually port 587)','tls'=>'Implicit TLS (usually port 465)','none'=>'Unencrypted — local test server only']]),
        'smtp_username'=>field('text','SMTP username',false),'smtp_password'=>field('password','SMTP password (blank keeps existing)',false),
    ];
}

function admin_settings(PDO $db): array
{
    $values=[];foreach(setting_fields() as $key=>$rule)if($key!=='smtp_password')$values[$key]=setting($db,$key);
    $values['smtp_password_configured']=(string)setting($db,'smtp_password_encrypted','')!=='' || (string)config('SMTP_PASSWORD','')!=='';
    $values['_revision']=(int)setting($db,'settings_revision',1);return ['values'=>$values,'fields'=>setting_fields()];
}

function save_settings(PDO $db,array $admin,array $input): array
{
    $db->beginTransaction();
    try{
        execute($db,"INSERT IGNORE INTO settings (setting_key,value_json) VALUES ('settings_revision','1')");
        $revision=row($db,"SELECT value_json FROM settings WHERE setting_key = 'settings_revision' FOR UPDATE");
        if((int)($input['_revision']??0)!==(int)json_decode($revision['value_json'],true))fail(409,'edit_conflict','Settings changed. Reload before saving.');
        $changed=[];
        foreach(setting_fields() as $key=>$rule){
            if(!array_key_exists($key,$input))continue;
            $value=$input[$key];
            if($rule['type']==='boolean'){$value=$value===true || $value===1 || $value==='1';}
            elseif($rule['type']==='integer'){
                if(filter_var($value,FILTER_VALIDATE_INT)===false || (int)$value<$rule['min'] || (int)$value>$rule['max'])fail(422,'validation_error','Check '.$rule['label'].'.');$value=(int)$value;
            }else{
                $value=text_field($input,$key,$rule['max']??2000,$rule['required']);
                if($rule['type']==='email' && !filter_var($value,FILTER_VALIDATE_EMAIL))fail(422,'validation_error','Check '.$rule['label'].'.');
                if($rule['type']==='select' && !isset($rule['options'][$value]))fail(422,'validation_error','Choose a valid '.$rule['label'].'.');
            }
            if($key==='site_url'){
                $url=parse_url($value);
                if(!filter_var($value,FILTER_VALIDATE_URL) || !in_array($url['scheme']??'',config('APP_ENV','local')==='local'?['http','https']:['https'],true) || !empty($url['user']) || !empty($url['query']) || !empty($url['fragment']) || !empty(trim($url['path']??'','/')))fail(422,'validation_error','Use a root website URL. Production requires HTTPS.');
                $value=rtrim($value,'/');
            }
            if($key==='timezone' && !in_array($value,DateTimeZone::listIdentifiers(),true))fail(422,'validation_error','Use a valid IANA timezone.');
            if($key==='currency' && !preg_match('/^[A-Z]{3}$/D',$value))fail(422,'validation_error','Use a three-letter uppercase currency code.');
            if($key==='smtp_host' && !preg_match('/^[a-zA-Z0-9.-]{1,200}$/D',$value))fail(422,'validation_error','Use an SMTP hostname without protocol or path.');
            if($key==='smtp_security' && $value==='none' && config('APP_ENV','local')!=='local')fail(422,'validation_error','Production SMTP requires TLS.');
            if($key==='hero_image' && (!preg_match('#^/assets/(?:images|uploads)/[A-Za-z0-9._-]+\.(jpg|jpeg|png|webp)$#D',$value) || !is_file(PUBLIC_ROOT.$value)))fail(422,'validation_error','Choose an existing site image.');
            if($key==='hero_video' && (!preg_match('#^/assets/video/[A-Za-z0-9._-]+\.mp4$#D',$value) || !is_file(PUBLIC_ROOT.$value)))fail(422,'validation_error','Choose an existing MP4 inside /assets/video/.');
            if($key==='smtp_password'){
                if($value!==''){set_setting($db,'smtp_password_encrypted',encrypted_secret($value));$changed[]='smtp_password';}continue;
            }
            if(setting($db,$key)!==$value){$changed[]=$key;set_setting($db,$key,$value);}
        }
        set_setting($db,'settings_revision',(int)json_decode($revision['value_json'],true)+1);
        if(config('APP_ENV','local')==='production' && !demo_mode() && setting($db,'booking_enabled',true)){
            $readiness=release_readiness($db);
            if($readiness['blocking'])fail(422,'setup_incomplete','Complete launch setup before opening live requests: '.implode('; ',$readiness['blocking']));
        }
        audit($db,(int)$admin['id'],'update','settings','site',['changed_keys'=>$changed]);$db->commit();return admin_settings($db);
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();clear_settings_cache($db);throw $e;}
}

function release_readiness(PDO $db): array
{
    $blocking=[];$warnings=[];
    if(strlen((string)config('APP_KEY',''))<32 || str_contains(strtolower((string)config('APP_KEY','')),'replace-with'))$blocking[]='Configure a private APP_KEY';
    if(!(int)row($db,"SELECT COUNT(*) AS n FROM admins WHERE role = 'super_admin' AND active = 1")['n'])$blocking[]='Create a super admin';
    if(!row($db,'SELECT id FROM service_areas WHERE active = 1 AND deleted_at IS NULL'))$blocking[]='Add postcode coverage';
    if(!row($db,'SELECT id FROM availability_rules WHERE active = 1 AND deleted_at IS NULL'))$blocking[]='Add working hours';
    if(!row($db,'SELECT id FROM packages WHERE active = 1 AND deleted_at IS NULL'))$blocking[]='Add service packages';
    $complete=row($db,'SELECT p.service_id,p.class_id FROM packages p JOIN services s ON s.id=p.service_id JOIN vehicle_classes c ON c.id=p.class_id JOIN vehicle_models m ON m.class_id=c.id JOIN vehicle_makes v ON v.id=m.make_id WHERE p.active=1 AND p.deleted_at IS NULL AND s.active=1 AND s.deleted_at IS NULL AND c.active=1 AND c.deleted_at IS NULL AND m.active=1 AND m.deleted_at IS NULL AND v.active=1 AND v.deleted_at IS NULL GROUP BY p.service_id,p.class_id HAVING COUNT(DISTINCT p.tier)=3 LIMIT 1');
    if(!$complete)$blocking[]='Publish three package tiers for at least one eligible vehicle/service';
    $incomplete=rows($db,'SELECT p.service_id,p.class_id,COUNT(*) AS tiers FROM packages p JOIN services s ON s.id = p.service_id JOIN vehicle_classes c ON c.id = p.class_id WHERE p.active = 1 AND p.deleted_at IS NULL AND s.active = 1 AND s.deleted_at IS NULL AND c.active = 1 AND c.deleted_at IS NULL GROUP BY p.service_id,p.class_id HAVING tiers != 3');
    if($incomplete)$warnings[]='Some service / class combinations have fewer than three active tiers and will offer enquiry only.';
    if(!setting($db,'legal_approved',false))$blocking[]='Review and approve privacy and service terms';
    foreach(['privacy','terms'] as $page)if(!row($db,'SELECT id FROM site_content WHERE id = ? AND published = 1 AND deleted_at IS NULL AND LENGTH(body) > 100',[$page]))$blocking[]='Publish the '.$page.' page';
    if(setting($db,'mail_transport','log')!=='smtp')$blocking[]='Configure SMTP delivery';
    if(!filter_var(setting($db,'mail_from',''),FILTER_VALIDATE_EMAIL) || str_ends_with((string)setting($db,'mail_from',''),'.test'))$blocking[]='Set a real sender email';
    if(!filter_var(setting($db,'notification_email',''),FILTER_VALIDATE_EMAIL) || str_ends_with((string)setting($db,'notification_email',''),'.test'))$blocking[]='Set the admin notification email';
    if(setting($db,'smtp_username','')==='' || (setting($db,'smtp_password_encrypted','')==='' && config('SMTP_PASSWORD','')===''))$blocking[]='Configure SMTP credentials';
    if(config('APP_ENV','local')==='production' && !in_array(setting($db,'smtp_security',''),['starttls','tls'],true))$blocking[]='Configure encrypted SMTP delivery';
    if((string)setting($db,'smtp_password_encrypted','')!==''){
        try{decrypted_secret((string)setting($db,'smtp_password_encrypted',''));}catch(Throwable){$blocking[]='Re-enter SMTP credentials after changing APP_KEY';}
    }
    if(config('APP_ENV','local')!=='local' && !str_starts_with((string)setting($db,'site_url',''),'https://'))$blocking[]='Set the production HTTPS URL';
    if(demo_mode())$warnings[]='Development notices and local test data mode are enabled.';
    $heartbeat=setting($db,'worker_heartbeat',null);
    if(!$heartbeat || strtotime((string)$heartbeat)<time()-600)$warnings[]='Email worker has not reported in the last ten minutes. Check its scheduled command.';
    $worker=row($db,'SELECT MAX(sent_at) AS last_sent FROM notifications');
    return ['blocking'=>$blocking,'warnings'=>$warnings,'ready'=>!$blocking,'last_delivery'=>$worker['last_sent'],'worker_heartbeat'=>setting($db,'worker_heartbeat',null),'incomplete_packages'=>$incomplete];
}

function save_admin_user(PDO $db,array $actor,?int $id,array $input): array
{
    $name=text_field($input,'name',100);$email=strtolower(text_field($input,'email',254));$role=text_field($input,'role',30);
    if(!filter_var($email,FILTER_VALIDATE_EMAIL) || !in_array($role,['super_admin','operator'],true))fail(422,'validation_error','Enter a valid email and role.');
    $active=($input['active']??false)===true || ($input['active']??0)===1;
    $password=text_field($input,'password',200,false);
    if((!$id || $password!=='') && strlen($password)<12)fail(422,'validation_error','Use a password of at least 12 characters.');
    $db->beginTransaction();
    try{
        // Serialize user changes so concurrent requests cannot remove both remaining owners.
        rows($db,"SELECT id FROM admins WHERE role = 'super_admin' AND active = 1 FOR UPDATE");
        $old=$id?row($db,'SELECT * FROM admins WHERE id = ? FOR UPDATE',[$id]):null;
        if($id && !$old)fail(404,'not_found','Admin not found.');
        if($old && (int)($input['session_version']??0)!==(int)$old['session_version'])fail(409,'edit_conflict','This admin changed. Reload before saving.');
        if($old && $old['role']==='super_admin' && $old['active'] && (!$active || $role!=='super_admin') && (int)row($db,"SELECT COUNT(*) AS n FROM admins WHERE role = 'super_admin' AND active = 1")['n']<=1)fail(422,'last_super_admin','Keep at least one active super admin.');
        if($old){
            execute($db,'UPDATE admins SET name = ?,email = ?,role = ?,active = ?,session_version = session_version + 1'.($password!==''?',password_hash = ?':'').' WHERE id = ?',[$name,$email,$role,(int)$active,...($password!==''?[password_hash($password,PASSWORD_DEFAULT)]:[]),$id]);
        }else{
            execute($db,'INSERT INTO admins (name,email,role,active,password_hash) VALUES (?,?,?,?,?)',[$name,$email,$role,(int)$active,password_hash($password,PASSWORD_DEFAULT)]);$id=(int)$db->lastInsertId();
        }
        audit($db,(int)$actor['id'],$old?'update':'create','admin-user',$id,['name'=>$name,'email'=>$email,'role'=>$role,'active'=>$active,'password_changed'=>$password!=='']);
        $db->commit();
        if($id===(int)$actor['id'])$_SESSION['session_version']=(int)$old['session_version']+1;
        return row($db,'SELECT id,name,email,role,active,session_version,last_login_at FROM admins WHERE id = ?',[$id]);
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();if($e instanceof PDOException && $e->getCode()==='23000')fail(409,'duplicate_email','This email already belongs to an admin.');throw $e;}
}

function upload_media(PDO $db,array $admin): array
{
    $file=$_FILES['image']??null;
    if(!$file || $file['error']!==UPLOAD_ERR_OK || $file['size']>5*1024*1024)fail(422,'invalid_upload','Upload a JPG, PNG or WebP image up to 5 MB.');
    $image=@getimagesize($file['tmp_name']);$extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
    if(!$image || !isset($extensions[$image['mime']]) || $image[0]>12000 || $image[1]>12000)fail(422,'invalid_upload','Use a valid JPG, PNG or WebP image up to 12000 pixels.');
    $id=bin2hex(random_bytes(16));$path='/assets/uploads/'.$id.'.'.$extensions[$image['mime']];
    $directory=PUBLIC_ROOT.'/assets/uploads';if(!is_dir($directory))mkdir($directory,0755,true);
    if(!move_uploaded_file($file['tmp_name'],PUBLIC_ROOT.$path))fail(503,'upload_failed','Image storage is not writable.');
    $name=mb_substr(basename((string)$file['name']),0,190);
    execute($db,'INSERT INTO media (id,name,path,alt_text,mime_type,size_bytes,width,height) VALUES (?,?,?,?,?,?,?,?)',[$id,$name,$path,'',$image['mime'],$file['size'],$image[0],$image[1]]);
    audit($db,(int)$admin['id'],'upload','media',$id,['path'=>$path]);return row($db,'SELECT * FROM media WHERE id = ?',[$id]);
}
