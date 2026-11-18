<?php
// Explicit local CLI migration. No record is deleted and users.phone is never rewritten.
if (PHP_SAPI!=='cli') {http_response_code(404);exit;}
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/account-management.php';
if (!in_array(DB_HOST,['localhost','127.0.0.1','::1'],true)) exit("Local databases only.\n");
try {
    $numbers=[];$duplicates=0;$invalid=0;$missing=0;
    foreach($pdo->query('SELECT id,phone FROM users')->fetchAll() as $row) {
        $phone=accountPhone($row['phone']);
        if ($phone===null) {if(trim((string)$row['phone'])==='')$missing++;else $invalid++;continue;}
        if(isset($numbers[$phone]))$duplicates++;
        $numbers[$phone][]=(int)$row['id'];
    }
    echo json_encode(['duplicate_accounts'=>$duplicates,'malformed_accounts'=>$invalid,'missing_accounts'=>$missing]),PHP_EOL;
    if (($argv[1]??'')!=='--apply') exit;
    if ($duplicates) {fwrite(STDERR,"Migration stopped: staff must resolve duplicate ownership; no schema or records changed.\n");exit(1);}
    if ($pdo->query("SHOW COLUMNS FROM users LIKE 'phone_normalized'")->fetch()) exit("Column already exists. Review installed schema; no changes made.\n");
    // MySQL DDL commits implicitly. Run during a maintenance window after a private backup.
    $pdo->exec('ALTER TABLE users ADD COLUMN phone_normalized CHAR(11) CHARACTER SET ascii COLLATE ascii_bin NULL, ADD UNIQUE KEY uq_users_phone_normalized(phone_normalized)');
    $pdo->beginTransaction();
    $q=$pdo->prepare('UPDATE users SET phone_normalized=:phone WHERE id=:id');
    foreach($numbers as $phone=>$ids)$q->execute(['phone'=>(string)$phone,'id'=>$ids[0]]);
    $pdo->commit();
    echo "Migration complete. Original phone/name values preserved; missing or malformed legacy numbers remain NULL in the unique column.\n";
} catch(Throwable $e) {
    if($pdo->inTransaction())$pdo->rollBack();
    fwrite(STDERR,"Migration failed safely. Review schema state before retrying; DDL may already have committed.\n");exit(1);
}
