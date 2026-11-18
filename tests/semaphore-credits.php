<?php
if (function_exists('curl_init')) { fwrite(STDERR, "Disable curl_init,curl_exec for this test.\n"); exit(1); }
require __DIR__ . '/../includes/semaphore-credits.php';
$checks = 0;
function checkCredits(bool $ok): void { global $checks; $checks++; if (!$ok) throw new RuntimeException('Check failed: ' . $checks); }
$root = sys_get_temp_dir() . '/bcihs-credit-tests-' . bin2hex(random_bytes(6));
mkdir($root, 0700);
$owner = getmypid();
register_shutdown_function(static function () use ($root, $owner): void {
    if (getmypid() !== $owner) return;
    foreach (glob($root . '/*') as $dir) { foreach (glob($dir . '/*') as $file) unlink($file); rmdir($dir); } rmdir($root);
});
$calls = 0;
$mock = static function ($key) use (&$calls): array { $calls++; return ['http'=>200,'body'=>'{"credit_balance":"250.50","secret":"do-not-return"}']; };
checkCredits(semaphoreCreditsRead('', $root.'/missing', $mock, 100)['configured'] === false);
checkCredits($calls === 0);
$result = semaphoreCreditsRead('mock-only-key', $root.'/success', $mock, 100);
checkCredits($result['balance'] === '250.50' && $result['updated_at'] === 100);
checkCredits(!str_contains(json_encode($result), 'mock-only-key') && !str_contains(json_encode($result), 'do-not-return'));
semaphoreCreditsRead('mock-only-key', $root.'/success', $mock, 159);
checkCredits($calls === 1);
$failure = semaphoreCreditsRead('mock-only-key', $root.'/success', static function () { throw new RuntimeException('mock-only-key'); }, 160);
checkCredits($failure['balance'] === '250.50' && $failure['updated_at'] === 100);
checkCredits(!str_contains(json_encode($failure), 'mock-only-key'));
semaphoreCreditsRead('new-key', $root.'/success', $mock, 170);
checkCredits($calls === 1);
foreach ([0,401,403,429,500,503] as $code) {
    $dir=$root.'/error'.$code;
    $result=semaphoreCreditsRead('mock-only-key',$dir,static fn()=>['http'=>$code,'body'=>'mock-only-key','retry'=>120],100);
    checkCredits($result['balance'] === null && $result['next_check_at'] === 220);
    semaphoreCreditsRead('mock-only-key',$dir,$mock,200);
    checkCredits($calls === 1);
}
foreach (['{}','garbage','{"credit_balance":-1}','{"credit_balance":"<script>"}','{"credit_balance":null}'] as $i=>$body) {
    checkCredits(semaphoreCreditsRead('mock-only-key',$root.'/bad'.$i,static fn()=>['http'=>200,'body'=>$body],100)['balance'] === null);
}
checkCredits(semaphoreCreditsRead('mock-only-key',$root.'/zero',static fn()=>['http'=>200,'body'=>'{"credit_balance":0}'],100)['balance'] === '0');
mkdir($root.'/corrupt',0700); file_put_contents($root.'/corrupt/semaphore-credits-cache.json','broken'); chmod($root.'/corrupt/semaphore-credits-cache.json',0600);
semaphoreCreditsRead('mock-only-key',$root.'/corrupt',$mock,100); checkCredits($calls===1);
mkdir($root.'/unsafe',0755); semaphoreCreditsRead('mock-only-key',$root.'/unsafe',$mock,100); checkCredits($calls===1);
// A stored in-progress cooldown survives a fresh call/process without a second transport.
mkdir($root.'/interrupted',0700); $claim=['key_id'=>hash('sha256','mock-only-key'),'next_check_at'=>160];
file_put_contents($root.'/interrupted/semaphore-credits-cache.json',json_encode($claim)); chmod($root.'/interrupted/semaphore-credits-cache.json',0600);
semaphoreCreditsRead('mock-only-key',$root.'/interrupted',$mock,110); checkCredits($calls===1);
if (function_exists('pcntl_fork')) {
    $dir=$root.'/concurrent'; mkdir($dir,0700); $children=[];
    for($i=0;$i<5;$i++) { $pid=pcntl_fork(); if($pid===0) {
        semaphoreCreditsRead('mock-only-key',$dir,static function() use($dir) { file_put_contents($dir.'/calls','1',FILE_APPEND|LOCK_EX); usleep(100000); return ['http'=>200,'body'=>'{"credit_balance":12}']; },100);
        exit(0);
    } $children[]=$pid; }
    foreach($children as $pid) pcntl_waitpid($pid,$status);
    checkCredits(file_get_contents($dir.'/calls')==='1');
}
$endpoint=file_get_contents(__DIR__.'/../backend/semaphore-credits.php');
checkCredits(str_contains($endpoint,'requireBookAccess($pdo, true);') && str_contains($endpoint,'requireBookCsrf()'));
checkCredits(!str_contains($endpoint,'smsDispatch') && !str_contains($endpoint,'INSERT') && !str_contains($endpoint,'UPDATE'));
$source=file_get_contents(__DIR__.'/../includes/semaphore-credits.php');
checkCredits(str_contains($source,'/api/v4/account?') && !str_contains($source,'/api/v4/messages'));
echo "$checks Semaphore credit checks passed; no HTTP or database calls.\n";
