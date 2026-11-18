<?php
if(!isset($app,$p,$env,$db)||!str_starts_with($db,'bcihs_registration_test_'))throw new RuntimeException('Owned disposable runner required.');
$startChecks=$checks;
// Only this temporary module is instrumented; real transport functions are disabled in workers.
$path=$app.'/includes/sms.php';$text=file_get_contents($path);$text=str_replace('function smsTransport(array $payload): array','function disabledOriginalTransport(array $payload): array',$text);
$text.= <<<'MOCK'

function smsTransport(array $payload): array {
    if(getenv('BCIHS_MOCK_DELAY')==='1')usleep(400000);
    $path=getenv('BCIHS_MOCK_CALL_FILE');
    file_put_contents($path,"attempt\n",FILE_APPEND|LOCK_EX);
    switch(getenv('BCIHS_MOCK_RESULT')){
        case 'exit':exit(6);
        case 'timeout':return ['error'=>true];
        case '503':return ['http'=>503,'body'=>'temporary failure'];
        case '422':return ['http'=>422,'body'=>'{"errors":{"content":["Invalid content"]}}'];
        case 'malformed':return ['http'=>201,'body'=>'invalid'];
    }
    if(($payload['provider']??'')==='unisms')return ['http'=>201,'body'=>json_encode(['message'=>['recipient'=>$payload['body']['recipient'],'reference_id'=>'msg_synthetic','status'=>'pending']])];
    return ['http'=>200,'body'=>json_encode([['recipient'=>$payload['number'],'message_id'=>123,'status'=>'Queued']])];
}
MOCK;
file_put_contents($path,$text);
$wrapper=$root.'/reminder-worker.php';file_put_contents($wrapper,"<?php\nrequire ".var_export($app.'/includes/db_connect.php',true).";\nrequire ".var_export($app.'/includes/sms-reminders.php',true).";\necho json_encode(runDueReminders(\$pdo,\$argv[1]==='dry',new DateTimeImmutable(\$argv[2],new DateTimeZone('Asia/Manila')))),PHP_EOL;\n");
$calls=$root.'/mock-calls';$reminderEnv=$env;$reminderEnv['BCIHS_DB_NAME']=$db;$reminderEnv['BCIHS_MOCK_CALL_FILE']=$calls;
foreach(['BCIHS_SMS_PROVIDER','BCIHS_SMS_MODE','BCIHS_SMS_LIVE_ENABLED'] as $key)unset($reminderEnv[$key]);
$reminderEnv['SEMAPHORE_API_KEY']='SYNTHETIC_ONLY';
$configure=static function(string $provider='unisms',string $mode='live')use($app):void {
    file_put_contents($app.'/includes/sms-local.php','<?php return '.var_export(['provider'=>$provider,'mode'=>$mode,'live_enabled'=>true,'unisms_secret'=>'SYNTHETIC_ONLY','unisms_delivery'=>'production','unisms_production_authorized'=>true,'live_test'=>null,'semaphore_sender_status'=>'approved'],true).';');
};
$runReminder=static function(string $mode='run',string $fault='',string $time='2026-11-18 09:00:00')use($wrapper,$reminderEnv,$root):array {
    $e=$reminderEnv;$e['BCIHS_MOCK_RESULT']=$fault;
    $proc=proc_open([PHP_BINARY,'-d','disable_functions=curl_init,curl_exec,curl_multi_init',$wrapper,$mode,$time],[0=>['pipe','r'],1=>['pipe','w'],2=>['file',$root.'/reminder-errors','a']],$pipes,null,$e);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);fclose($pipes[1]);$code=proc_close($proc);return [$code,json_decode($out,true)];
};
$callCount=static fn()=>is_file($calls)?count(file($calls)):0;
$p->exec('DELETE FROM sms_logs');
$p->exec('UPDATE borrow_requests SET due_date=NULL');$p->exec('UPDATE reservations SET due_date=NULL');
$p->exec('INSERT INTO sms_notification_settings(id,allow_notifications,version) VALUES(1,1,0) ON DUPLICATE KEY UPDATE allow_notifications=1');
$u=phaseUser();$q=$p->prepare('UPDATE users SET phone=? WHERE id=?');$q->execute(['09170005555',$u]);
$ids=[];
foreach(['checked_out','approved','returned','pending','rejected','awaiting_pickup'] as $state){$id=phaseBorrow($u,phaseBook(in_array($state,['checked_out','approved'])?1:0),$state);$p->exec('UPDATE borrow_requests SET due_date="2026-11-19 23:59:59" WHERE id='.$id);$ids[$state]=$id;}
$r=phaseReservation($u,phaseBook(1),'fulfilled');$p->exec('UPDATE reservations SET due_date="2026-11-19 00:00:00" WHERE id='.$r);
foreach(['waiting','ready','cancelled','returned'] as $state){$id=phaseReservation($u,phaseBook(),$state);$p->exec('UPDATE reservations SET due_date="2026-11-19 12:00:00" WHERE id='.$id);}
foreach(['2026-11-18 23:59:59','2026-11-17 09:00:00','2026-11-20 00:00:00'] as $date){$id=phaseBorrow($u,phaseBook(1),'checked_out');$q=$p->prepare('UPDATE borrow_requests SET due_date=? WHERE id=?');$q->execute([$date,$id]);}
$configure();[$code,$result]=$runReminder('dry');check($code===0&&$result['candidates']===3,'dry run selects ordinary legacy and reservation tomorrow only');check($callCount()===0&&(int)$p->query('SELECT COUNT(*) FROM sms_logs')->fetchColumn()===0,'dry run writes no logs or transport');
[$code,$result]=$runReminder('run','','2026-11-18 08:59:59');check($result['before_schedule']&&$callCount()===0,'no early scheduler sends');
[$code,$result]=$runReminder();check($code===0&&$result['reserved']===3&&$callCount()===3,'three correct mocked submissions');
foreach($p->query('SELECT user_id,phone,message,response FROM sms_logs')->fetchAll(PDO::FETCH_ASSOC) as $log){$meta=json_decode($log['response'],true);check($meta['event']==='due_reminder'&&$meta['state']==='pending','monitor-compatible reminder status');check((int)$log['user_id']===$u&&$log['phone']==='09170005555','owner-linked recipient');check(str_contains($log['message'],'Nov 19, 2026')&&strlen($log['message'])<=160,'short exact due-date template');}
[$code,$result]=$runReminder();check($result['reserved']===0&&$result['already_processed']===3&&$callCount()===3,'repeated run no replay');
[$code,$result]=$runReminder('run','','2026-11-19 09:00:00');check($result['candidates']===1,'next day does not catch up yesterday reminders');
// Each isolated fault/permission case uses a fresh due date, never a real provider.
foreach(['off','simulate','invalid','missing','422','503','timeout','malformed','exit','semaphore'] as $i=>$case){
 $day=20+$i;$due='2026-11-'.$day.' 12:00:00';$now='2026-11-'.($day-1).' 09:00:00';
 $q=$p->prepare('UPDATE borrow_requests SET due_date=? WHERE id=?');$q->execute([$due,$ids['checked_out']]);
 // Remove the other active dates from this case's range.
 $p->exec('UPDATE users SET phone="09170005555" WHERE id='.$u);$p->exec('UPDATE sms_notification_settings SET allow_notifications='.($case==='off'?0:1).' WHERE id=1');
 if($case==='invalid')$p->exec('UPDATE users SET phone="invalid" WHERE id='.$u);
 if($case==='missing')$p->exec('UPDATE users SET phone=NULL WHERE id='.$u);
 $configure($case==='semaphore'?'semaphore':'unisms',$case==='simulate'?'simulate':'live');
 $beforeCalls=$callCount();[$code,$result]=$runReminder('run',$case,$now);$log=$p->query('SELECT response FROM sms_logs ORDER BY id DESC LIMIT 1')->fetchColumn();$meta=json_decode($log,true);
 $expected=match($case){'off','invalid','missing'=>'skipped','simulate'=>'simulated','422'=>'failed','503','timeout','malformed'=>'unknown','exit'=>'pending','semaphore'=>'queued'};
 check($meta['state']===$expected,'safe outcome '.$case);
 check($callCount()===$beforeCalls+(in_array($case,['off','simulate','invalid','missing'])?0:1),'transport budget '.$case);
 $beforeCalls=$callCount();$runReminder('run','',$now);check($callCount()===$beforeCalls,'no automatic resubmission after '.$case);
}
// Returned before execution is excluded, regardless of a future date.
$p->exec('UPDATE borrow_requests SET status="returned",due_date="2026-12-01 12:00:00" WHERE id='.$ids['checked_out']);$configure();[$code,$result]=$runReminder('run','','2026-11-30 09:00:00');check($result['candidates']===0,'return before run prevents reminder');
echo 'Reminder checks so far: '.($checks-$startChecks).PHP_EOL;

$launch=static function(string $now,array $extra=[])use($wrapper,$reminderEnv,$root):array {
 $e=array_merge($reminderEnv,$extra);$proc=proc_open([PHP_BINARY,'-d','disable_functions=curl_init,curl_exec,curl_multi_init',$wrapper,'run',$now],[0=>['pipe','r'],1=>['pipe','w'],2=>['file',$root.'/reminder-errors','a']],$pipes,null,$e);fclose($pipes[0]);return [$proc,$pipes[1]];
};
$finish=static function(array $worker):array {[$proc,$out]=$worker;$body=stream_get_contents($out);fclose($out);$code=proc_close($proc);return [$code,json_decode($body,true)];};
$configure();$p->exec('UPDATE users SET phone="09170005555" WHERE id='.$u);$p->exec('UPDATE sms_notification_settings SET allow_notifications=1 WHERE id=1');
$p->exec('UPDATE borrow_requests SET status="checked_out",due_date="2026-12-02 23:59:59" WHERE id='.$ids['checked_out']);
$loanBefore=$p->query('SELECT * FROM borrow_requests ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$beforeCalls=$callCount();$one=$launch('2026-12-01 09:00:00',['BCIHS_MOCK_DELAY'=>'1']);$two=$launch('2026-12-01 09:00:00',['BCIHS_MOCK_DELAY'=>'1']);[$c1,$r1]=$finish($one);[$c2,$r2]=$finish($two);
check($c1===0&&$c2===0,'independent scheduler processes exit safely');check($callCount()===$beforeCalls+1,'concurrent scheduler submits once');check(!empty($r1['busy'])||!empty($r2['busy']),'concurrent process observes database scheduler lock');
check($p->query('SELECT * FROM borrow_requests ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$loanBefore,'reminders never mutate borrowing records');
// Pause at the existing permission lock, change eligibility, then allow the authoritative recheck.
foreach(['return','date','phone'] as $case){
 $p->exec('UPDATE borrow_requests SET status="checked_out",due_date="2026-12-04 12:00:00" WHERE id='.$ids['checked_out']);
 $p->exec('UPDATE users SET phone="09170005555" WHERE id='.$u);
 $permissionLock=smsPermissionLock($p);$beforeCalls=$callCount();$logs=(int)$p->query('SELECT COUNT(*) FROM sms_logs')->fetchColumn();
 try {
  $worker=$launch('2026-12-03 09:00:00');$waiting=false;
  for($i=0;$i<100;$i++){$q=$p->prepare("SELECT COUNT(*) FROM information_schema.processlist WHERE DB=? AND STATE='User lock'");$q->execute([$db]);if((int)$q->fetchColumn()>0){$waiting=true;break;}usleep(10000);}
  check($waiting,'worker waits before event reservation: '.$case);
  if($case==='return')$p->exec('UPDATE borrow_requests SET status="returned" WHERE id='.$ids['checked_out']);
  elseif($case==='date')$p->exec('UPDATE borrow_requests SET due_date="2026-12-05 12:00:00" WHERE id='.$ids['checked_out']);
  else $p->exec('UPDATE users SET phone=NULL WHERE id='.$u);
 } finally {smsPermissionUnlock($p,$permissionLock);}
 [$code,$res]=$finish($worker);check($code===0,'recheck worker exits safely: '.$case);
 check($callCount()===$beforeCalls,'no transport after eligibility/phone change: '.$case);
 check((int)$p->query('SELECT COUNT(*) FROM sms_logs')->fetchColumn()===$logs+($case==='phone'?1:0),'only eligible reminder reserves a log: '.$case);
 // Keep each artificial test independent; deletion is confined to this owned disposable database.
 if($case==='phone')$p->exec('DELETE FROM sms_logs WHERE id=(SELECT id FROM (SELECT MAX(id) AS id FROM sms_logs) latest)');
}
// A UTC timestamp at Manila midnight selects the same local tomorrow boundary.
[$code,$res]=$runReminder('dry','','2026-12-03 01:00:00+00:00');check($code===0,'offset time accepted and normalized to Manila');
echo 'Total reminder checks: '.($checks-$startChecks).PHP_EOL;

mkdir($app.'/scripts',0700);copy(__DIR__.'/../scripts/send-due-reminders.php',$app.'/scripts/send-due-reminders.php');
$beforeCalls=$callCount();$logs=(int)$p->query('SELECT COUNT(*) FROM sms_logs')->fetchColumn();
$proc=proc_open([PHP_BINARY,'-d','disable_functions=curl_init,curl_exec,curl_multi_init',$app.'/scripts/send-due-reminders.php','--dry-run'],[0=>['pipe','r'],1=>['pipe','w'],2=>['file',$root.'/reminder-errors','a']],$pipes,null,$reminderEnv);fclose($pipes[0]);$result=json_decode(stream_get_contents($pipes[1]),true);fclose($pipes[1]);check(proc_close($proc)===0&&!empty($result['dry_run']),'actual CLI dry-run is non-sending');check($callCount()===$beforeCalls&&(int)$p->query('SELECT COUNT(*) FROM sms_logs')->fetchColumn()===$logs,'CLI dry-run no provider/log writes');
$c=curl_init('http://127.0.0.1:8906/scripts/send-due-reminders.php');curl_setopt($c,CURLOPT_RETURNTRANSFER,true);curl_exec($c);check(curl_getinfo($c,CURLINFO_HTTP_CODE)===404,'runner refuses HTTP invocation');curl_close($c);
echo 'Final reminder checks: '.($checks-$startChecks).PHP_EOL;

$message=smsEventMessage('due_reminder',['due_date'=>'2026-11-19 23:59:59','title'=>str_repeat('長い題名',100)]);
check($message==='BCIHS Library: Reminder: Your borrowed book is due tomorrow, Nov 19, 2026. Please return it on time. Thank you.','exact title-independent reminder template');
check(smsUniSegments($message)===1&&strlen($message)<=160,'one GSM-7 segment');
$never=static function():array {throw new RuntimeException('Unauthorized transport reached.');};
$config=['provider'=>'unisms','mode'=>'live','live_enabled'=>true,'key'=>'SYNTHETIC','unisms_delivery'=>'acceptance','live_test'=>null];
$result=smsDispatch($config,'09170005555',$message,$never,['event'=>'due_reminder','event_id'=>'borrow:1:due:20261119','user_id'=>1]);check($result['state']==='skipped','acceptance policy never permits reminder');
check(!smsUniProductionScope(['event'=>'due_reminder','event_id'=>'borrow:1','user_id'=>1]),'reminder requires source-specific due-date binding');
echo 'Verified reminder checks: '.($checks-$startChecks).PHP_EOL;
