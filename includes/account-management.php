<?php
require_once __DIR__ . '/borrowing.php';

function accountText($value, int $maximum, bool $required = true): string
{
    if (!is_string($value)) throw new DomainException('Enter valid text for each field.',422);
    $value=trim($value);
    if (($required && $value==='') || mb_strlen($value)>$maximum || preg_match('/[\x00-\x1f\x7f]/u',$value)) {
        throw new DomainException('Check required fields and their maximum lengths.',422);
    }
    return $value;
}

function accountPhone($value): ?string
{
    if (!is_string($value)) return null;
    $phone=preg_replace('/[\s().-]/','',trim($value));
    if (preg_match('/^09\d{9}$/',$phone)) return $phone;
    if (preg_match('/^\+639\d{9}$/',$phone)) return '0'.substr($phone,3);
    return null;
}

function registrationName(array $input): string
{
    $parts=[];
    foreach (['first_name','middle_name','last_name'] as $field) {
        $value=accountText($input[$field]??'',150,$field!=='middle_name');
        if ($value!=='' && !preg_match("/^[\p{L}\p{M}][\p{L}\p{M} '\x{2019}.\-]*$/u",$value)) {
            throw new DomainException('Enter your name using letters, spaces, apostrophes, periods or hyphens.',422);
        }
        if ($value!=='') $parts[]=$value;
    }
    return accountText(implode(' ',$parts),150);
}

function requireUniqueAccountPhone(PDO $pdo, string $phone, int $excludeId=0): void
{
    try {
        $q=$pdo->prepare('SELECT id FROM users WHERE phone_normalized=:phone AND id<>:id LIMIT 1');
        $q->execute(['phone'=>$phone,'id'=>$excludeId]);
    } catch (PDOException $exception) {
        if ($exception->getCode()==='42S22') throw new DomainException('Registration/contact updates need a library database update. Please contact staff.',503);
        throw $exception;
    }
    if ($q->fetch()) throw new DomainException('That mobile number is already registered to another account.',409);
}

function accountHistorySql(): string
{
    // Fixed alias u; preserve identities and audit references in every linked table.
    return 'EXISTS(SELECT 1 FROM borrow_requests h WHERE h.user_id=u.id)
        OR EXISTS(SELECT 1 FROM reservations h WHERE h.user_id=u.id)
        OR EXISTS(SELECT 1 FROM attendance h WHERE h.user_id=u.id)
        OR EXISTS(SELECT 1 FROM sms_logs h WHERE h.user_id=u.id)
        OR EXISTS(SELECT 1 FROM announcements h WHERE h.posted_by=u.id)';
}
