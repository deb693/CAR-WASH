<?php
declare(strict_types=1);

function admin_dispatch(PDO $db,string $method,string $path,array $input): mixed
{
    if($path==='admin/auth/session' && $method==='GET'){
        $user=admin_identity($db);return ['user'=>$user,'csrf_token'=>$_SESSION['csrf'],'local_mode'=>config('APP_ENV','local')==='local'];
    }
    if($path==='admin/auth/login' && $method==='POST')return admin_login($db,$input);
    $admin=require_admin($db);
    if($method!=='GET')require_csrf();
    if($path==='admin/auth/logout' && $method==='POST'){admin_logout();return ['signed_out'=>true];}
    if($path==='admin/auth/me' && $method==='GET')return $admin;
    if($path==='admin/overview' && $method==='GET'){
        return ['bookings'=>rows($db,'SELECT status,COUNT(*) AS count FROM bookings WHERE deleted_at IS NULL GROUP BY status'),
            'enquiries'=>rows($db,'SELECT status,COUNT(*) AS count FROM enquiries WHERE deleted_at IS NULL GROUP BY status'),
            'upcoming'=>rows($db,"SELECT id,reference,status,starts_at,timezone,customer_json FROM bookings WHERE deleted_at IS NULL AND status IN ('confirmed','pending_confirmation','reschedule_requested') AND starts_at >= UTC_TIMESTAMP() ORDER BY starts_at LIMIT 8"),
            'failed_notifications'=>(int)row($db,"SELECT COUNT(*) AS n FROM notifications WHERE status = 'failed'")['n']];
    }
    if(preg_match('#^admin/(bookings|enquiries)(?:/(\d+))?(?:/(convert|availability))?$#D',$path,$match)){
        $type=$match[1]==='bookings'?'booking':'enquiry';$id=isset($match[2])?(int)$match[2]:null;$action=$match[3]??'';
        if($method==='GET' && !$id)return admin_request_list($db,$type,$_GET);
        if($method==='GET' && $id && $action==='availability' && $type==='booking'){
            $booking=admin_request_detail($db,$type,$id);return ['slots'=>available_slots($db,['snapshot'=>$booking['quote']],(string)($_GET['date']??''),$id),'timezone'=>setting($db,'timezone','Europe/Copenhagen')];
        }
        if($method==='GET' && $id)return admin_request_detail($db,$type,$id);
        if($method==='POST' && $id && $action==='convert' && $type==='enquiry')return convert_enquiry($db,$admin,$id,$input);
        if($method==='PATCH' && $id && !$action)return $type==='booking'?update_booking($db,$admin,$id,$input):update_enquiry($db,$admin,$id,$input);
        if($method==='DELETE' && $id){require_admin($db,true);return erase_request($db,$admin,$type,$id,$input);}
    }
    if($path==='admin/notifications' && $method==='GET'){
        $page=max(1,(int)($_GET['page']??1));$status=substr((string)($_GET['status']??''),0,30);$where=$status!==''?'WHERE status = ?':'';$args=$status!==''?[$status]:[];
        return ['items'=>rows($db,"SELECT id,entity_type,entity_id,recipient,subject,status,attempts,last_error,transport,sent_at,created_at FROM notifications $where ORDER BY id DESC LIMIT 30 OFFSET ".(($page-1)*30),$args),'total'=>(int)row($db,"SELECT COUNT(*) AS n FROM notifications $where",$args)['n'],'page'=>$page,'limit'=>30];
    }
    if(preg_match('#^admin/notifications/(\d+)(/retry)?$#D',$path,$m)){
        $notification=row($db,'SELECT * FROM notifications WHERE id = ?',[$m[1]]);if(!$notification)fail(404,'not_found','Notification not found.');
        if($method==='GET')return $notification;
        if($method==='POST' && !empty($m[2])){
            if(!in_array($notification['status'],['failed','pending'],true))fail(422,'invalid_transition','Only pending or failed messages can be retried.');
            execute($db,"UPDATE notifications SET status = 'pending',attempts = 0,available_at = UTC_TIMESTAMP(),last_error = NULL WHERE id = ? AND status IN ('failed','pending')",[$m[1]]);audit($db,(int)$admin['id'],'retry','notification',$m[1]);return ['queued'=>true];
        }
    }
    // Configuration, publication, media and admin users belong to super admins.
    require_admin($db,true);
    if($path==='admin/metadata' && $method==='GET')return ['resources'=>resource_definitions(),'lookups'=>admin_lookups($db),'currency'=>setting($db,'currency','DKK')];
    if($path==='admin/lookups' && $method==='GET')return admin_lookups($db);
    if($path==='admin/settings'){
        if($method==='GET')return admin_settings($db);
        if($method==='PUT')return save_settings($db,$admin,$input);
    }
    if($path==='admin/readiness' && $method==='GET')return release_readiness($db);
    if($path==='admin/audit' && $method==='GET'){
        $page=max(1,(int)($_GET['page']??1));return ['items'=>rows($db,'SELECT l.*,a.name AS admin_name FROM audit_logs l LEFT JOIN admins a ON a.id=l.admin_id ORDER BY l.id DESC LIMIT 40 OFFSET '.(($page-1)*40)),'total'=>(int)row($db,'SELECT COUNT(*) AS n FROM audit_logs')['n'],'page'=>$page,'limit'=>40];
    }
    if($path==='admin/users' && $method==='GET')return ['items'=>rows($db,'SELECT id,name,email,role,active,session_version,last_login_at FROM admins ORDER BY id')];
    if($path==='admin/users' && $method==='POST')return save_admin_user($db,$admin,null,$input);
    if(preg_match('#^admin/users/(\d+)$#D',$path,$m) && $method==='PUT')return save_admin_user($db,$admin,(int)$m[1],$input);
    if($path==='admin/media'){
        if($method==='GET')return ['items'=>rows($db,'SELECT * FROM media WHERE deleted_at IS NULL ORDER BY created_at DESC')];
        if($method==='POST')return upload_media($db,$admin);
    }
    if(preg_match('#^admin/media/([a-f0-9]{32})$#D',$path,$m)){
        $media=row($db,'SELECT * FROM media WHERE id = ?',[$m[1]]);if(!$media)fail(404,'not_found','Image not found.');
        if((int)($input['version']??0)!==(int)$media['version'])fail(409,'edit_conflict','Image details changed. Reload before saving.');
        if($method==='PUT'){
            execute($db,'UPDATE media SET name = ?,alt_text = ?,version = version + 1 WHERE id = ?',[text_field($input,'name',200),text_field($input,'alt_text',300,false),$m[1]]);audit($db,(int)$admin['id'],'update','media',$m[1]);return ['updated'=>true];
        }
        if($method==='DELETE'){
            // Keep the file so existing published pages or snapshots never get a broken image.
            execute($db,'UPDATE media SET deleted_at = UTC_TIMESTAMP(),version = version + 1 WHERE id = ?',[$m[1]]);audit($db,(int)$admin['id'],'archive','media',$m[1]);return ['archived'=>true];
        }
    }
    if(preg_match('#^admin/catalogue/([a-z-]+)(?:/([^/]+))?$#D',$path,$m)){
        $resource=$m[1];$id=isset($m[2])?rawurldecode($m[2]):null;
        if($method==='GET')return $id!==null?resource_record($db,$resource,$id):admin_resource_list($db,$resource,$_GET);
        if($method==='POST' && $id===null)return save_resource($db,$admin,$resource,null,$input);
        if($method==='PUT' && $id!==null)return save_resource($db,$admin,$resource,$id,$input);
        if($method==='DELETE' && $id!==null)return delete_resource($db,$admin,$resource,$id,$input);
    }
    fail(404,'not_found','This admin endpoint does not exist.');
}
