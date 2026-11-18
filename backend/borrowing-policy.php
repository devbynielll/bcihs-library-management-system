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
        if (!$canManage) bookError(403, 'Only authorized library staff may change the default borrowing period.');
        requireBookCsrf();
        saveLoanPolicy(bookRequestInput(), $actorId, $role);
    }
    $policy = loanPolicy();
    echo json_encode(['days'=>$policy['days'], 'version'=>$policy['version'], 'can_manage'=>$canManage,
        'audit'=>$canManage ? array_slice($policy['audit'], -20) : [], 'csrfToken'=>bookCsrfToken(), 'timezone'=>'Asia/Manila',
        'server_date'=>date('Y-m-d'), 'max_date'=>min('2038-01-18', (new DateTimeImmutable('+365 days'))->format('Y-m-d'))]);
} catch (Throwable $exception) { borrowingFailure($pdo, $exception); }
