<?php
if(!isset($p,$db)||!str_starts_with($db,'bcihs_registration_test_'))throw new RuntimeException('Disposable integration runner required.');
$startChecks=$checks;
$u=phaseUser();$v=phaseUser();$ids=[];
foreach(['pending','awaiting_pickup','checked_out','approved','returned','rejected'] as $state)$ids[$state]=phaseBorrow($u,phaseBook(in_array($state,['checked_out','approved'])?1:0),$state);
$foreign=phaseBorrow($v,phaseBook(),'rejected');
$student=client();$identifier=$p->query('SELECT student_id FROM users WHERE id='.$u)->fetchColumn();request('login-process',$student,['identifier'=>$identifier,'password'=>$pass]);[, $profile]=request('profile-data',$student);$student['csrf']=$profile['csrfToken'];
// Capture the entire actual loan row, plus SMS logs, to prove GET views cannot alter history.
$rowsBefore=$p->query('SELECT * FROM borrow_requests ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$logsBefore=$p->query('SELECT * FROM sms_logs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
foreach(['my-requests-data','borrowing-history-data'] as $endpoint){
 [$code,$data]=request($endpoint,$student);check($code===200,'borrower history readable '.$endpoint);
 $rows=$data['requests'];check(count($rows)===5,'only rejected owner row excluded '.$endpoint);
 foreach($rows as $row){check($row['status']!=='rejected','no rejected borrower row');$id=(int)($row['request_id']??$row['id']);check(in_array($id,$ids,true)&&$id!==$foreign,'owner scope preserved');}
 [$code,$data]=request($endpoint.'?view=rejected',$student);check($code===200&&!in_array('rejected',array_column($data['requests'],'status'),true),'borrower query cannot expose rejection');
}
$lib=client();request('login-process',$lib,['identifier'=>$users[2]['id_number'],'password'=>$pass]);
foreach([$a,$lib] as $staff){
 [$code,$data]=request('borrow-requests-data',$staff);check($code===200,'staff routine available');check(!in_array('rejected',array_column($data['requests'],'status'),true),'staff default excludes rejections');
 foreach(['pending','awaiting_pickup','checked_out','approved','returned'] as $state)check(in_array($ids[$state],array_map('intval',array_column($data['requests'],'id')),true),'valid state remains visible '.$state);
 [$code,$audit]=request('borrow-requests-data?view=rejected',$staff);check($code===200&&count($audit['requests'])>=2,'staff rejection audit available');foreach($audit['requests'] as $row)check($row['status']==='rejected','audit returns rejected rows only');
 [$code]=request('borrow-requests-data?view=unknown',$staff);check($code===422,'invalid audit mode rejected');
 [$code,$tx]=request('transactions-data',$staff);check($code===200&&!in_array('rejected',array_column($tx['requests'],'status'),true),'routine transactions hide rejections');
 [$code,$report]=request('reports-data?report=requests&status=rejected&period=month',$staff);check($code===200,'staff report retained');check(in_array($ids['rejected'],array_map('intval',array_column($report['records'],'id')),true),'rejected history remains in reports');
}
foreach([client(),$student] as $session){[$code]=request('borrow-requests-data?view=rejected',$session);check(in_array($code,[401,403],true),'audit denies guest/borrower');}
$rejected=phaseUser();$p->exec('UPDATE users SET account_status="rejected" WHERE id='.$rejected);
foreach([$a,$lib] as $session){[$code,$data]=request('all-users-data?view=rejected',$session);check($code===200,'account management accessible');$found=array_values(array_filter($data['users'],fn($row)=>(int)$row['id']===$rejected));check(count($found)===1&&$found[0]['account_status']==='rejected','rejected account retained for staff');}
check($p->query('SELECT * FROM borrow_requests ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$rowsBefore,'all request rows/statuses preserved');
check($p->query('SELECT * FROM sms_logs ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)===$logsBefore,'SMS history preserved');
// A new real rejection action in the disposable app still creates a log, though its row vanishes from routine views.
$permission=$p->query('SELECT allow_notifications FROM sms_notification_settings WHERE id=1')->fetchColumn();
$p->exec('UPDATE sms_notification_settings SET allow_notifications=0 WHERE id=1');
$staff=phaseStaff();$fresh=phaseBorrow($u,phaseBook(),'pending');$beforeLogCount=(int)$p->query('SELECT COUNT(*) FROM sms_logs')->fetchColumn();check(phaseAction($staff,$fresh,'reject')===200,'rejection action remains functional');check((int)$p->query('SELECT COUNT(*) FROM sms_logs')->fetchColumn()===$beforeLogCount+1,'rejection SMS log still recorded');
$p->exec('UPDATE sms_notification_settings SET allow_notifications='.(int)$permission.' WHERE id=1');
echo 'Rejected visibility checks: '.($checks-$startChecks).PHP_EOL;
