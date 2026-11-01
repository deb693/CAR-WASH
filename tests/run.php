<?php
declare(strict_types=1);
require dirname(__DIR__) . '/app/bootstrap.php';
if (config('DB_DATABASE') !== 'carcare_test') { fwrite(STDERR, "Tests require DB_DATABASE=carcare_test; no other database will be modified.\n"); exit(1); }
$db = database();

function assertion(bool $result, string $name): void {
    if (!$result) throw new RuntimeException('FAIL: ' . $name);
    echo 'PASS: ' . $name . "\n";
}
function expect_error(callable $run, string $code): void {
    try { $run(); } catch (ApiError $e) { assertion($e->errorCode === $code, $code); return; }
    throw new RuntimeException('Expected error: ' . $code);
}
function test_input(): array {
    return ['service_id'=>'full','vehicle_model_id'=>'vw-golf','package_id'=>'full-small-1','add_on_ids'=>[],'postcode'=>'1000'];
}
function booking_input(string $quote, string $start): array {
    return ['quote_id'=>$quote,'starts_at'=>$start,'customer'=>['full_name'=>'Test Customer','email'=>'test@example.test','phone'=>'+4512345678'],'address'=>'TEST ONLY, example address','message'=>'Automated local test','consent'=>true];
}

// A subprocess permits a genuine database race, not a sequential approximation.
if (($argv[1] ?? '') === '--race-worker') {
    $notBefore = (float)($argv[4] ?? 0);
    while (microtime(true) < $notBefore) usleep(1000);
    try { $result = create_request($db, json_decode($argv[2], true), 'pay_on_completion', $argv[3]); echo json_encode_safe(['status'=>'created','reference'=>$result['reference']]); }
    catch (ApiError $e) { echo json_encode_safe(['status'=>$e->errorCode]); }
    exit;
}

foreach (['bookings','enquiries','idempotency','quotes','scheduling_locks','blocked_dates','rate_limits'] as $table) $db->exec('DELETE FROM ' . $table);
$catalogue = catalogue($db);
assertion(count($catalogue['packages']) === 27, 'three tiers for each of nine sample service/class combinations');
$first = create_quote($db, [...test_input(),'price_minor'=>1,'vehicle_class_id'=>'large']);
assertion($first['total_minor'] === 6900, 'server ignores submitted price and class');
$extras = create_quote($db, [...test_input(),'add_on_ids'=>['pet-hair']]);
assertion($extras['total_minor'] === 8400 && $extras['duration_minutes'] === 95, 'extras update server amount and duration');
expect_error(fn()=>create_quote($db,[...test_input(),'postcode'=>'9999']), 'outside_service_area');
expect_error(fn()=>create_quote($db,[...test_input(),'package_id'=>'interior-small-1']), 'invalid_package');
expect_error(fn()=>create_quote($db,[...test_input(),'add_on_ids'=>['fake']]), 'invalid_add_ons');
execute($db,'UPDATE quotes SET expires_at = ? WHERE id = ?',[gmdate('Y-m-d H:i:s',time()-1),$first['quote_id']]);
expect_error(fn()=>valid_quote($db,$first['quote_id']), 'quote_expired');
$stale=create_quote($db,test_input());
execute($db,'UPDATE packages SET price_minor = price_minor + 1 WHERE id = ?',['full-small-1']);
expect_error(fn()=>valid_quote($db,$stale['quote_id']), 'quote_changed');
execute($db,'UPDATE packages SET price_minor = price_minor - 1 WHERE id = ?',['full-small-1']);
execute($db,'UPDATE packages SET active = 0 WHERE id = ?',['full-small-3']);
expect_error(fn()=>create_quote($db,test_input()), 'packages_unavailable');
execute($db,'UPDATE packages SET active = 1 WHERE id = ?',['full-small-3']);
$quote=create_quote($db,test_input());
$day=(new DateTimeImmutable('tomorrow',new DateTimeZone('UTC')))->modify('+2 days');
while($day->format('N')==='7')$day=$day->modify('+1 day');
$slots=available_slots($db,valid_quote($db,$quote['quote_id']),$day->format('Y-m-d'));
assertion(count($slots)>2, 'future duration-aware slots are available');
expect_error(fn()=>allocate_slot($db,valid_quote($db,$quote['quote_id']),'tomorrow 9am'),'invalid_slot');
$input=booking_input($quote['quote_id'],$slots[0]['starts_at']);
$key=bin2hex(random_bytes(16));
$booked=create_request($db,$input,'pay_on_completion',$key);
assertion($booked['status']==='pending_confirmation', 'request is pending, not confirmed');
$again=create_request($db,$input,'pay_on_completion',$key);
assertion($again['reference']===$booked['reference'] && (int)$db->query('SELECT COUNT(*) FROM bookings')->fetchColumn()===1, 'retry returns original reference without duplicate');
expect_error(fn()=>create_request($db,[...$input,'message'=>'changed'],'pay_on_completion',$key),'idempotency_conflict');
expect_error(fn()=>create_request($db,booking_input($quote['quote_id'],$slots[1]['starts_at']),'pay_on_completion',bin2hex(random_bytes(16))),'slot_unavailable');
$enquiry=create_request($db,['customer'=>$input['customer'],'service_id'=>'full','vehicle'=>'Other / unknown','postcode'=>'9999','message'=>'Out-of-area test enquiry','consent'=>true],'send_enquiry',bin2hex(random_bytes(16)));
assertion($enquiry['status']==='new_enquiry' && (int)$db->query('SELECT COUNT(*) FROM bookings')->fetchColumn()===1, 'unsupported-area enquiry needs no quote or slot and reserves nothing');
$snapshot=row($db,'SELECT snapshot_json FROM bookings WHERE reference = ?',[$booked['reference']]);
execute($db,'UPDATE packages SET price_minor = price_minor + 100 WHERE id = ?',['full-small-1']);
assertion(row($db,'SELECT snapshot_json FROM bookings WHERE reference = ?',[$booked['reference']])===$snapshot,'historical booking snapshot remains immutable after price edit');
execute($db,'UPDATE packages SET price_minor = price_minor - 100 WHERE id = ?',['full-small-1']);

// Workers compete for overlapping intervals with DIFFERENT start times.
$raceDay=$day->modify('+2 days');
while($raceDay->format('N')==='7')$raceDay=$raceDay->modify('+1 day');
$raceQuote=create_quote($db,test_input());
$raceSlots=available_slots($db,valid_quote($db,$raceQuote['quote_id']),$raceDay->format('Y-m-d'));
$processes=[];
$notBefore = (string)(microtime(true) + 0.4);
foreach([0,1] as $index){
    $pipes=[];
    $command=[PHP_BINARY,__FILE__,'--race-worker',json_encode_safe(booking_input($raceQuote['quote_id'],$raceSlots[$index]['starts_at'])),bin2hex(random_bytes(16)),$notBefore];
    $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]);$processes[]=[$process,$pipes];
}
$results=[];
foreach($processes as [$process,$pipes]){
    $output=stream_get_contents($pipes[1]);$stderr=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    assertion(proc_close($process)===0 && $stderr==='','race worker completed cleanly');
    $results[]=json_decode($output,true,512,JSON_THROW_ON_ERROR)['status'];
}
sort($results);
assertion($results===['created','slot_unavailable'],'simultaneous overlapping submissions cannot exceed final capacity');
echo "All domain/database checks passed against MySQL. Test data is isolated in carcare_test.\n";
