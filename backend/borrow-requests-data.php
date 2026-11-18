<?php
require_once __DIR__ . '/../includes/session.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
require_once __DIR__ . '/../includes/db_connect.php';
require_once __DIR__ . '/../includes/borrowing.php';

try {
    requireBookAccess($pdo, true);
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') bookError(405, 'method_not_allowed');
    $view=$_GET['view']??'routine';
    if (!is_string($view) || !in_array($view,['routine','rejected'],true)) throw new DomainException('Choose a valid request view.',422);
    $predicate=$view==='rejected'?'br.status="rejected"':'br.status<>"rejected"';
    $stmt = $pdo->query(
        'SELECT br.id, br.status, br.requested_at, br.approved_at, br.checked_out_at, br.due_date, br.rejected_at, br.returned_at,
                COALESCE(br.checked_out_at, CASE WHEN br.status IN ("approved","returned") THEN br.approved_at END) AS borrowed_at,
                br.user_id, u.role AS borrower_role, COALESCE(u.student_id,u.id_number,u.email) AS borrower_identifier, COALESCE(br.borrower_name, u.full_name) AS student_name,
                b.id AS book_id, b.title AS book_title, b.author, b.accession_number
         FROM borrow_requests br LEFT JOIN users u ON u.id = br.user_id
         JOIN books b ON b.id = br.book_id WHERE ' . $predicate . ' ORDER BY br.requested_at DESC, br.id DESC'
    );
    $borrowers=$pdo->query('SELECT id,full_name,COALESCE(student_id,id_number,email) AS identifier FROM users WHERE account_status="accepted" AND role IN ("student","teacher","librarian","staff","employee") ORDER BY full_name,id')->fetchAll();
    echo json_encode(['borrowers'=>$borrowers,'requests' => $stmt->fetchAll(), 'csrfToken' => bookCsrfToken(), 'serverDate' => date('Y-m-d'), 'manualCheckoutToken' => manualCheckoutToken()]);
} catch (Throwable $exception) {
    borrowingFailure($pdo, $exception);
}
