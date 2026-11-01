<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/app/bootstrap.php';
$options=getopt('',['email:','name:']);$email=strtolower((string)($options['email']??''));$name=(string)($options['name']??'Administrator');
if(!filter_var($email,FILTER_VALIDATE_EMAIL)){fwrite(STDERR,"Usage: php scripts/create-admin.php --email=owner@your-domain.com --name=Owner\nSupply the password through stdin, not command arguments.\n");exit(1);}
$password=rtrim((string)fgets(STDIN),"\r\n");
if(strlen($password)<12 || strlen($password)>200){fwrite(STDERR,"Use a password between 12 and 200 characters.\n");exit(1);}
if(row(database(),'SELECT id FROM admins WHERE email = ?',[$email])){fwrite(STDERR,"This admin exists; use the admin panel to update it.\n");exit(1);}
execute(database(),'INSERT INTO admins (name,email,password_hash,role) VALUES (?,?,?,?)',[$name,$email,password_hash($password,PASSWORD_DEFAULT),'super_admin']);
echo "Super admin created. No public signup endpoint exists.\n";
