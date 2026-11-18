<?php
ini_set('display_errors', '0');
require_once __DIR__ . '/../includes/session.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/book-management.php';
require_once __DIR__ . '/../includes/semaphore-credits.php';
try {
    requireBookAccess($pdo, true);
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') bookError(405, 'method_not_allowed');
    requireBookCsrf();
    session_write_close();
    $directory = function_exists('hostingPrivatePath') ? dirname(hostingPrivatePath('config.php'))
        : sys_get_temp_dir() . '/bcihs-credits-' . substr(hash('sha256', __DIR__), 0, 16);
    echo json_encode(semaphoreCreditsRead(semaphoreCreditsKey(), $directory, 'semaphoreCreditsTransport'), JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    http_response_code(503);
    echo json_encode(['error' => 'Credit balance is temporarily unavailable.']);
}
