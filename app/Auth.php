<?php
declare(strict_types=1);

function admin_session(): void
{
    if(session_status()===PHP_SESSION_ACTIVE)return;
    ini_set('session.use_strict_mode','1');ini_set('session.use_only_cookies','1');
    $directory=PROJECT_ROOT.'/storage/sessions';if(!is_dir($directory))mkdir($directory,0700,true);
    session_save_path($directory);ini_set('session.gc_maxlifetime','28800');
    $context=(string)config('DB_HOST','').'|'.(string)config('DB_DATABASE','');
    session_name('steamrex_admin_'.substr(hash_hmac('sha256',$context,(string)config('APP_KEY','')),0,12));
    session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>(($_SERVER['HTTPS']??'')==='on'),'httponly'=>true,'samesite'=>'Strict']);
    session_start();
    if(!isset($_SESSION['csrf']))$_SESSION['csrf']=bin2hex(random_bytes(32));
}

function admin_identity(PDO $db): ?array
{
    admin_session();
    if(empty($_SESSION['admin_id']))return null;
    if(time()-(int)($_SESSION['last_seen']??0)>1800 || time()-(int)($_SESSION['signed_in']??0)>28800){admin_logout();return null;}
    $admin=row($db,'SELECT id,name,email,role,session_version FROM admins WHERE id = ? AND active = 1',[$_SESSION['admin_id']]);
    if(!$admin || $admin['session_version']!==($_SESSION['session_version']??0)){admin_logout();return null;}
    $_SESSION['last_seen']=time();unset($admin['session_version']);return $admin;
}

function require_admin(PDO $db,bool $owner=false): array
{
    $admin=admin_identity($db);
    if(!$admin)fail(401,'unauthenticated','Please sign in to continue.');
    if($owner && $admin['role']!=='super_admin')fail(403,'forbidden','Only a super admin can change this configuration.');
    return $admin;
}

function require_csrf(): void
{
    admin_session();$supplied=$_SERVER['HTTP_X_CSRF_TOKEN']??'';
    if(!is_string($supplied) || !hash_equals($_SESSION['csrf'],$supplied))fail(419,'csrf_failed','Your session has changed. Reload and try again.');
}

function admin_login(PDO $db,array $input): array
{
    require_csrf();rate_limit($db,'admin-login',config('APP_ENV','local')==='local'?100:10);
    $email=strtolower(text_field($input,'email',254));$password=text_field($input,'password',200);
    $admin=row($db,'SELECT * FROM admins WHERE email = ? AND active = 1',[$email]);
    $dummy='$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
    if(!password_verify($password,$admin['password_hash']??$dummy) || !$admin)fail(401,'invalid_credentials','Email or password is incorrect.');
    session_regenerate_id(true);$_SESSION=['admin_id'=>$admin['id'],'session_version'=>$admin['session_version'],'last_seen'=>time(),'signed_in'=>time(),'csrf'=>bin2hex(random_bytes(32))];
    execute($db,'UPDATE admins SET last_login_at = UTC_TIMESTAMP() WHERE id = ?',[$admin['id']]);
    audit($db,(int)$admin['id'],'login','admin',$admin['id']);
    return ['user'=>admin_identity($db),'csrf_token'=>$_SESSION['csrf']];
}

function admin_logout(): void
{
    admin_session();$_SESSION=[];session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(32));
}
