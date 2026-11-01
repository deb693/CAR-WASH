<?php
declare(strict_types=1);

function setup_status(PDO $db): array
{
    return ['available'=>(int)row($db,'SELECT COUNT(*) AS n FROM admins')['n']===0 && strlen((string)config('SETUP_TOKEN',''))>=32];
}

function setup_owner(PDO $db,array $input): array
{
    rate_limit($db,'first-setup',10);$provided=text_field($input,'setup_token',200);$expected=(string)config('SETUP_TOKEN','');
    if(strlen($expected)<32 || !hash_equals($expected,$provided))fail(403,'setup_denied','The setup token is invalid or setup is disabled.');
    $name=text_field($input,'name',100);$email=strtolower(text_field($input,'email',254));$password=text_field($input,'password',200);
    if(!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($password)<12)fail(422,'validation_error','Use a valid email and a password of at least 12 characters.');
    $db->beginTransaction();
    try{
        execute($db,"INSERT IGNORE INTO settings (setting_key,value_json) VALUES ('setup_lock','true')");row($db,"SELECT setting_key FROM settings WHERE setting_key = 'setup_lock' FOR UPDATE");
        if((int)row($db,'SELECT COUNT(*) AS n FROM admins')['n']!==0)fail(403,'setup_complete','The first admin has already been created. Sign in instead.');
        initialize_application($db);initialize_content($db);
        execute($db,'INSERT INTO admins (name,email,password_hash,role) VALUES (?,?,?,?)',[$name,$email,password_hash($password,PASSWORD_DEFAULT),'super_admin']);
        $id=(int)$db->lastInsertId();audit($db,$id,'first_setup','admin-user',$id);$db->commit();return ['created'=>true,'next'=>'/admin/'];
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();clear_settings_cache($db);throw $error;}
}
