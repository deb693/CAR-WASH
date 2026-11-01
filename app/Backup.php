<?php
declare(strict_types=1);

function create_backup(PDO $db,string $file): array
{
    if(is_file($file))throw new RuntimeException('A backup already exists at this path. Choose a new filename.');
    $output=gzopen($file,'wb9');if(!$output)throw new RuntimeException('Backup path is not writable.');
    $counts=[];$tables=array_column(rows($db,'SHOW TABLES'),array_key_first(rows($db,'SHOW TABLES')[0]??[]));
    $db->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');$db->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
    try{
        gzwrite($output,json_encode_safe(['format'=>'steamrex-backup-v1','created_at'=>gmdate(DateTimeInterface::ATOM)])."\n");
        foreach($tables as $table){
            if(!preg_match('/^[a-z_]+$/D',$table))throw new RuntimeException('Unexpected table name.');
            $create=$db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
            gzwrite($output,json_encode_safe(['table'=>$table,'create_sql'=>$create])."\n");$counts[$table]=0;
            $statement=$db->query("SELECT * FROM `$table`");while($record=$statement->fetch(PDO::FETCH_ASSOC)){gzwrite($output,json_encode_safe(['table'=>$table,'row'=>$record])."\n");$counts[$table]++;}
        }
        $db->commit();return $counts;
    }catch(Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
    finally{gzclose($output);}
}

function restore_backup(PDO $db,string $file): array
{
    if(rows($db,'SHOW TABLES'))throw new RuntimeException('Restore requires an empty database. Existing data was not changed.');
    $input=gzopen($file,'rb');if(!$input)throw new RuntimeException('Cannot open backup file.');
    $first=json_decode((string)gzgets($input),true,512,JSON_THROW_ON_ERROR);if(($first['format']??'')!=='steamrex-backup-v1')throw new RuntimeException('Unsupported backup format.');
    $counts=[];$db->exec('SET FOREIGN_KEY_CHECKS=0');
    try{
        while(($line=gzgets($input))!==false){
            $record=json_decode($line,true,512,JSON_THROW_ON_ERROR);$table=$record['table']??'';
            if(!preg_match('/^[a-z_]+$/D',$table))throw new RuntimeException('Invalid backup table name.');
            if(isset($record['create_sql'])){
                if(!str_starts_with($record['create_sql'],'CREATE TABLE `'.$table.'`'))throw new RuntimeException('Invalid table definition.');
                $db->exec($record['create_sql']);$counts[$table]=0;
            }elseif(isset($record['row'])){
                $columns=array_keys($record['row']);foreach($columns as $column)if(!preg_match('/^[a-z_]+$/D',$column))throw new RuntimeException('Invalid backup column.');
                execute($db,"INSERT INTO `$table` (`".implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')',array_values($record['row']));$counts[$table]++;
            }else throw new RuntimeException('Invalid backup record.');
        }
        return $counts;
    }finally{$db->exec('SET FOREIGN_KEY_CHECKS=1');gzclose($input);clear_settings_cache($db);}
}
