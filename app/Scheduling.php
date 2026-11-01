<?php
declare(strict_types=1);

function scheduling_window(string $date, array $quote): array
{
    $db=database();$tz=new DateTimeZone((string)setting($db,'timezone','Europe/Copenhagen'));
    $day=DateTimeImmutable::createFromFormat('!Y-m-d',$date,$tz);
    if(!$day || $day->format('Y-m-d')!==$date)fail(422,'invalid_date','Choose a valid date.');
    $today=new DateTimeImmutable('today',$tz);$horizon=(int)setting($db,'booking_horizon_days',60);
    if($day<$today || $day>$today->modify("+$horizon days"))fail(422,'invalid_date',"Choose a date within the next $horizon days.");
    return [$day,(int)$quote['snapshot']['duration_minutes']+(int)setting($db,'buffer_minutes',15)];
}

function lock_schedule_days(PDO $db,array $days): void
{
    $days=array_values(array_unique($days));sort($days);
    foreach($days as $day){execute($db,'INSERT IGNORE INTO scheduling_locks (day) VALUES (?)',[$day]);row($db,'SELECT day FROM scheduling_locks WHERE day = ? FOR UPDATE',[$day]);}
}

function interval_has_capacity(PDO $db,DateTimeImmutable $start,DateTimeImmutable $end,?int $capacity=null,?int $excludeId=null): bool
{
    $utc=new DateTimeZone('UTC');$from=$start->setTimezone($utc)->format('Y-m-d H:i:s');$to=$end->setTimezone($utc)->format('Y-m-d H:i:s');
    $sql="SELECT starts_at,ends_at FROM bookings WHERE status IN ('pending_confirmation','confirmed','reschedule_requested') AND starts_at < ? AND ends_at > ?";$args=[$to,$from];
    if($excludeId!==null){$sql.=' AND id != ?';$args[]=$excludeId;}
    $events=[];
    foreach(rows($db,$sql,$args) as $booking){$events[]=[max($from,$booking['starts_at']),1];$events[]=[min($to,$booking['ends_at']),-1];}
    usort($events,fn($a,$b)=>($a[0]<=>$b[0])?:($a[1]<=>$b[1]));$active=0;$capacity??=1;
    foreach($events as $event){$active+=$event[1];if($active>=$capacity)return false;}
    return true;
}

function available_slots(PDO $db,array $quote,string $date,?int $excludeId=null): array
{
    [$day,$duration]=scheduling_window($date,$quote);
    $block=row($db,'SELECT * FROM blocked_dates WHERE day = ? AND deleted_at IS NULL',[$date]);
    if($block && $block['starts_at']===null)return [];
    $rules=rows($db,'SELECT * FROM availability_rules WHERE weekday = ? AND active = 1 AND deleted_at IS NULL ORDER BY opens_at',[(int)$day->format('N')]);
    $earliest=time()+(int)setting($db,'lead_minutes',60)*60;$slots=[];
    foreach($rules as $rule){
        [$oh,$om]=array_map('intval',explode(':',$rule['opens_at']));[$ch,$cm]=array_map('intval',explode(':',$rule['closes_at']));
        $close=$day->setTime($ch,$cm);$interval=(int)$rule['interval_minutes'];
        for($start=$day->setTime($oh,$om);$start->modify("+$duration minutes")<=$close;$start=$start->modify("+$interval minutes")){
            $end=$start->modify("+$duration minutes");
            if($start->getTimestamp()<$earliest)continue;
            if($block && $start->format('H:i:s')<$block['ends_at'] && $end->format('H:i:s')>$block['starts_at'])continue;
            if(!interval_has_capacity($db,$start,$end,(int)$rule['capacity'],$excludeId))continue;
            $slots[]=['starts_at'=>$start->format(DateTimeInterface::ATOM),'label'=>$start->format('H:i'),'duration_minutes'=>$duration];
        }
    }
    return $slots;
}

function allocate_slot(PDO $db,array $quote,string $requested,?int $excludeId=null,array $otherDays=[]): array
{
    if(!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/D',$requested))fail(422,'invalid_slot','Choose an available time with a timezone.');
    try{$start=new DateTimeImmutable($requested);}catch(Exception){fail(422,'invalid_slot','Choose an available time.');}
    $start=$start->setTimezone(new DateTimeZone((string)setting($db,'timezone','Europe/Copenhagen')));$date=$start->format('Y-m-d');
    lock_schedule_days($db,[$date,...$otherDays]);$valid=null;
    foreach(available_slots($db,$quote,$date,$excludeId) as $slot)if($slot['starts_at']===$start->format(DateTimeInterface::ATOM))$valid=$slot;
    if(!$valid)fail(409,'slot_unavailable','This time is no longer available. Please choose another.');
    $utc=new DateTimeZone('UTC');
    return [$start->setTimezone($utc)->format('Y-m-d H:i:s'),$start->modify('+'.$valid['duration_minutes'].' minutes')->setTimezone($utc)->format('Y-m-d H:i:s')];
}
