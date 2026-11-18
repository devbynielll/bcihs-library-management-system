<?php
require_once __DIR__.'/sms.php';

function reminderDay(DateTimeImmutable $now): array
{
    $tomorrow=$now->setTimezone(new DateTimeZone('Asia/Manila'))->modify('+1 day')->setTime(0,0);
    return [$tomorrow->format('Y-m-d H:i:s'),$tomorrow->modify('+1 day')->format('Y-m-d H:i:s')];
}

function runDueReminders(PDO $pdo, bool $dryRun=true, ?DateTimeImmutable $testNow=null): array
{
    if ($pdo->inTransaction()) throw new RuntimeException('Reminder runner needs its own connection.');
    $pdo->exec("SET time_zone='+08:00'");
    $now=$testNow??new DateTimeImmutable('now',new DateTimeZone('Asia/Manila'));
    $now=$now->setTimezone(new DateTimeZone('Asia/Manila'));
    $result=['dry_run'=>$dryRun,'candidates'=>0,'reserved'=>0,'already_processed'=>0,'busy'=>false,'before_schedule'=>false];
    if ($now->format('H:i')<'09:00') {$result['before_schedule']=true;return $result;}
    $database=$pdo->query('SELECT DATABASE()')->fetchColumn();
    $lock='bcihs-reminders-'.substr(hash('sha256',(string)$database),0,32);
    $q=$pdo->prepare('SELECT GET_LOCK(:key,0)');$q->execute(['key'=>$lock]);
    if((int)$q->fetchColumn()!==1){$result['busy']=true;return $result;}
    try {
        [$start,$end]=reminderDay($now);
        foreach(['borrow'=>'borrow_requests','reservation'=>'reservations'] as $source=>$table){
            $states=$source==='borrow'?'("checked_out","approved")':'("fulfilled")';
            $sql='SELECT l.id,l.user_id,l.due_date,u.phone FROM '.$table.' l JOIN users u ON u.id=l.user_id
                WHERE l.status IN '.$states.' AND l.due_date>=:start AND l.due_date<:end
                AND u.account_status="accepted" AND u.role IN ("student","teacher","librarian","staff","employee")';
            $q=$pdo->prepare($sql.' ORDER BY l.id');$q->execute(['start'=>$start,'end'=>$end]);
            foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row){
                $result['candidates']++;
                if($dryRun)continue;
                $key=$source.':'.$row['id'].':due:'.str_replace('-','',substr($row['due_date'],0,10));
                $hash=hash('sha256','due_reminder:'.$key);
                $seen=$pdo->prepare('SELECT id FROM sms_logs WHERE CASE WHEN JSON_VALID(response) THEN JSON_UNQUOTE(JSON_EXTRACT(response,"$.event_key")) ELSE NULL END=:key LIMIT 1');
                $seen->execute(['key'=>$hash]);
                if($seen->fetch()){$result['already_processed']++;continue;}
                $recheck=static function() use($pdo,$sql,$row,$testNow): string|null|false {
                    $current=$testNow??new DateTimeImmutable('now',new DateTimeZone('Asia/Manila'));
                    [$start,$end]=reminderDay($current);
                    $q=$pdo->prepare($sql.' AND l.id=:id AND l.user_id=:user AND l.due_date=:due');
                    $q->execute(['start'=>$start,'end'=>$end,'id'=>$row['id'],'user'=>$row['user_id'],'due'=>$row['due_date']]);
                    $fresh=$q->fetch(PDO::FETCH_ASSOC);
                    return $fresh?($fresh['phone']===null?null:(string)$fresh['phone']):false;
                };
                // Logs reserve each event before transport. OFF, failures and uncertain results are never replayed.
                send_sms($pdo,(int)$row['user_id'],$row['phone'],smsEventMessage('due_reminder',['due_date'=>$row['due_date']]),'due_reminder',$key,$recheck);
                $hash=hash('sha256','due_reminder:'.$key);
                $q=$pdo->prepare('SELECT COUNT(*) FROM sms_logs WHERE CASE WHEN JSON_VALID(response) THEN JSON_UNQUOTE(JSON_EXTRACT(response,"$.event_key")) ELSE NULL END=:key');
                $q->execute(['key'=>$hash]);$result['reserved']+=(int)$q->fetchColumn();
            }
        }
        return $result;
    } finally {$q=$pdo->prepare('SELECT RELEASE_LOCK(:key)');$q->execute(['key'=>$lock]);}
}
