<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli')exit(1);
require dirname(__DIR__).'/app/bootstrap.php';
$url=rtrim($argv[1]??'','/');$parts=parse_url($url);
if(!filter_var($url,FILTER_VALIDATE_URL) || ($parts['scheme']??'')!=='https' || !empty(trim($parts['path']??'','/')) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['user'])){
    fwrite(STDERR,"Usage: php scripts/prepare-seo.php https://your-production-domain.com\n");exit(1);
}
$db=database();$db->beginTransaction();
try{set_setting($db,'site_url',$url);set_setting($db,'search_indexing',true);set_setting($db,'settings_revision',(int)setting($db,'settings_revision',1)+1);audit($db,null,'configure_seo','settings','seo',['site_url'=>$url]);$db->commit();}
catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
echo "Canonical URLs and sitemap now use the configured domain. Indexing stays disabled in local/development mode. No static build is required.\n";
