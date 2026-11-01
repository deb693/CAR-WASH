<?php
declare(strict_types=1);

function field(string $type,string $label,bool $required=true,array $extra=[]): array {return ['type'=>$type,'label'=>$label,'required'=>$required,...$extra];}

function resource_definitions(): array
{
    $active=field('boolean','Available for new bookings',false);
    $ref=fn($label,$resource)=>field('select',$label,true,['resource'=>$resource]);
    $price=fn($label='Price (minor units)')=>field('integer',$label,true,['min'=>0,'max'=>100000000]);
    $duration=field('integer','Duration in minutes',true,['min'=>1,'max'=>1440]);
    $version=['version'=>field('hidden','Version',false)];
    return [
        'reviews'=>['table'=>'reviews','label'=>'Customer reviews','key'=>'id','fields'=>[
            'author'=>field('text','Customer name',true,['max'=>100]),'quote'=>field('textarea','Review excerpt',true,['max'=>2000]),
            'rating'=>field('integer','Individual rating (1–5)',true,['min'=>1,'max'=>5]),
            'source_label'=>field('text','Source / attribution',true,['max'=>100]),'source_url'=>field('url','Original review or source URL',true,['max'=>500]),
            'sort_order'=>field('integer','Display order',true,['min'=>0,'max'=>10000]),'active'=>field('boolean','Publish review',false),...$version]],
        'faqs'=>['table'=>'faqs','label'=>'Frequently asked questions','key'=>'id','fields'=>['question'=>field('text','Question',true,['max'=>250]),'answer'=>field('textarea','Answer'),'sort_order'=>field('integer','Display order',true,['min'=>0,'max'=>10000]),'active'=>$active,...$version]],
        'services'=>['table'=>'services','label'=>'Services','key'=>'id','fields'=>[
            'name'=>field('text','Service name'),'slug'=>field('slug','Public URL slug'),'summary'=>field('textarea','Short summary'),
            'description'=>field('textarea','Full service description',false,['max'=>20000]),'exclusions'=>field('textarea','Exclusions / preparation instructions',false),
            'image_path'=>field('image','Service photo',false),'sort_order'=>field('integer','Display order',true,['min'=>0,'max'=>10000]),'active'=>$active,...$version]],
        'vehicle-classes'=>['table'=>'vehicle_classes','label'=>'Vehicle classes','key'=>'id','fields'=>['name'=>field('text','Class name'),'sort_order'=>field('integer','Display order',true,['min'=>0,'max'=>10000]),'active'=>$active,...$version]],
        'vehicle-makes'=>['table'=>'vehicle_makes','label'=>'Vehicle makes','key'=>'id','fields'=>['name'=>field('text','Make name'),'active'=>$active,...$version]],
        'vehicle-models'=>['table'=>'vehicle_models','label'=>'Vehicle models','key'=>'id','fields'=>['name'=>field('text','Model name'),'make_id'=>$ref('Make','vehicle-makes'),'class_id'=>$ref('Vehicle class','vehicle-classes'),'active'=>$active,...$version]],
        'packages'=>['table'=>'packages','label'=>'Packages','key'=>'id','fields'=>[
            'name'=>field('text','Package name'),'service_id'=>$ref('Service','services'),'class_id'=>$ref('Vehicle class','vehicle-classes'),
            'tier'=>field('integer','Tier (1, 2 or 3)',true,['min'=>1,'max'=>3]),'summary'=>field('textarea','Short description'),
            'price_minor'=>$price(),'duration_minutes'=>$duration,'inclusions'=>field('lines','Inclusions — one per line'),
            'exclusions'=>field('textarea','Exclusions / notes',false),'badge'=>field('text','Optional badge',false,['max'=>80]),
            'add_on_ids'=>field('multi','Available add-ons',false,['resource'=>'add-ons']),'active'=>$active,...$version]],
        'add-ons'=>['table'=>'add_ons','label'=>'Add-on services','key'=>'id','fields'=>['name'=>field('text','Add-on name'),'description'=>field('textarea','Description',false),'price_minor'=>$price(),'duration_minutes'=>field('integer','Additional minutes',true,['min'=>0,'max'=>720]),'active'=>$active,...$version]],
        'price-overrides'=>['table'=>'package_price_overrides','label'=>'Model price overrides','key'=>'composite','fields'=>['package_id'=>$ref('Package','packages'),'model_id'=>$ref('Vehicle model','vehicle-models'),'price_minor'=>$price(),...$version]],
        'addon-price-overrides'=>['table'=>'add_on_price_overrides','label'=>'Add-on class prices','key'=>'id','fields'=>['add_on_id'=>$ref('Add-on service','add-ons'),'class_id'=>$ref('Vehicle class','vehicle-classes'),'price_minor'=>$price(),...$version]],
        'service-areas'=>['table'=>'service_areas','label'=>'Postcode coverage','key'=>'id','auto'=>true,'fields'=>['name'=>field('text','Area name'),'postcode'=>field('postcode','Exact postcode'),'surcharge_minor'=>$price('Travel surcharge (minor units)'),'active'=>$active,...$version]],
        'availability-rules'=>['table'=>'availability_rules','label'=>'Working hours','key'=>'id','fields'=>[
            'weekday'=>field('select','Day of week',true,['options'=>['1'=>'Monday','2'=>'Tuesday','3'=>'Wednesday','4'=>'Thursday','5'=>'Friday','6'=>'Saturday','7'=>'Sunday']]),
            'opens_at'=>field('time','Opening time'),'closes_at'=>field('time','Closing time'),
            'interval_minutes'=>field('integer','Start-time interval (minutes)',true,['min'=>5,'max'=>240]),
            'capacity'=>field('integer','Maximum concurrent jobs',true,['min'=>1,'max'=>100]),'active'=>$active,...$version]],
        'blocked-dates'=>['table'=>'blocked_dates','label'=>'Blocked dates','key'=>'day','fields'=>['day'=>field('date','Date'),'reason'=>field('text','Reason'),'starts_at'=>field('time','Block from (blank = whole day)',false),'ends_at'=>field('time','Block until (blank = whole day)',false),...$version]],
        'content'=>['table'=>'site_content','label'=>'Pages & SEO','key'=>'id','fields'=>[
            'id'=>field('slug','Page key / URL slug'),'title'=>field('text','Page name'),'meta_title'=>field('text','SEO title',true,['max'=>200]),'meta_description'=>field('textarea','SEO description',true,['max'=>320]),
            'heading'=>field('text','Main heading',true,['max'=>250]),'summary'=>field('textarea','Opening paragraph',false),'body'=>field('textarea','Page body — separate paragraphs with blank lines',false,['max'=>60000]),
            'image_path'=>field('image','Hero / feature image',false),'published'=>field('boolean','Publish page',false),...$version]],
    ];
}

function resource_key(array $spec,array $record): string
{
    return $spec['key']==='composite' ? $record['package_id'].'~'.$record['model_id'] : (string)$record[$spec['key']];
}

function resource_where(array $spec,string $id): array
{
    if($spec['key']==='composite'){
        $parts=explode('~',$id);if(count($parts)!==2)fail(404,'not_found','Record not found.');
        return ['package_id = ? AND model_id = ?',$parts];
    }
    return ['`'.$spec['key'].'` = ?',[$id]];
}

function resource_record(PDO $db,string $resource,string $id,bool $lock=false): array
{
    $spec=resource_definitions()[$resource]??null;if(!$spec)fail(404,'not_found','Unknown configuration section.');
    [$where,$args]=resource_where($spec,$id);
    $record=row($db,'SELECT * FROM `'.$spec['table'].'` WHERE '.$where.($lock?' FOR UPDATE':''),$args);
    if(!$record)fail(404,'not_found','Record not found.');
    $record['_key']=resource_key($spec,$record);
    if($resource==='packages'){
        $record['inclusions']=json_decode($record['inclusions'],true);
        $record['add_on_ids']=array_column(rows($db,'SELECT add_on_id FROM package_add_ons WHERE package_id = ?',[$record['id']]),'add_on_id');
    }
    return $record;
}

function admin_resource_list(PDO $db,string $resource,array $query): array
{
    $spec=resource_definitions()[$resource]??null;if(!$spec)fail(404,'not_found','Unknown configuration section.');
    $page=max(1,(int)($query['page']??1));$limit=min(100,max(1,(int)($query['limit']??30)));
    $where=($query['archived']??'')==='1'?'deleted_at IS NOT NULL':'deleted_at IS NULL';$args=[];
    if(trim((string)($query['q']??''))!==''){
        $field=isset($spec['fields']['name'])?'name':($resource==='content'?'title':($resource==='faqs'?'question':($resource==='blocked-dates'?'reason':$spec['key'])));
        if($field==='composite')$field='package_id';
        if($resource==='reviews')$field='author';
        $where.=' AND `'.$field.'` LIKE ?';$args[]='%'.substr((string)$query['q'],0,100).'%';
    }
    $total=(int)row($db,'SELECT COUNT(*) AS total FROM `'.$spec['table'].'` WHERE '.$where,$args)['total'];
    $order=isset($spec['fields']['name'])?'name':($resource==='content'?'title':($spec['key']==='composite'?'package_id':$spec['key']));
    $records=rows($db,'SELECT * FROM `'.$spec['table'].'` WHERE '.$where.' ORDER BY `'.$order.'` LIMIT '.$limit.' OFFSET '.(($page-1)*$limit),$args);
    foreach($records as &$record){$record['_key']=resource_key($spec,$record);if($resource==='packages')$record['inclusions']=json_decode($record['inclusions'],true);}
    return ['items'=>$records,'total'=>$total,'page'=>$page,'limit'=>$limit];
}

function validate_resource(PDO $db,string $resource,array $input): array
{
    $spec=resource_definitions()[$resource];$data=[];
    foreach($spec['fields'] as $key=>$rule){
        if(in_array($key,['version','add_on_ids'],true))continue;
        $type=$rule['type'];$value=$input[$key]??null;
        if($type==='boolean'){$data[$key]=($value===true || $value===1 || $value==='1')?1:0;continue;}
        if(($value===null || $value==='') && !$rule['required']){$data[$key]=in_array($type,['time'],true)?null:'';continue;}
        if($type==='integer'){
            if(filter_var($value,FILTER_VALIDATE_INT)===false || (int)$value<$rule['min'] || (int)$value>$rule['max'])fail(422,'validation_error',$rule['label'].' is outside its allowed range.',[$key=>'Check this value.']);
            $data[$key]=(int)$value;continue;
        }
        if($type==='lines'){
            $lines=is_array($value)?$value:preg_split('/\r?\n/',(string)$value);
            if(count($lines)>100)fail(422,'validation_error','Use at most 100 inclusion lines.');
            $lines=array_values(array_filter(array_map(fn($line)=>is_string($line)?trim($line):'', $lines),fn($line)=>$line!==''));
            foreach($lines as $line)if(strlen($line)>500)fail(422,'validation_error','An inclusion is too long.');
            if(!$lines)fail(422,'validation_error','Add at least one inclusion.');$data[$key]=json_encode_safe($lines);continue;
        }
        // Numeric select values are returned as integers by PDO (for example weekdays).
        // Accept the same representation on a read/edit round trip as a form's string value.
        if($type==='select' && isset($rule['options']) && is_int($value))$input[$key]=(string)$value;
        $value=text_field($input,$key,$rule['max']??($type==='textarea'?10000:200),$rule['required']);
        if($type==='slug' && !preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/D',$value))fail(422,'validation_error','Use a lowercase URL slug containing letters, numbers and hyphens.',[$key=>'Invalid slug.']);
        if($type==='postcode')$value=normalize_postcode($value);
        if($type==='url' && (!filter_var($value,FILTER_VALIDATE_URL) || parse_url($value,PHP_URL_SCHEME)!=='https' || parse_url($value,PHP_URL_USER)!==null))fail(422,'validation_error','Use an HTTPS source URL without credentials.');
        if($type==='date'){
            $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value);
            if(!$date || $date->format('Y-m-d')!==$value)fail(422,'validation_error','Enter a valid date.');
        }
        if($type==='time'){
            if(!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::00)?$/D',$value))fail(422,'validation_error','Enter a valid time.');
            $value=substr($value,0,5).':00';
        }
        if($type==='image' && $value!==''){
            if(!preg_match('#^/assets/(?:images|uploads)/[A-Za-z0-9._-]+\.(?:jpg|jpeg|png|webp)$#D',$value) || !is_file(PUBLIC_ROOT.$value))fail(422,'validation_error','Choose an uploaded or existing site image.');
        }
        if($type==='select'){
            if(isset($rule['options']) && !array_key_exists($value,$rule['options']))fail(422,'validation_error','Choose a valid '.$rule['label'].'.');
            if(isset($rule['resource'])){
                $parent=resource_definitions()[$rule['resource']];
                if(!row($db,'SELECT id FROM `'.$parent['table'].'` WHERE id = ? AND deleted_at IS NULL',[$value]))fail(422,'validation_error','The selected '.$rule['label'].' is unavailable.');
            }
        }
        $data[$key]=$value;
    }
    if($resource==='packages'){
        $ids=$input['add_on_ids']??[];
        if(!is_array($ids) || count($ids)>100)fail(422,'validation_error','Choose valid add-on services.');
        foreach($ids as $id)if(!is_string($id) || !row($db,'SELECT id FROM add_ons WHERE id = ? AND deleted_at IS NULL',[$id]))fail(422,'validation_error','An add-on is unavailable.');
        $data['add_on_ids']=array_values(array_unique($ids));
    }
    if($resource==='price-overrides'){
        $package=row($db,'SELECT class_id FROM packages WHERE id = ?',[$data['package_id']]);$model=row($db,'SELECT class_id FROM vehicle_models WHERE id = ?',[$data['model_id']]);
        if($package['class_id']!==$model['class_id'])fail(422,'validation_error','The model must belong to the package vehicle class.');
    }
    if($resource==='availability-rules' && $data['opens_at']>=$data['closes_at'])fail(422,'validation_error','Closing time must be after opening time.');
    if($resource==='blocked-dates' && (($data['starts_at']===null)!==($data['ends_at']===null) || ($data['starts_at']!==null && $data['starts_at']>=$data['ends_at'])))fail(422,'validation_error','Supply both block times in increasing order, or leave both blank.');
    return $data;
}

function save_resource(PDO $db,array $admin,string $resource,?string $id,array $input): array
{
    $spec=resource_definitions()[$resource]??null;if(!$spec)fail(404,'not_found','Unknown section.');
    $db->beginTransaction();
    try{
        $old=$id!==null?resource_record($db,$resource,$id,true):null;
        if($old && (int)($input['version']??0)!==(int)$old['version'])fail(409,'edit_conflict','This record changed. Reload it before saving.');
        $data=validate_resource($db,$resource,$input);
        if($old && $spec['key']==='composite' && ($data['package_id']!==$old['package_id'] || $data['model_id']!==$old['model_id']))fail(422,'validation_error','Create a new override to change its package or model.');
        if($old && $spec['key']==='day' && $data['day']!==$old['day'])fail(422,'validation_error','Create a new blocked date to change its day.');
        if($resource==='availability-rules' && $data['active']){
            $conflict=row($db,'SELECT id FROM availability_rules WHERE weekday = ? AND active = 1 AND deleted_at IS NULL AND opens_at < ? AND closes_at > ? AND id != ?',[$data['weekday'],$data['closes_at'],$data['opens_at'],$id??'']);
            if($conflict)fail(422,'validation_error','Working windows on the same day cannot overlap.');
        }
        if($resource==='content' && $old && $data['id']!==$old['id'])fail(422,'validation_error','A page key cannot be changed; create a new page instead.');
        if(!$old && $resource==='packages'){
            $prior=row($db,'SELECT id FROM packages WHERE service_id = ? AND class_id = ? AND tier = ? FOR UPDATE',[$data['service_id'],$data['class_id'],$data['tier']]);
            if($prior){$prior=resource_record($db,$resource,$prior['id'],true);if($prior['deleted_at']!==null){$old=$prior;$id=$prior['id'];}else fail(409,'duplicate_record','This service/class already has that tier. Edit the existing package.');}
        }
        $extras=$data['add_on_ids']??null;unset($data['add_on_ids']);
        if($old){
            [$where,$args]=resource_where($spec,(string)$id);
            $set=implode(',',array_map(fn($field)=>'`'.$field.'` = ?',array_keys($data)));
            execute($db,'UPDATE `'.$spec['table'].'` SET '.$set.', deleted_at = NULL, version = version + 1 WHERE '.$where,[...array_values($data),...$args]);
        }else{
            if($spec['key']==='id' && empty($spec['auto']) && !isset($data['id']))$data=['id'=>substr($resource,0,14).'-'.bin2hex(random_bytes(6)),...$data];
            $columns=array_keys($data);execute($db,'INSERT INTO `'.$spec['table'].'` (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')',array_values($data));
            $id=!empty($spec['auto'])?(string)$db->lastInsertId():resource_key($spec,$data);
        }
        if($extras!==null){execute($db,'DELETE FROM package_add_ons WHERE package_id = ?',[$id]);foreach($extras as $extra)execute($db,'INSERT INTO package_add_ons (package_id,add_on_id) VALUES (?,?)',[$id,$extra]);}
        $record=resource_record($db,$resource,(string)$id);
        audit($db,(int)$admin['id'],$old?'update':'create',$resource,(string)$id,['before'=>$old,'after'=>$record]);
        $db->commit();return $record;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();if($e instanceof PDOException && $e->getCode()==='23000')fail(409,'duplicate_record','A record with these unique values already exists. Edit or restore it.');throw $e;}
}

function delete_resource(PDO $db,array $admin,string $resource,string $id,array $input): array
{
    $db->beginTransaction();
    try{
        $old=resource_record($db,$resource,$id,true);
        if((int)($input['version']??0)!==(int)$old['version'])fail(409,'edit_conflict','This record changed. Reload before removing it.');
        $spec=resource_definitions()[$resource];[$where,$args]=resource_where($spec,$id);
        execute($db,'UPDATE `'.$spec['table'].'` SET deleted_at = UTC_TIMESTAMP(), version = version + 1 WHERE '.$where,$args);
        audit($db,(int)$admin['id'],'archive',$resource,$id,['before'=>$old]);$db->commit();return ['archived'=>true,'message'=>'Removed from new bookings. Existing request snapshots are preserved.'];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}

function admin_lookups(PDO $db): array
{
    $result=[];
    foreach(resource_definitions() as $resource=>$spec){
        if(!in_array($spec['key'],['id'],true))continue;
        $name=isset($spec['fields']['name'])?'name':($resource==='content'?'title':'id');
        $display=$resource==='packages'?"CONCAT(name,' — ',service_id,' / ',class_id,' / tier ',tier)":'`'.$name.'`';
        $result[$resource]=rows($db,'SELECT id, '.$display.' AS name FROM `'.$spec['table'].'` WHERE deleted_at IS NULL ORDER BY `'.$name.'` LIMIT 5000');
    }
    $images=[];
    foreach(glob(PUBLIC_ROOT.'/assets/images/*') as $path)if(preg_match('/\.(jpg|jpeg|png|webp)$/i',$path))$images[]=['id'=>'/assets/images/'.basename($path),'name'=>ucwords(str_replace('-',' ',pathinfo($path,PATHINFO_FILENAME)))];
    $result['images']=array_merge($images,rows($db,'SELECT path AS id, name FROM media WHERE deleted_at IS NULL ORDER BY created_at DESC'));
    return $result;
}
