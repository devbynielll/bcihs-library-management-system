<?php
require_once __DIR__ . '/../includes/session.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/account-management.php';

try {
    requireBookAccess($pdo, true);
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') bookError(405, 'method_not_allowed');
    $view = $_GET['view'] ?? 'routine';
    if (!is_string($view) || !in_array($view, ['routine','rejected'], true)) throw new DomainException('Choose a valid account view.', 422);
    $predicate = $view === 'rejected' ? 'u.account_status="rejected"' : 'u.account_status<>"rejected"';
    $stmt = $pdo->query(
        'SELECT u.id,u.full_name,u.email,u.student_id,u.year_section,u.id_number,u.department,u.phone,u.role,u.account_status,u.last_login,u.created_at, ('.accountHistorySql().') AS has_history
         FROM users u WHERE ' . $predicate . ' ORDER BY u.created_at DESC,u.id DESC'
    );
    $users = $stmt->fetchAll();

    echo json_encode(['users' => $users, 'statistics' => $pdo->query('SELECT role,account_status,created_at FROM users')->fetchAll(), 'csrfToken' => bookCsrfToken(), 'serverDate'=>date('Y-m-d'), 'currentUserId'=>isset($_SESSION['admin_id'])?null:($_SESSION['user_id']??null)]);
} catch (Throwable $exception) {
    borrowingFailure($pdo, $exception);
}
