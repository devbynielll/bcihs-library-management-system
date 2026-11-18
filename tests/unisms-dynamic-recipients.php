<?php
// Isolated synthetic recipients and mocked transport; no configuration or database loaded.
if (function_exists('curl_exec')) exit("Disable cURL before running this test.\n");
require_once __DIR__ . '/../includes/sms-core.php';
$checks = 0;
function recipientCheck(bool $ok): void {
    global $checks;
    if (!$ok) throw new RuntimeException('Dynamic recipient regression failed.');
    $checks++;
}
$config = ['provider'=>'unisms','mode'=>'live','live_enabled'=>true,'key'=>'SYNTHETIC_ONLY',
    'unisms_delivery'=>'production','unisms_production_authorized'=>true,'live_test'=>null];
$calls=[];
$mock=static function(array $payload) use (&$calls): array {
    $calls[]=$payload;
    return ['http'=>201,'body'=>json_encode(['message'=>['status'=>'pending',
        'recipient'=>$payload['body']['recipient'],'reference_id'=>'msg_mock-'.count($calls)]])];
};
foreach ([['09171234567',901],['+639911234567',902]] as [$phone,$id]) {
    $result=smsDispatch($config,$phone,smsEventMessage('account_approved'),$mock,
        ['event'=>'account_approved','event_id'=>'account:'.$id,'user_id'=>$id]);
    recipientCheck($result['state']==='pending');
}
recipientCheck(count($calls)===2);
recipientCheck($calls[0]['body']['recipient']==='+639171234567');
recipientCheck($calls[1]['body']['recipient']==='+639911234567');
foreach ([null,'','invalid','091234'] as $phone) {
    $before=count($calls);
    $result=smsDispatch($config,$phone,smsEventMessage('account_approved'),$mock,
        ['event'=>'account_approved','event_id'=>'account:903','user_id'=>903]);
    recipientCheck($result['state']==='skipped');
    recipientCheck(count($calls)===$before);
}
recipientCheck(smsPhone('09171234567')===smsPhone('+639171234567'));
foreach (['This Sender ID cannot send to Smart, TNT or Sun numbers', "This Sender ID can't send to Smart numbers"] as $error) {
    $result=smsUniResult(422,json_encode(['errors'=>['recipient'=>[$error]]]),'09181234567');
    recipientCheck($result['state']==='skipped' && $result['reason']==='provider_network_rejected');
}
foreach ([0,503] as $http) {
    recipientCheck(smsUniResult($http,'unavailable','09181234567')['state']==='unknown');
}
recipientCheck(smsUniResult(422,'{"message":"Smart response unavailable"}','09181234567')['state']==='failed');
echo "Passed $checks dynamic recipient checks; zero database or provider requests.\n";
