<?php
require_once __DIR__.'/../includes/session.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__.'/../includes/db_connect.php';
require_once __DIR__.'/../includes/borrowing.php';
try {
    requireBookAccess($pdo, true);
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') bookError(405, 'method_not_allowed');
    $id = borrowingId($_GET['user_id'] ?? null);
    echo json_encode(['borrowingCapacity'=>borrowingCapacity($pdo, $id), 'activeLoanCapacity'=>activeLoanCapacity($pdo, $id)]);
} catch (Throwable $exception) { borrowingFailure($pdo, $exception); }
