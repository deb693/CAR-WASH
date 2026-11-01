<?php
declare(strict_types=1);

function booking_local_day(array $booking): string
{
    return (new DateTimeImmutable($booking['starts_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($booking['timezone']))->format('Y-m-d');
}

function admin_request_list(PDO $db,string $type,array $query): array
{
    $table=$type==='booking'?'bookings':'enquiries';$where='deleted_at IS NULL';$args=[];
    if(!empty($query['status'])){$where.=' AND status = ?';$args[]=substr((string)$query['status'],0,40);}
    if(!empty($query['q'])){$where.=' AND (reference LIKE ? OR customer_json LIKE ?)';$q='%'.substr((string)$query['q'],0,100).'%';$args[]=$q;$args[]=$q;}
    if(!empty($query['date'])){
        $date=(string)$query['date'];$day=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone((string)setting($db,'timezone','Europe/Copenhagen')));
        if(!$day || $day->format('Y-m-d')!==$date)fail(422,'validation_error','Choose a valid date.');
        $field=$type==='booking'?'starts_at':'created_at';$utc=new DateTimeZone('UTC');$where.=" AND $field >= ? AND $field < ?";
        $args[]=$day->setTimezone($utc)->format('Y-m-d H:i:s');$args[]=$day->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
    }
    $page=max(1,(int)($query['page']??1));$limit=min(100,max(1,(int)($query['limit']??25)));
    $total=(int)row($db,"SELECT COUNT(*) AS n FROM $table WHERE $where",$args)['n'];
    $fields=$type==='booking'?'id,reference,status,intent,customer_json,postcode,starts_at,timezone,version,created_at':'id,reference,status,customer_json,version,created_at';
    $items=rows($db,"SELECT $fields FROM $table WHERE $where ORDER BY created_at DESC,id DESC LIMIT $limit OFFSET ".(($page-1)*$limit),$args);
    foreach($items as &$item){$item['customer']=json_decode($item['customer_json'],true);unset($item['customer_json']);}
    return ['items'=>$items,'total'=>$total,'page'=>$page,'limit'=>$limit];
}

function admin_request_detail(PDO $db,string $type,int $id): array
{
    $table=$type==='booking'?'bookings':'enquiries';$record=row($db,"SELECT * FROM $table WHERE id = ? AND deleted_at IS NULL",[$id]);
    if(!$record)fail(404,'not_found','Request not found.');
    foreach(['customer_json'=>'customer','selection_json'=>'selection','snapshot_json'=>'quote','context_json'=>'context'] as $column=>$field)if(isset($record[$column])){$record[$field]=json_decode($record[$column],true);unset($record[$column]);}
    $record['history']=rows($db,'SELECT h.*,a.name AS admin_name FROM request_history h LEFT JOIN admins a ON a.id = h.admin_id WHERE entity_type = ? AND entity_id = ? ORDER BY h.id DESC',[$type,$id]);
    $record['notes']=rows($db,'SELECT n.*,a.name AS admin_name FROM admin_notes n LEFT JOIN admins a ON a.id = n.admin_id WHERE entity_type = ? AND entity_id = ? ORDER BY n.id DESC',[$type,$id]);
    $record['notifications']=rows($db,'SELECT id,recipient,subject,status,attempts,last_error,transport,sent_at,created_at FROM notifications WHERE entity_type = ? AND entity_id = ? ORDER BY id DESC',[$type,$id]);
    return $record;
}

function require_request_version(array $record,array $input): void
{
    if((int)($input['version']??0)!==(int)$record['version'])fail(409,'edit_conflict','Another admin changed this request. Reload it before continuing.');
}

function update_booking(PDO $db,array $admin,int $id,array $input): array
{
    $action=text_field($input,'action',40);$reason=text_field($input,'reason',2000,!in_array($action,['confirm','complete','note','edit_contact'],true));
    $db->beginTransaction();
    try{
        $record=row($db,'SELECT * FROM bookings WHERE id = ? AND deleted_at IS NULL FOR UPDATE',[$id]);
        if(!$record)fail(404,'not_found','Booking not found.');require_request_version($record,$input);
        $from=$record['status'];$to=$from;$details=[];$notify=true;
        $active=in_array($from,['pending_confirmation','confirmed','reschedule_requested'],true);
        if($action==='note'){
            execute($db,'INSERT INTO admin_notes (entity_type,entity_id,admin_id,note) VALUES (?,?,?,?)',['booking',$id,$admin['id'],text_field($input,'note',4000)]);$notify=false;
        }elseif($action==='edit_contact'){
            $customer=customer_details(['customer'=>$input['customer']??null,'consent'=>true]);
            execute($db,'UPDATE bookings SET customer_json = ?,address = ?,message = ? WHERE id = ?',[json_encode_safe($customer),text_field($input,'address',500),text_field($input,'message',2000,false),$id]);$notify=false;
        }elseif($action==='confirm' && $from==='pending_confirmation'){
            if(strtotime($record['starts_at'].' UTC')<=time())fail(422,'past_appointment','Reschedule this past request before confirming.');$to='confirmed';
        }elseif($action==='complete' && $from==='confirmed'){
            if(strtotime($record['ends_at'].' UTC')>time())fail(422,'future_appointment','An appointment cannot be marked completed before its scheduled finish.');$to='completed';
        }elseif($action==='cancel' && $active){$to='cancelled';}
        elseif($action==='reject' && ($from==='pending_confirmation' || ($from==='reschedule_requested' && $record['prior_status']==='pending_confirmation'))){$to='rejected';}
        elseif($action==='propose_reschedule' && in_array($from,['pending_confirmation','confirmed'],true)){
            $quote=['snapshot'=>json_decode($record['snapshot_json'],true)];$requested=text_field($input,'starts_at',60);
            // Validate the proposal now, but keep the existing reservation until accepted.
            [$proposed]=allocate_slot($db,$quote,$requested,$id,[booking_local_day($record)]);
            execute($db,'UPDATE bookings SET proposed_start = ?,prior_status = ? WHERE id = ?',[$proposed,$from,$id]);$to='reschedule_requested';$details=['proposed_start'=>$proposed];
        }elseif($action==='accept_reschedule' && $from==='reschedule_requested'){
            if(($input['customer_agreed']??false)!==true)fail(422,'agreement_required','Record customer agreement before changing the appointment.');
            $quote=['snapshot'=>json_decode($record['snapshot_json'],true)];$tz=new DateTimeZone((string)setting($db,'timezone','Europe/Copenhagen'));
            $proposed=(new DateTimeImmutable($record['proposed_start'],new DateTimeZone('UTC')))->setTimezone($tz)->format(DateTimeInterface::ATOM);
            [$start,$end]=allocate_slot($db,$quote,$proposed,$id,[booking_local_day($record)]);
            execute($db,'UPDATE bookings SET starts_at = ?,ends_at = ?,timezone = ?,proposed_start = NULL,prior_status = NULL WHERE id = ?',[$start,$end,$tz->getName(),$id]);
            $details=['previous_start'=>$record['starts_at'],'starts_at'=>$start,'customer_agreed'=>true];$to='confirmed';
        }elseif($action==='withdraw_reschedule' && $from==='reschedule_requested'){
            $to=$record['prior_status'];execute($db,'UPDATE bookings SET proposed_start = NULL,prior_status = NULL WHERE id = ?',[$id]);
        }elseif($action==='amend' && in_array($from,['pending_confirmation','confirmed'],true)){
            if(($input['customer_agreed']??false)!==true)fail(422,'agreement_required','Record customer agreement before changing the service or price.');
            $quote=valid_quote($db,text_field($input,'quote_id',100));
            $start=(new DateTimeImmutable($record['starts_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone((string)setting($db,'timezone','Europe/Copenhagen')))->format(DateTimeInterface::ATOM);
            [$newStart,$end]=allocate_slot($db,$quote,$start,$id,[booking_local_day($record)]);
            execute($db,'UPDATE bookings SET selection_json = ?,snapshot_json = ?,postcode = ?,ends_at = ?,address = ? WHERE id = ?',[json_encode_safe($quote['selection']),json_encode_safe($quote['snapshot']),$quote['selection']['postcode'],$end,text_field($input,'address',500),$id]);
            $details=['previous_quote'=>json_decode($record['snapshot_json'],true),'quote'=>$quote['snapshot'],'customer_agreed'=>true];
        }else fail(422,'invalid_transition','This action is not allowed from the current status.');
        if(in_array($to,['cancelled','rejected'],true))lock_schedule_days($db,[booking_local_day($record)]);
        execute($db,'UPDATE bookings SET status = ?,version = version + 1 WHERE id = ?',[$to,$id]);
        request_history($db,'booking',$id,$from,$to,(int)$admin['id'],$reason?:$action,$details);
        audit($db,(int)$admin['id'],$action,'booking',$id,['from'=>$from,'to'=>$to,...$details]);
        if($notify)queue_request_notifications($db,'booking',$id,$action,(int)$record['version']+1);
        $db->commit();return admin_request_detail($db,'booking',$id);
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}

function update_enquiry(PDO $db,array $admin,int $id,array $input): array
{
    $action=text_field($input,'action',40);$db->beginTransaction();
    try{
        $record=row($db,'SELECT * FROM enquiries WHERE id = ? AND deleted_at IS NULL FOR UPDATE',[$id]);
        if(!$record)fail(404,'not_found','Enquiry not found.');require_request_version($record,$input);
        $from=$record['status'];$to=$from;$reason=text_field($input,'reason',2000,false);
        if($action==='note')execute($db,'INSERT INTO admin_notes (entity_type,entity_id,admin_id,note) VALUES (?,?,?,?)',['enquiry',$id,$admin['id'],text_field($input,'note',4000)]);
        elseif($action==='contacted' && $from==='new_enquiry')$to='contacted';
        elseif($action==='close' && in_array($from,['new_enquiry','contacted'],true)){$to='enquiry_closed';if($reason==='')fail(422,'validation_error','Add a reason for closing the enquiry.');}
        elseif($action==='edit_contact' && $from!=='enquiry_converted'){
            $customer=customer_details(['customer'=>$input['customer']??null,'consent'=>true]);
            execute($db,'UPDATE enquiries SET customer_json = ?,message = ? WHERE id = ?',[json_encode_safe($customer),text_field($input,'message',2000),$id]);
        }else fail(422,'invalid_transition','This action is not allowed from the current status.');
        execute($db,'UPDATE enquiries SET status = ?,version = version + 1 WHERE id = ?',[$to,$id]);
        request_history($db,'enquiry',$id,$from,$to,(int)$admin['id'],$reason?:$action);audit($db,(int)$admin['id'],$action,'enquiry',$id);
        if($action==='close')queue_request_notifications($db,'enquiry',$id,'closed',(int)$record['version']+1);
        $db->commit();return admin_request_detail($db,'enquiry',$id);
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}

function convert_enquiry(PDO $db,array $admin,int $id,array $input): array
{
    if(($input['customer_agreed']??false)!==true)fail(422,'agreement_required','Record customer agreement to the quote and requested time.');
    $db->beginTransaction();
    try{
        $enquiry=row($db,'SELECT * FROM enquiries WHERE id = ? AND deleted_at IS NULL FOR UPDATE',[$id]);
        if(!$enquiry)fail(404,'not_found','Enquiry not found.');require_request_version($enquiry,$input);
        if(!in_array($enquiry['status'],['new_enquiry','contacted'],true) || $enquiry['converted_booking_id'])fail(409,'already_converted','This enquiry has already been converted or closed.');
        $quote=valid_quote($db,text_field($input,'quote_id',100));[$start,$end]=allocate_slot($db,$quote,text_field($input,'starts_at',60));
        $address=text_field($input,'address',500);$reference='SR-'.strtoupper(bin2hex(random_bytes(8)));
        $status=($input['confirm']??false)===true?'confirmed':'pending_confirmation';
        execute($db,'INSERT INTO bookings (reference,status,intent,customer_json,address,postcode,selection_json,snapshot_json,starts_at,ends_at,timezone,message,consent_version,consent_at,source_enquiry_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$reference,$status,'pay_on_completion',$enquiry['customer_json'],$address,$quote['selection']['postcode'],json_encode_safe($quote['selection']),json_encode_safe($quote['snapshot']),$start,$end,setting($db,'timezone','Europe/Copenhagen'),$enquiry['message'],$enquiry['consent_version'],$enquiry['consent_at'],$id]);
        $bookingId=(int)$db->lastInsertId();execute($db,"UPDATE enquiries SET status = 'enquiry_converted',converted_booking_id = ?,version = version + 1 WHERE id = ?",[$bookingId,$id]);
        request_history($db,'enquiry',$id,$enquiry['status'],'enquiry_converted',(int)$admin['id'],'Customer agreed to conversion',['booking_id'=>$bookingId]);
        request_history($db,'booking',$bookingId,null,$status,(int)$admin['id'],'Converted from enquiry',['enquiry_id'=>$id]);
        audit($db,(int)$admin['id'],'convert','enquiry',$id,['booking_id'=>$bookingId]);queue_request_notifications($db,'booking',$bookingId,'converted',1);
        $db->commit();return ['booking'=>admin_request_detail($db,'booking',$bookingId),'enquiry'=>admin_request_detail($db,'enquiry',$id)];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}

function erase_request(PDO $db,array $admin,string $type,int $id,array $input): array
{
    $table=$type==='booking'?'bookings':'enquiries';$db->beginTransaction();
    try{
        $record=row($db,"SELECT * FROM $table WHERE id = ? FOR UPDATE",[$id]);if(!$record)fail(404,'not_found','Request not found.');require_request_version($record,$input);
        $terminal=$type==='booking'?['cancelled','rejected','completed']:['enquiry_closed','enquiry_converted'];
        if(!in_array($record['status'],$terminal,true))fail(422,'active_request','Close or cancel this request before removing personal details.');
        $redacted=json_encode_safe(['full_name'=>'Removed','email'=>'','phone'=>'']);
        $more=$type==='booking'?",address = '',postcode = '',selection_json = JSON_REMOVE(selection_json,'$.postcode')":",context_json = JSON_OBJECT()";
        execute($db,"UPDATE $table SET customer_json = ?,message = '',deleted_at = UTC_TIMESTAMP(),version = version + 1 $more WHERE id = ?",[$redacted,$id]);
        execute($db,'DELETE FROM admin_notes WHERE entity_type = ? AND entity_id = ?',[$type,$id]);
        // Free-text reasons can contain personal details; retain the status/timestamp trail.
        execute($db,"UPDATE request_history SET reason = 'Personal details removed' WHERE entity_type = ? AND entity_id = ?",[$type,$id]);
        $mailIds=array_column(rows($db,'SELECT id FROM notifications WHERE entity_type = ? AND entity_id = ?',[$type,$id]),'id');
        execute($db,'DELETE FROM notifications WHERE entity_type = ? AND entity_id = ?',[$type,$id]);
        audit($db,$admin['id']===null?null:(int)$admin['id'],'erase_personal_data',$type,$id);$db->commit();
        foreach($mailIds as $mailId){$file=mail_capture_directory().'/'.(int)$mailId.'.eml';if(is_file($file))unlink($file);}
        return ['removed'=>true];
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
