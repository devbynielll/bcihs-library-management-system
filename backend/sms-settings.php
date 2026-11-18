<?php
require_once __DIR__ . '/../includes/session.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/borrowing.php';
try {
    $role = requireBookAccess($pdo, true);
    $canManage = in_array($role, ['admin','librarian'], true);
    $actorId = (int)($role === 'admin' ? $_SESSION['admin_id'] : $_SESSION['user_id']);
    $method = $_SERVER['REQUEST_METHOD'];
    if (!in_array($method, ['GET','POST'], true)) bookError(405, 'method_not_allowed');
    if ($method === 'POST') {
        if (!$canManage) bookError(403, 'Only authorized library staff may change SMS notification permission.');
        requireBookCsrf();
        smsPermissionChange($pdo, $actorId, bookRequestInput(), $role);
    }
    $permission = smsPermissionState($pdo);
    $config = smsConfig();
    $audit = [];
    if ($canManage && $permission['available']) {
        $actorColumn = smsAuditHasActorRole($pdo) ? 'actor_role' : '"admin" AS actor_role';
        $audit = $pdo->query('SELECT ' . $actorColumn . ',admin_id,previous_allowed,new_allowed,setting_version,
            DATE_FORMAT(CONVERT_TZ(changed_at,"+00:00","+08:00"),"%Y-%m-%d %H:%i:%s") AS changed_at
            FROM sms_notification_audit ORDER BY id DESC LIMIT 20')->fetchAll();
    }
    echo json_encode(['permission' => $permission, 'can_manage' => $canManage,
        'csrfToken' => bookCsrfToken(), 'audit' => $audit] + smsDeliveryStatus($permission, $config), JSON_THROW_ON_ERROR);
} catch (Throwable $exception) { borrowingFailure($pdo, $exception); }
