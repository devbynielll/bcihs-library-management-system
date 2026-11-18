<?php
// Pure validation only: no private configuration, database, or transport.
// Load only the pure validators; the module's borrowing bootstrap requires a database.
$source=file_get_contents(__DIR__.'/../includes/account-management.php');
$start=strpos($source, 'function accountPhone(');
$end=strpos($source, 'function accountHistorySql(');
if ($start===false || $end===false) throw new RuntimeException('Validator source unavailable.');
eval(substr($source,$start,$end-$start));
$checks=0;
foreach (['09171234567'=>'09171234567','+639911234567'=>'09911234567','09181234567'=>'09181234567','09991234567'=>'09991234567','09221234567'=>'09221234567','invalid'=>null,''=>null] as $value=>$expected) {
    if (accountPhone($value)!==$expected) throw new RuntimeException('Phone normalization failed.');
    $checks++;
}
echo "Passed $checks student network/phone validation checks.\n";
