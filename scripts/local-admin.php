<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
if(config('APP_ENV','local')!=='local'){fwrite(STDERR,"Local admin setup is disabled in production.\n");exit(1);}
if((int)row(database(),'SELECT COUNT(*) AS n FROM admins')['n']===0){
    $password=bin2hex(random_bytes(12)).'aA!';$email='admin@steamrex.local';
    execute(database(),'INSERT INTO admins (name,email,password_hash,role) VALUES (?,?,?,?)',['Local administrator',$email,password_hash($password,PASSWORD_DEFAULT),'super_admin']);
    file_put_contents(PROJECT_ROOT.'/.runtime/admin-access.json',json_encode_safe(['email'=>$email,'password'=>$password]));
}
echo "Admin panel: /admin/ . Initial local login is in .runtime/admin-access.json (private; never deploy it).\n";
