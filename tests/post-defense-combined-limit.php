<?php
// Owns a disposable schema and a credential-free app copy. Never selects the source database.
if (PHP_SAPI !== 'cli' || ($argv[1] ?? '') !== '--run' || empty($argv[2])) exit("Usage: php tests/post-defense-combined-limit.php --run /path/to/local/checkout\n");
require rtrim($argv[2], '/').'/includes/db_config.php';
if (!in_array(DB_HOST,['localhost','127.0.0.1','::1'],true)) exit("Local server required.\n");
if ($s=@fsockopen('127.0.0.1',8911)) {fclose($s);exit("Test port is busy.\n");}
$db='bcihs_limit_test_'.bin2hex(random_bytes(4));$root=sys_get_temp_dir().'/'.$db;mkdir($root,0700);$app=$root.'/app';mkdir($app,0700);
$p=new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$checks=0;$server=null;$created=false;$failed=false;
function ok($value,$label) {global $checks;if(!$value)throw new RuntimeException($label);$checks++;}
function client() {global $root;return ['cookie'=>$root.'/cookie-'.bin2hex(random_bytes(4)),'csrf'=>''];}
function handle($session,$endpoint,$data=null,$form=false) {
 $parts=explode('?', $endpoint, 2);$c=curl_init('http://127.0.0.1:8911/backend/'.$parts[0].'.php'.(isset($parts[1])?'?'.$parts[1]:''));
 curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$session['cookie'],CURLOPT_COOKIEJAR=>$session['cookie'],CURLOPT_TIMEOUT=>15]);
 if($data!==null)curl_setopt_array($c,[CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$form?http_build_query($data):json_encode($data),CURLOPT_HTTPHEADER=>$form?[]:['Content-Type: application/json','X-CSRF-Token: '.$session['csrf']]]);
 return $c;
}
function callApi($s,$e,$d=null,$form=false){$c=handle($s,$e,$d,$form);$b=curl_exec($c);$code=curl_getinfo($c,CURLINFO_HTTP_CODE);curl_close($c);return [$code,json_decode((string)$b,true)];}
function book(){global $p;$p->exec('INSERT INTO books(title,author,total_copies) VALUES("Synthetic limit test","Synthetic Author",1)');return (int)$p->lastInsertId();}
function borrower(){global $p;$q=$p->prepare('INSERT INTO users(full_name,student_id,password_hash,role,account_status) VALUES(?,?,?,"student","accepted")');$q->execute(['Synthetic Limit Borrower','LIMIT-'.bin2hex(random_bytes(4)),password_hash('Synthetic-Limit-Only-2026!',PASSWORD_DEFAULT)]);return (int)$p->lastInsertId();}
function loan($u,$status){global $p;$b=book();$q=$p->prepare('INSERT INTO borrow_requests(user_id,book_id,status,pickup_token) VALUES(?,?,?,?)');$q->execute([$u,$b,$status,bin2hex(random_bytes(32))]);$id=(int)$p->lastInsertId();if(in_array($status,['checked_out','approved']))$p->exec('UPDATE books SET borrowed_copies=1 WHERE id='.$b);return $id;}
function reservation($u,$status){global $p;$b=book();$q=$p->prepare('INSERT INTO reservations(user_id,book_id,status,pickup_at) VALUES(?,?,?,?)');$q->execute([$u,$b,$status,(new DateTimeImmutable('next monday'))->format('Y-m-d').' 10:00:00']);$id=(int)$p->lastInsertId();if($status==='fulfilled')$p->exec('UPDATE books SET borrowed_copies=1 WHERE id='.$b);return $id;}
function action($s,$id,$a,$reservation=false,$extra=[]){return callApi($s,$reservation?'reservation-action':'borrow-request-action',['id'=>$id,'action'=>$a]+$extra)[0];}
try {
 $p->exec("CREATE DATABASE `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");$created=true;$p->exec("USE `$db`");
 $sql=file_get_contents(__DIR__.'/../sql/schema.sql');$sql=preg_replace('/^CREATE DATABASE.*?;\s*/ms','',$sql);$sql=preg_replace('/^USE\s+\w+;\s*/m','',$sql);$p->exec($sql);
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__),FilesystemIterator::SKIP_DOTS));
 foreach($it as $f){$rel=substr($f->getPathname(),strlen(dirname(__DIR__))+1);if(!$f->isFile()||$f->isLink()||preg_match('#(^|/)(\.git|uploads|tests|tools|scripts|deployment|vendor)/#',$rel)||preg_match('/(?:local|key|config)\.(?:php|json)$/',basename($rel))&&basename($rel)!=='db_config.php')continue;$dest=$app.'/'.$rel;if(!is_dir(dirname($dest)))mkdir(dirname($dest),0700,true);copy($f->getPathname(),$dest);}
 $env=['PATH'=>getenv('PATH'),'PHP_CLI_SERVER_WORKERS'=>'4','BCIHS_DB_HOST'=>DB_HOST,'BCIHS_DB_PORT'=>DB_PORT,'BCIHS_DB_NAME'=>$db,'BCIHS_DB_USER'=>DB_USER,'BCIHS_DB_PASS'=>DB_PASS,'BCIHS_SMS_PROVIDER'=>'simulation','BCIHS_SMS_MODE'=>'simulate','BCIHS_SMS_LIVE_ENABLED'=>'0'];
 $server=proc_open([PHP_BINARY,'-d','disable_functions=curl_init,curl_exec,curl_multi_init','-d','session.save_path='.$root,'-S','127.0.0.1:8911','-t',$app],[0=>['pipe','r'],1=>['file',$root.'/server.log','a'],2=>['file',$root.'/server.log','a']],$pipes,$app,$env);fclose($pipes[0]);
 for($i=0;$i<30;$i++){if($s=@fsockopen('127.0.0.1',8911)){fclose($s);break;}usleep(100000);}
 $p->prepare('INSERT INTO admins(username,password_hash) VALUES("synthetic-limit-admin",?)')->execute([password_hash('Synthetic-Limit-Only-2026!',PASSWORD_DEFAULT)]);
 $staff=[];for($i=0;$i<2;$i++){$s=client();callApi($s,'admin-login-process',['identifier'=>'synthetic-limit-admin','password'=>'Synthetic-Limit-Only-2026!'],true);[$code,$profile]=callApi($s,'profile-data');ok($code===200,'staff login');$s['csrf']=$profile['csrfToken'];$staff[]=$s;}$a=$staff[0];

 if (($argv[3] ?? '') === '--staff') {
  $lib=borrower();$p->exec('UPDATE users SET role="librarian" WHERE id='.$lib);$s=client();$identifier=$p->query('SELECT student_id FROM users WHERE id='.$lib)->fetchColumn();callApi($s,'admin-login-process',['identifier'=>$identifier,'password'=>'Synthetic-Limit-Only-2026!'],true);[$code,$profile]=callApi($s,'profile-data');ok($code===200,'librarian login');$s['csrf']=$profile['csrfToken'];
  [$code,$settings]=callApi($s,'sms-settings');ok($code===200 && $settings['can_manage'],'librarian may manage SMS');
  $bad=$s;$bad['csrf']='invalid';ok(callApi($bad,'sms-settings',['allowed'=>true,'version'=>$settings['permission']['version'],'confirm_enable'=>true])[0]===403,'SMS CSRF enforced');
  [$code,$settings]=callApi($s,'sms-settings',['allowed'=>true,'version'=>$settings['permission']['version'],'confirm_enable'=>true]);ok($code===200 && $settings['permission']['allowed'],'librarian enables permission in simulation');ok($settings['delivery_mode']==='Simulation','permission never authorizes live sending');ok($settings['audit'][0]['actor_role']==='librarian' && (int)$settings['audit'][0]['admin_id']===$lib,'audit correctly identifies librarian');
  [$code,$settings]=callApi($a,'sms-settings',['allowed'=>false,'version'=>$settings['permission']['version']]);ok($code===200 && !$settings['permission']['allowed'],'administrator retains SMS management');ok($settings['audit'][0]['actor_role']==='admin','administrator audit identity retained');
  [$code,$policy]=callApi($s,'borrowing-policy');ok($code===200 && $policy['can_manage'],'librarian may manage borrowing default');$loan=loan($lib,'checked_out');$beforeLoan=$p->query('SELECT * FROM borrow_requests WHERE id='.$loan)->fetch();
  [$code,$policy]=callApi($s,'borrowing-policy',['days'=>'3','version'=>$policy['version'],'reason'=>'Synthetic staff permission test','confirm_change'=>true]);ok($code===200 && $policy['days']===3,'librarian saves approved default');ok($policy['audit'][0]['actor_role']==='librarian' && (int)$policy['audit'][0]['admin_id']===$lib,'policy audit identifies librarian');ok($p->query('SELECT * FROM borrow_requests WHERE id='.$loan)->fetch()===$beforeLoan,'existing loan unchanged by new default');
  ok(callApi($s,'borrowing-policy',['days'=>'4','version'=>0,'reason'=>'Stale change','confirm_change'=>true])[0]===409,'stale policy changes rejected');
  foreach(['student','teacher','visitor'] as $role){$id=borrower();$p->exec('UPDATE users SET role="'.$role.'" WHERE id='.$id);$other=client();$identifier=$p->query('SELECT student_id FROM users WHERE id='.$id)->fetchColumn();callApi($other,'login-process',['identifier'=>$identifier,'password'=>'Synthetic-Limit-Only-2026!'],true);[, $profile]=callApi($other,'profile-data');$other['csrf']=$profile['csrfToken'];foreach(['sms-settings','borrowing-policy'] as $endpoint)ok(callApi($other,$endpoint,[])[0]===403,$role.' cannot manage '.$endpoint);}
  foreach(['sms-settings','borrowing-policy'] as $endpoint)ok(callApi(client(),$endpoint,[])[0]===401,'guest denied '.$endpoint);
  $p->exec('UPDATE users SET account_status="pending" WHERE id='.$lib);ok(callApi($s,'sms-settings')[0]===401,'unapproved librarian loses management access');
  ok((int)$p->query('SELECT COUNT(*) FROM sms_logs WHERE status="sent"')->fetchColumn()===0,'no sent SMS');
 } else {
 $rejected=borrower();$p->exec('UPDATE users SET account_status="rejected" WHERE id='.$rejected);$pending=borrower();$p->exec('UPDATE users SET account_status="pending" WHERE id='.$pending);$accepted=borrower();
 $usersBefore=$p->query('SELECT * FROM users ORDER BY id')->fetchAll();$logsBefore=$p->query('SELECT * FROM sms_logs ORDER BY id')->fetchAll();
 [$code,$data]=callApi($a,'all-users-data');ok($code===200 && !in_array('rejected',array_column($data['users'],'account_status'),true),'routine API excludes rejected accounts');ok(count($data['users'])===2,'routine API retains pending and approved');ok(count($data['statistics'])===3,'summary statistics preserve all accounts');
 [$code,$data]=callApi($a,'all-users-data?view=rejected');ok($code===200 && count($data['users'])===1 && (int)$data['users'][0]['id']===$rejected,'restricted audit preserves rejected account access');ok(callApi(client(),'all-users-data?view=rejected')[0]===401,'unauthenticated audit denied');
 ok(callApi($a,'all-users-data?view=invalid')[0]===422,'invalid view rejected');ok($p->query('SELECT * FROM users ORDER BY id')->fetchAll()===$usersBefore && $p->query('SELECT * FROM sms_logs ORDER BY id')->fetchAll()===$logsBefore,'all account records and SMS history unchanged');
 if (($argv[3] ?? '') !== '--users') {
 $u=borrower();loan($u,'checked_out');loan($u,'approved');$hold=loan($u,'pending');ok(action($a,$hold,'approve')===200,'third slot approval');$b=book();$student=client();$identifier=$p->query('SELECT student_id FROM users WHERE id='.$u)->fetchColumn();callApi($student,'login-process',['identifier'=>$identifier,'password'=>'Synthetic-Limit-Only-2026!'],true);[, $profile]=callApi($student,'profile-data');$student['csrf']=$profile['csrfToken'];ok(callApi($student,'borrow-request-process',['book_id'=>$b])[0]===409,'fourth request blocked at submission');
 $token=$p->query('SELECT pickup_token FROM borrow_requests WHERE id='.$hold)->fetchColumn();ok(action($a,$hold,'checkout',false,['pickup_token'=>$token])===200,'QR converts third hold without double counting');ok(action($a,$hold,'checkout',false,['pickup_token'=>$token])===409,'QR repeat blocked');ok(action($a,$hold,'return')===200,'return releases slot');$fourth=loan($u,'pending');ok(action($a,$fourth,'approve')===200,'approval after return');ok(action($a,$fourth,'reject')===200,'reject releases hold');
 $r=reservation($u,'waiting');ok(action($a,$r,'ready',true)===200,'Ready uses third slot');$r2=reservation($u,'waiting');ok(action($a,$r2,'ready',true)===409,'fourth Ready blocked');ok(action($a,$r,'fulfill',true)===200,'Ready converts third slot');ok(action($a,$r,'return',true)===200,'reservation return frees slot');ok(action($a,$r2,'fulfill',true)===200,'direct Waiting checkout uses slot');
 $u=borrower();loan($u,'awaiting_pickup');reservation($u,'ready');reservation($u,'fulfilled');foreach(['rejected','returned'] as $st)loan($u,$st);foreach(['waiting','cancelled','returned'] as $st)reservation($u,$st);
 [$c,$d]=callApi($a,'borrow-requests-data');$b=book();[$c]=callApi($a,'borrow-request-add',['user_id'=>$u,'book_id'=>$b,'confirm_checkout'=>true,'checkout_token'=>$d['manualCheckoutToken']]);ok($c===409,'manual blocked by mixed three slots');ok((int)$p->query('SELECT borrowed_copies FROM books WHERE id='.$b)->fetchColumn()===0,'blocked manual preserves inventory');
 $u=borrower();$ready=reservation($u,'ready');ok(action($a,$ready,'cancel',true)===200,'cancel releases allocation');$holds=[];for($i=0;$i<3;$i++){$id=loan($u,'pending');ok(action($a,$id,'approve')===200,'cancelled/Waiting entries do not count');$holds[]=$id;}
 // Independent staff sessions contend for one remaining slot on different books.
 $u=borrower();loan($u,'checked_out');reservation($u,'ready');$x=loan($u,'pending');$y=reservation($u,'waiting');
 $p->beginTransaction();$p->query('SELECT id FROM users WHERE id='.$u.' FOR UPDATE')->fetch();$m=curl_multi_init();$hs=[handle($staff[0],'borrow-request-action',['id'=>$x,'action'=>'approve']),handle($staff[1],'reservation-action',['id'=>$y,'action'=>'ready'])];foreach($hs as $h)curl_multi_add_handle($m,$h);
 $overlap=false;for($i=0;$i<150;$i++){curl_multi_exec($m,$running);$q=$p->prepare('SELECT COUNT(*) FROM performance_schema.data_lock_waits w JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.BLOCKING_ENGINE_LOCK_ID WHERE l.OBJECT_SCHEMA=?');$q->execute([$db]);if((int)$q->fetchColumn()>=2){$overlap=true;break;}usleep(20000);} $p->commit();ok($overlap,'two overlapping transactions wait on borrower lock');
 do{curl_multi_exec($m,$running);if($running)curl_multi_select($m,1);}while($running);$codes=[];foreach($hs as $h){$codes[]=curl_getinfo($h,CURLINFO_HTTP_CODE);curl_multi_remove_handle($m,$h);curl_close($h);}curl_multi_close($m);sort($codes);ok($codes===[200,409],'existing pending slot preserved against competing Ready');
 ok((int)$p->query('SELECT COUNT(*) FROM borrow_requests WHERE user_id='.$u.' AND status="awaiting_pickup"')->fetchColumn()+(int)$p->query('SELECT COUNT(*) FROM reservations WHERE user_id='.$u.' AND status="ready"')->fetchColumn()===2,'two holds plus one loan remain');

 $u=borrower();$student=client();$identifier=$p->query('SELECT student_id FROM users WHERE id='.$u)->fetchColumn();callApi($student,'login-process',['identifier'=>$identifier,'password'=>'Synthetic-Limit-Only-2026!'],true);[, $profile]=callApi($student,'profile-data');$student['csrf']=$profile['csrfToken'];$requests=[];
 foreach([3,2,1] as $remaining){[, $data]=callApi($student,'books-data');ok($data['borrowingCapacity']['remaining']===$remaining,'counter before request');[$code,$data]=callApi($student,'borrow-request-process',['book_id'=>book()]);ok($code===200,'request uses slot');$requests[]=$data['id'];}
 [, $data]=callApi($student,'books-data');ok($data['borrowingCapacity']['remaining']===0,'three pending requests show zero');ok(callApi($student,'borrow-request-process',['book_id'=>book()])[0]===409,'fourth pending refused');
 ok(action($a,$requests[0],'reject')===200,'reject pending');[, $data]=callApi($student,'books-data');ok($data['borrowingCapacity']['remaining']===1,'rejection restores one');
 foreach(array_slice($requests,1) as $id){ok(action($a,$id,'approve')===200,'approve existing slot');ok(action($a,$id,'checkout')===200,'checkout existing slot');}
 [, $data]=callApi($student,'books-data');ok($data['borrowingCapacity']['remaining']===1,'approval checkout keep count');foreach(array_slice($requests,1) as $id)ok(action($a,$id,'return')===200,'return loan');[, $data]=callApi($student,'books-data');ok($data['borrowingCapacity']['remaining']===3,'two returns restore all three');
 // Two genuinely overlapping request submissions compete for the last pending-request slot.
 $u=borrower();loan($u,'checked_out');loan($u,'awaiting_pickup');$sessions=[];
 for($i=0;$i<2;$i++){$s=client();$identifier=$p->query('SELECT student_id FROM users WHERE id='.$u)->fetchColumn();callApi($s,'login-process',['identifier'=>$identifier,'password'=>'Synthetic-Limit-Only-2026!'],true);[, $profile]=callApi($s,'profile-data');$s['csrf']=$profile['csrfToken'];$sessions[]=$s;}
 $books=[book(),book()];$p->beginTransaction();$p->query('SELECT id FROM users WHERE id='.$u.' FOR UPDATE')->fetch();$m=curl_multi_init();$hs=[];foreach($sessions as $i=>$s){$h=handle($s,'borrow-request-process',['book_id'=>$books[$i]]);$hs[]=$h;curl_multi_add_handle($m,$h);}
 $overlap=false;for($i=0;$i<150;$i++){curl_multi_exec($m,$running);$q=$p->prepare('SELECT COUNT(*) FROM performance_schema.data_lock_waits w JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.BLOCKING_ENGINE_LOCK_ID WHERE l.OBJECT_SCHEMA=?');$q->execute([$db]);if((int)$q->fetchColumn()>=2){$overlap=true;break;}usleep(20000);}$p->commit();ok($overlap,'independent request submissions overlap');do{curl_multi_exec($m,$running);if($running)curl_multi_select($m,1);}while($running);$codes=[];foreach($hs as $h){$codes[]=curl_getinfo($h,CURLINFO_HTTP_CODE);curl_multi_remove_handle($m,$h);curl_close($h);}curl_multi_close($m);sort($codes);ok($codes===[200,409],'only one final request slot claimed');

 // Legacy over-limit holds remain intact; only actual fourth physical checkout is refused.
 $u=borrower();loan($u,'checked_out');loan($u,'approved');$held=[];for($i=0;$i<4;$i++)$held[]=loan($u,'awaiting_pickup');
 $token=$p->query('SELECT pickup_token FROM borrow_requests WHERE id='.$held[0])->fetchColumn();[$code,$qr]=callApi($a,'pickup-verification-data?token='.$token);ok($code===200 && $qr['borrowingCapacity']['remaining']===0 && $qr['activeLoanCapacity']['used']===2 && $qr['eligible'],'zero outstanding slots do not disable valid third QR checkout');
 ok(action($a,$held[0],'checkout',false,['pickup_token'=>$token])===200,'legacy third physical checkout allowed');
 $before=$p->query('SELECT * FROM borrow_requests WHERE user_id='.$u.' ORDER BY id')->fetchAll();ok(action($a,$held[1],'checkout')===409,'legacy fourth physical checkout blocked');ok($p->query('SELECT * FROM borrow_requests WHERE user_id='.$u.' ORDER BY id')->fetchAll()===$before,'failed legacy checkout preserves every record');
 $r=reservation($u,'ready');ok(action($a,$r,'fulfill',true)===409,'Ready cannot bypass physical cap');
 $u=borrower();loan($u,'checked_out');reservation($u,'fulfilled');$x=loan($u,'awaiting_pickup');$y=reservation($u,'ready');
 $p->beginTransaction();$p->query('SELECT id FROM users WHERE id='.$u.' FOR UPDATE')->fetch();$m=curl_multi_init();$hs=[handle($staff[0],'borrow-request-action',['id'=>$x,'action'=>'checkout']),handle($staff[1],'reservation-action',['id'=>$y,'action'=>'fulfill'])];foreach($hs as $h)curl_multi_add_handle($m,$h);
 $overlap=false;for($i=0;$i<150;$i++){curl_multi_exec($m,$running);$q=$p->prepare('SELECT COUNT(*) FROM performance_schema.data_lock_waits w JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.BLOCKING_ENGINE_LOCK_ID WHERE l.OBJECT_SCHEMA=?');$q->execute([$db]);if((int)$q->fetchColumn()>=2){$overlap=true;break;}usleep(20000);} $p->commit();ok($overlap,'two legacy checkouts overlap on borrower lock');
 do{curl_multi_exec($m,$running);if($running)curl_multi_select($m,1);}while($running);$codes=[];foreach($hs as $h){$codes[]=curl_getinfo($h,CURLINFO_HTTP_CODE);curl_multi_remove_handle($m,$h);curl_close($h);}curl_multi_close($m);sort($codes);ok($codes===[200,409],'only one legacy fourth-slot contender becomes third physical loan');
 ok((int)$p->query('SELECT (SELECT COUNT(*) FROM borrow_requests WHERE user_id='.$u.' AND status IN ("checked_out","approved"))+(SELECT COUNT(*) FROM reservations WHERE user_id='.$u.' AND status="fulfilled")')->fetchColumn()===3,'legacy overlapping checkouts never exceed three physical loans');
 $u=borrower();[, $data]=callApi($a,'borrow-requests-data');[$code,$manual]=callApi($a,'borrow-request-add',['user_id'=>$u,'book_id'=>book(),'confirm_checkout'=>true,'checkout_token'=>$data['manualCheckoutToken']]);ok($code===200,'manual checkout with available slot');ok(action($a,$manual['id'],'return')===200,'manual return restores capacity');
 // Two waiting users compete for one requestable physical copy.
 $u=borrower();$v=borrower();$r1=reservation($u,'waiting');$book=(int)$p->query('SELECT book_id FROM reservations WHERE id='.$r1)->fetchColumn();$q=$p->prepare('INSERT INTO reservations(user_id,book_id,status,pickup_at) VALUES(?,?,"waiting",?)');$q->execute([$v,$book,(new DateTimeImmutable('next monday'))->format('Y-m-d').' 10:00:00']);$r2=(int)$p->lastInsertId();
 $p->beginTransaction();$p->query('SELECT id FROM books WHERE id='.$book.' FOR UPDATE')->fetch();$m=curl_multi_init();$hs=[handle($staff[0],'reservation-action',['id'=>$r1,'action'=>'ready']),handle($staff[1],'reservation-action',['id'=>$r2,'action'=>'ready'])];foreach($hs as $h)curl_multi_add_handle($m,$h);
 $overlap=false;for($i=0;$i<150;$i++){curl_multi_exec($m,$running);$q=$p->prepare('SELECT COUNT(*) FROM performance_schema.data_lock_waits w JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.BLOCKING_ENGINE_LOCK_ID WHERE l.OBJECT_SCHEMA=?');$q->execute([$db]);if((int)$q->fetchColumn()>=2){$overlap=true;break;}usleep(20000);}$p->commit();ok($overlap,'last-copy allocation transactions overlap');do{curl_multi_exec($m,$running);if($running)curl_multi_select($m,1);}while($running);$codes=[];foreach($hs as $h){$codes[]=curl_getinfo($h,CURLINFO_HTTP_CODE);curl_multi_remove_handle($m,$h);curl_close($h);}curl_multi_close($m);sort($codes);ok($codes===[200,409],'only one last-copy allocation');
 ok((int)$p->query('SELECT COUNT(*) FROM reservations WHERE book_id='.$book.' AND status="ready"')->fetchColumn()===1,'exactly one physical hold');
 ok((int)$p->query('SELECT COUNT(*) FROM sms_logs WHERE status="sent"')->fetchColumn()===0,'no sent SMS');
 }
 }
 echo "Passed $checks isolated workflow checks. No provider HTTP.\n";
} catch(Throwable $e){if($p->inTransaction())$p->rollBack();
 if(isset($m,$hs) && $m instanceof CurlMultiHandle){
  for($i=0;$i<50;$i++){curl_multi_exec($m,$running);if(!$running)break;usleep(20000);}
  foreach($hs as $h){$body=json_decode((string)curl_multi_getcontent($h),true);fwrite(STDERR,json_encode(['synthetic_http'=>curl_getinfo($h,CURLINFO_HTTP_CODE),'reason'=>$body['error']??null]).PHP_EOL);}
 }
 fwrite(STDERR,'FAIL: '.$e->getMessage().PHP_EOL);$failed=true;}

finally {
 $processes=[];exec('ps -ax -o pid=,command=',$processes);foreach($processes as $line)if(preg_match('/^\s*(\d+)\s+(.+)$/',$line,$m)&&str_contains($m[2],'-S 127.0.0.1:8911')&&str_contains($m[2],$app))posix_kill((int)$m[1],SIGTERM);
 if(is_resource($server)){proc_terminate($server);proc_close($server);}if($created)$p->exec("DROP DATABASE `$db`");
 $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $f){if($f->isDir())rmdir($f->getPathname());else unlink($f->getPathname());}rmdir($root);
}
exit($failed?1:0);
