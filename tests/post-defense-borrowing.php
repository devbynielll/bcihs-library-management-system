<?php
// Included by the owning isolated registration runner, with HTTP provider transport disabled.
if(!isset($db)||!str_starts_with($db,'bcihs_registration_test_'))throw new RuntimeException('Isolated runner required.');
function phaseStaff():array {global $pass;$s=client();request('admin-login-process',$s,['identifier'=>'synthetic-admin','password'=>$pass]);[, $v]=request('profile-data',$s);$s['csrf']=$v['csrfToken'];return $s;}
function phaseBook(int $borrowed=0,int $copies=1):int {global $p;$q=$p->prepare('INSERT INTO books(title,author,total_copies,borrowed_copies) VALUES(?,?,?,?)');$q->execute(['Synthetic '.bin2hex(random_bytes(4)),'Synthetic Author',$copies,$borrowed]);return (int)$p->lastInsertId();}
function phaseBorrow(int $uid,int $book,string $status):int {global $p;$q=$p->prepare('INSERT INTO borrow_requests(user_id,book_id,status,pickup_token,approved_at,checked_out_at,due_date) VALUES(?,?,?,?,NOW(),NOW(),DATE_ADD(NOW(),INTERVAL 14 DAY))');$q->execute([$uid,$book,$status,bin2hex(random_bytes(32))]);return (int)$p->lastInsertId();}
function phaseReservation(int $uid,int $book,string $status):int {global $p;$date=(new DateTimeImmutable('next monday'))->format('Y-m-d').' 10:00:00';$q=$p->prepare('INSERT INTO reservations(user_id,book_id,status,pickup_at) VALUES(?,?,?,?)');$q->execute([$uid,$book,$status,$date]);return (int)$p->lastInsertId();}
function overlap(array $jobs,string $lockSql):array {
 global $p,$db;
 $p->beginTransaction();$p->query($lockSql)->fetchAll();$multi=curl_multi_init();$handles=[];
 try {
 foreach($jobs as [$endpoint,$session,$data]){$c=curl_init('http://127.0.0.1:8906/backend/'.$endpoint.'.php');curl_setopt_array($c,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_COOKIEFILE=>$session['cookie'],CURLOPT_TIMEOUT=>15,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>json_encode($data),CURLOPT_HTTPHEADER=>['Content-Type: application/json','X-CSRF-Token: '.$session['csrf']]]);$handles[]=$c;curl_multi_add_handle($multi,$c);}
 $waits=0;
 for($i=0;$i<250;$i++){curl_multi_exec($multi,$running);$q=$p->prepare('SELECT COUNT(*) FROM performance_schema.data_lock_waits w JOIN performance_schema.data_locks l ON l.ENGINE_LOCK_ID=w.BLOCKING_ENGINE_LOCK_ID WHERE l.OBJECT_SCHEMA=?');$q->execute([$db]);$waits=(int)$q->fetchColumn();if($waits>=2)break;usleep(20000);}
 if($waits<2){$diag=[];foreach($handles as $h)$diag[]=['http'=>curl_getinfo($h,CURLINFO_HTTP_CODE),'response'=>json_decode(curl_multi_getcontent($h),true)];fwrite(STDERR,json_encode(['lock_waits'=>$waits,'synthetic_responses'=>$diag]).PHP_EOL);}
 check($waits>=2,'two real independent HTTP transactions overlap on InnoDB locks');$p->commit();
 do {curl_multi_exec($multi,$running);if($running)curl_multi_select($multi,1);}while($running);
 $codes=[];foreach($handles as $c)$codes[]=curl_getinfo($c,CURLINFO_HTTP_CODE);sort($codes);return $codes;
 }finally{if($p->inTransaction())$p->rollBack();foreach($handles as $c){curl_multi_remove_handle($multi,$c);curl_close($c);}curl_multi_close($multi);}
}
$a=phaseStaff();$b=phaseStaff();$uid=(int)$users[0]['id'];$other=(int)$users[1]['id'];
$p->exec('INSERT IGNORE INTO reservation_settings(id,daily_limit,slot_limit) VALUES(1,NULL,NULL)');
// Staff allocation: two waiters, one actual free copy.
$book=phaseBook();$r1=phaseReservation($uid,$book,'waiting');$r2=phaseReservation($other,$book,'waiting');
$codes=overlap([['reservation-action',$a,['id'=>$r1,'action'=>'ready']],['reservation-action',$b,['id'=>$r2,'action'=>'ready']]],'SELECT id FROM books WHERE id='.$book.' FOR UPDATE');check($codes===[200,409],'one Ready allocation only');
check((int)$p->query('SELECT COUNT(*) FROM reservations WHERE book_id='.$book.' AND status="ready"')->fetchColumn()===1,'one protected hold');
// Ordinary approval versus reservation allocation.
$book=phaseBook();$br=phaseBorrow($uid,$book,'pending');$r=phaseReservation($other,$book,'waiting');
$codes=overlap([['borrow-request-action',$a,['id'=>$br,'action'=>'approve']],['reservation-action',$b,['id'=>$r,'action'=>'ready']]],'SELECT id FROM books WHERE id='.$book.' FOR UPDATE');check($codes===[200,409],'borrow approval and Ready share last-copy allocation');
// Two users directly reserving an available copy are both told to request it instead.
$sa=client();$sb=client();foreach([&$sa,&$sb] as $i=>&$s){$u=$users[$i];request('login-process',$s,['identifier'=>$u['student_id']?:$u['id_number'],'password'=>$pass]);[, $v]=request('profile-data',$s);$s['csrf']=$v['csrfToken'];}unset($s);
$book=phaseBook();$date=(new DateTimeImmutable('next monday'))->format('Y-m-d').'T10:00';
$codes=overlap([['reservation-process',$sa,['book_id'=>$book,'pickup_at'=>$date]],['reservation-process',$sb,['book_id'=>$book,'pickup_at'=>$date]]],'SELECT id FROM books WHERE id='.$book.' FOR UPDATE');check($codes===[409,409],'available copy is not waitlisted or double allocated');
// When there are no copies, multiple Waiting entries are legitimate and allocate nothing.
$book=phaseBook(1);phaseBorrow((int)$users[2]['id'],$book,'checked_out');$codes=overlap([['reservation-process',$sa,['book_id'=>$book,'pickup_at'=>$date]],['reservation-process',$sb,['book_id'=>$book,'pickup_at'=>$date]]],'SELECT id FROM books WHERE id='.$book.' FOR UPDATE');check($codes===[200,200],'multiple unallocated waitlist entries preserved');
check((int)$p->query('SELECT COUNT(*) FROM reservations WHERE book_id='.$book.' AND status="waiting"')->fetchColumn()===2,'both entries remain waiting');
echo "Reservation baseline: last-copy allocation protected; multiple Waiting entries are not allocations.\n";
function phaseUser():int {global $p,$pass;$q=$p->prepare('INSERT INTO users(full_name,student_id,password_hash,role,account_status) VALUES(?,?,?,"student","accepted")');$q->execute(['Synthetic Borrower','CAP-'.bin2hex(random_bytes(4)),password_hash($pass,PASSWORD_DEFAULT)]);return (int)$p->lastInsertId();}
function phaseAction(array &$s,int $id,string $action,string $endpoint='borrow-request-action',array $extra=[]):int {[$code]=request($endpoint,$s,['id'=>$id,'action'=>$action]+$extra,true);return $code;}
// At 0, 1 and 2 physical loans the next checkout succeeds; at 3 it rolls back.
for($count=0;$count<=3;$count++){
 $u=phaseUser();for($i=0;$i<$count;$i++)phaseBorrow($u,phaseBook(1),$i===0?'approved':'checked_out');
 foreach(['rejected','returned'] as $status)phaseBorrow($u,phaseBook(),$status);
 foreach(['waiting','cancelled','returned'] as $status)phaseReservation($u,phaseBook(),$status);
 $book=phaseBook();$id=phaseBorrow($u,$book,'awaiting_pickup');
 $q=$p->query('SELECT pickup_token FROM borrow_requests WHERE id='.$id);$token=$q->fetchColumn();
 $code=phaseAction($a,$id,'checkout','borrow-request-action',['pickup_token'=>$token]);check($code===($count<3?200:409),'QR checkout count '.$count);
 check((int)$p->query('SELECT borrowed_copies FROM books WHERE id='.$book)->fetchColumn()===($count<3?1:0),'limit rollback stock '.$count);
 if($count<3){check(phaseAction($a,$id,'checkout','borrow-request-action',['pickup_token'=>$token])===409,'QR repeat cannot checkout twice');}
 if($count===3){$returnId=(int)$p->query('SELECT id FROM borrow_requests WHERE user_id='.$u.' AND status="approved"')->fetchColumn();check(phaseAction($a,$returnId,'return')===200,'legacy return frees slot');check(phaseAction($a,$id,'checkout')===200,'checkout after return');}
}
// Mixed ordinary and reservation-based loans share the same cap.
$u=phaseUser();phaseBorrow($u,phaseBook(1),'checked_out');phaseReservation($u,phaseBook(1),'fulfilled');
$book=phaseBook();$r=phaseReservation($u,$book,'ready');check(phaseAction($a,$r,'fulfill','reservation-action')===200,'reservation becomes third loan');
$book2=phaseBook();$r2=phaseReservation($u,$book2,'ready');check(phaseAction($a,$r2,'fulfill','reservation-action')===409,'reservation fourth blocked');check((int)$p->query('SELECT borrowed_copies FROM books WHERE id='.$book2)->fetchColumn()===0,'reservation rollback inventory');
check(phaseAction($a,$r,'return','reservation-action')===200,'reservation return frees slot');check(phaseAction($a,$r2,'fulfill','reservation-action')===200,'reservation checkout after return');
// Manual checkout requires an actual account, preserves server-side identity and idempotency.
$u=phaseUser();$book=phaseBook();[, $data]=request('borrow-requests-data',$a);$manual=['user_id'=>$u,'borrower_name'=>'Forged Name','book_id'=>$book,'confirm_checkout'=>true,'checkout_token'=>$data['manualCheckoutToken']];
[$code,$result]=request('borrow-request-add',$a,$manual,true);check($code===200,'account-linked manual checkout');
$q=$p->query('SELECT user_id,borrower_name FROM borrow_requests WHERE id='.(int)$result['id']);$row=$q->fetch(PDO::FETCH_ASSOC);check((int)$row['user_id']===$u && $row['borrower_name']==='Synthetic Borrower','manual identity is server authoritative');
[$code,$repeat]=request('borrow-request-add',$a,$manual,true);check($code===200&&!empty($repeat['replayed']),'manual submission idempotent');
foreach([1,2] as $i)phaseBorrow($u,phaseBook(1),'checked_out');
[, $data]=request('borrow-requests-data',$b);$manual['book_id']=phaseBook();$manual['checkout_token']=$data['manualCheckoutToken'];[$code]=request('borrow-request-add',$b,$manual,true);check($code===409,'manual fourth blocked');
unset($manual['user_id']);[$code]=request('borrow-request-add',$b,$manual,true);check($code===422,'name-only manual checkout rejected');
// Two existing holds plus one loan already occupy three slots; both holds may convert.
$u=phaseUser();phaseBorrow($u,phaseBook(1),'checked_out');
$book1=phaseBook();$book2=phaseBook();$id1=phaseBorrow($u,$book1,'awaiting_pickup');$id2=phaseReservation($u,$book2,'ready');
$codes=overlap([['borrow-request-action',$a,['id'=>$id1,'action'=>'checkout']],['reservation-action',$b,['id'=>$id2,'action'=>'fulfill']]],'SELECT id FROM users WHERE id='.$u.' FOR UPDATE');check($codes===[200,200],'simultaneous checkout converts two existing allocations');
$count=(int)$p->query('SELECT (SELECT COUNT(*) FROM borrow_requests WHERE user_id='.$u.' AND status IN ("approved","checked_out"))+(SELECT COUNT(*) FROM reservations WHERE user_id='.$u.' AND status="fulfilled")')->fetchColumn();check($count===3,'concurrent active total exactly three');
check((int)$p->query('SELECT SUM(borrowed_copies) FROM books WHERE id IN ('.$book1.','.$book2.')')->fetchColumn()===2,'both allocated checkouts update inventory');
// Return versus Ready: no over-allocation regardless of which transaction obtains the book first.
$u=phaseUser();$v=phaseUser();$book=phaseBook(1);$id=phaseBorrow($u,$book,'checked_out');$r=phaseReservation($v,$book,'waiting');
$codes=overlap([['borrow-request-action',$a,['id'=>$id,'action'=>'return']],['reservation-action',$b,['id'=>$r,'action'=>'ready']]],'SELECT id FROM books WHERE id='.$book.' FOR UPDATE');check($codes===[200,200]||$codes===[200,409],'return versus Ready valid serialized outcomes');
check((int)$p->query('SELECT borrowed_copies FROM books WHERE id='.$book)->fetchColumn()===0,'return restores physical stock');
// Checkout versus another Ready action cannot take the already held copy.
$u=phaseUser();$v=phaseUser();$book=phaseBook();$id=phaseBorrow($u,$book,'awaiting_pickup');$r=phaseReservation($v,$book,'waiting');
$codes=overlap([['borrow-request-action',$a,['id'=>$id,'action'=>'checkout']],['reservation-action',$b,['id'=>$r,'action'=>'ready']]],'SELECT id FROM books WHERE id='.$book.' FOR UPDATE');check($codes===[200,409],'held checkout and Ready cannot duplicate allocation');
check((int)$p->query('SELECT borrowed_copies FROM books WHERE id='.$book)->fetchColumn()===1,'checkout inventory exactly one');
// Every book has enough physical stock for all allocated pickup holds.
$rows=$p->query('SELECT b.total_copies,b.borrowed_copies,(SELECT COUNT(*) FROM borrow_requests br WHERE br.book_id=b.id AND br.status="awaiting_pickup")+(SELECT COUNT(*) FROM reservations r WHERE r.book_id=b.id AND r.status="ready") AS holds FROM books b')->fetchAll(PDO::FETCH_ASSOC);
foreach($rows as $row)check((int)$row['borrowed_copies']>=0 && (int)$row['borrowed_copies']+(int)$row['holds']<=(int)$row['total_copies'],'global inventory allocation invariant');
echo "Borrowing limit and manual/QR/reservation concurrency checks passed.\n";

// Manual staff checkout racing QR/ordinary checkout must use the same borrower mutex.
$u=phaseUser();phaseBorrow($u,phaseBook(1),'approved');phaseReservation($u,phaseBook(1),'fulfilled');
$book1=phaseBook();$book2=phaseBook();$id=phaseBorrow($u,$book1,'awaiting_pickup');
[, $data]=request('borrow-requests-data',$b);$manual=['user_id'=>$u,'book_id'=>$book2,'confirm_checkout'=>true,'checkout_token'=>$data['manualCheckoutToken']];
$codes=overlap([['borrow-request-action',$a,['id'=>$id,'action'=>'checkout']],['borrow-request-add',$b,$manual]],'SELECT id FROM users WHERE id='.$u.' FOR UPDATE');check($codes===[200,409],'manual and ordinary checkout share third-slot mutex');
check((int)$p->query('SELECT SUM(borrowed_copies) FROM books WHERE id IN ('.$book1.','.$book2.')')->fetchColumn()===1,'manual race loser changes no stock');
// Historical unlinked loans remain returnable without guessing an account.
$book=phaseBook(1);$q=$p->prepare('INSERT INTO borrow_requests(borrower_name,book_id,status) VALUES(?,?,"approved")');$q->execute(['Historical Unlinked',$book]);$id=(int)$p->lastInsertId();check(phaseAction($a,$id,'return')===200,'unlinked historical return preserved');check($p->query('SELECT user_id FROM borrow_requests WHERE id='.$id)->fetchColumn()===null,'historical ownership remains unlinked');
foreach($p->query('SELECT b.id,b.borrowed_copies,(SELECT COUNT(*) FROM borrow_requests br WHERE br.book_id=b.id AND br.status IN ("approved","checked_out"))+(SELECT COUNT(*) FROM reservations r WHERE r.book_id=b.id AND r.status="fulfilled") AS loans FROM books b')->fetchAll(PDO::FETCH_ASSOC) as $row)check((int)$row['borrowed_copies']===(int)$row['loans'],'inventory counter reconciles to real active loans');
$p->exec('INSERT IGNORE INTO attendance_settings(id) VALUES(1)');
foreach(['dashboard-data','reports-data','transactions-data','attendance-data','reservations-data','books-data'] as $endpoint){[$code]=request($endpoint,$a);check($code===200,'staff read regression '.$endpoint);}
[$code]=request('borrow-request-add',$sa,['user_id'=>$u,'book_id'=>phaseBook(),'confirm_checkout'=>true],true);check($code===403,'borrower cannot use manual staff checkout');
