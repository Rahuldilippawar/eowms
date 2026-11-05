<?php
// ⏰ Browser मधून "मी अजून काम करतोय" — Server वरचा निष्क्रियता-Timer पुन्हा सुरू करतो.
// परत: JSON {ok, limit (सेकंद)}; Session संपलं असेल तर 401.
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$fresh = currentUser() && (empty($_SESSION['last_activity']) || time() - (int)$_SESSION['last_activity'] <= sessionIdleLimitSeconds());
if ($fresh) {
    $_SESSION['last_activity'] = time();
    echo json_encode(['ok' => true, 'limit' => sessionIdleLimitSeconds()]);
} else {
    http_response_code(401);
    echo json_encode(['ok' => false]);
}
