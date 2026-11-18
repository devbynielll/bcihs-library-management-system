<?php
// Copy actual modules into an isolated tree so smsSettings reads only synthetic private data.
if (function_exists('curl_exec')) throw new RuntimeException('Disable cURL first.');
$root=sys_get_temp_dir().'/bcihs-loader-'.bin2hex(random_bytes(8));
mkdir($root,0700);mkdir($root.'/app',0700);mkdir($root.'/app/includes',0700);
$ledger=sys_get_temp_dir().'/bcihs-ledger-'.bin2hex(random_bytes(8));mkdir($ledger,0700);$ledger=realpath($ledger);
register_shutdown_function(static function() use($root,$ledger):void {
    foreach (glob($root.'/app/includes/*') as $p) unlink($p);
    rmdir($root.'/app/includes');rmdir($root.'/app');rmdir($root);
    foreach(glob($ledger.'/*') as $p) unlink($p);rmdir($ledger);
});
foreach(['sms.php','sms-core.php','sms-permission.php','sms-live-test.php'] as $file) copy(__DIR__.'/../includes/'.$file,$root.'/app/includes/'.$file);
foreach(['BCIHS_SMS_MODE','BCIHS_SMS_LIVE_ENABLED','BCIHS_SMS_PROVIDER','SEMAPHORE_SENDER_NAME'] as $name) putenv($name);
require $root.'/app/includes/sms.php';
$count=0;
function loaderCheck(bool $ok):void {global $count;if(!$ok)throw new RuntimeException('Loader regression failed.');$count++;}
$local=['provider'=>'unisms','mode'=>'live','live_enabled'=>true,'unisms_verified_networks'=>['09171234567'=>'globe'],
    'live_test'=>['enabled'=>true,'issued_at'=>time()-1,'expires_at'=>time()+120,'event_id'=>'account:900','recipient'=>'09171234567','directory'=>$ledger]];
$write=static function(array $data)use($root):void {file_put_contents($root.'/app/includes/sms-local.php','<?php return '.var_export($data,true).';');};
$write($local);$config=smsSettings();
loaderCheck($config['unisms_verified_networks']===$local['unisms_verified_networks']);
$ctx=['event'=>'account_approved','event_id'=>'account:900','user_id'=>900];$msg=smsEventMessage('account_approved');
loaderCheck(smsReserveLiveTest($config,'09911234567',$msg,$ctx)==='live_test_event_or_recipient_mismatch');
$wrong=$ctx;$wrong['event_id']='account:901';loaderCheck(smsReserveLiveTest($config,'09171234567',$msg,$wrong)==='live_test_event_or_recipient_mismatch');
$missing=$local;unset($missing['unisms_verified_networks']);$write($missing);
loaderCheck(smsSettings()['unisms_verified_networks']===[]);
loaderCheck(smsReserveLiveTest(smsSettings(),'09171234567',$msg,$ctx)==='live_test_event_or_recipient_mismatch');
$write($local);loaderCheck(smsReserveLiveTest(smsSettings(),'09171234567',$msg,$ctx)===null);
loaderCheck(smsReserveLiveTest(smsSettings(),'09171234567',$msg,$ctx)==='live_test_consumed');
$production=$missing;$production['live_test']=null;$production['unisms_delivery']='production';$production['unisms_production_authorized']=true;$write($production);
$config=smsSettings()+['key'=>'SYNTHETIC'];$calls=0;
foreach(['09171234567','09911234567'] as $phone){
 $result=smsDispatch($config,$phone,$msg,static function($payload)use(&$calls,$phone){$calls++;loaderCheck(smsPhone($payload['body']['recipient'])===$phone);return ['http'=>201,'body'=>json_encode(['message'=>['recipient'=>$payload['body']['recipient'],'reference_id'=>'msg_mock','status'=>'pending']])];},$ctx);
 loaderCheck($result['state']==='pending');
}
loaderCheck($calls===2);
foreach([
 ['errors'=>['recipient'=>['This Sender ID cannot send to Smart, TNT or Sun numbers']]],
 ['errors'=>['recipient'=>["This Sender ID can't send to Smart numbers"]]]
] as $body) loaderCheck(smsUniResult(422,json_encode($body),'09171234567')['state']==='skipped');
foreach([
 ['message'=>'This Sender ID cannot send to Smart numbers'],
 ['errors'=>['content'=>['This Sender ID cannot send to Smart numbers']]],
 ['errors'=>['recipient'=>['Smart response unavailable']]],
 ['errors'=>['recipient'=>['This Sender ID cannot send to these numbers']]],
 ['errors'=>['recipient'=>['Invalid recipient']]],
 ['errors'=>['recipient'=>['Cannot validate content'], 'content'=>['Smart']]],
] as $body) loaderCheck(smsUniResult(422,json_encode($body),'09171234567')['reason']==='provider_validation_rejected');
foreach([0,503] as $http)loaderCheck(smsUniResult($http,'invalid','09171234567')['state']==='unknown');
loaderCheck(smsUniResult(201,'invalid','09171234567')['state']==='unknown');
echo "Passed $count actual-loader/classification checks; synthetic files only, no database/provider calls.\n";
