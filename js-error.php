<?php
// Browser मधल्या JavaScript त्रुटी — Error Log मध्ये नोंद (फक्त Login असलेल्यांकडून, मर्यादित संख्येत)
require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || empty($_SESSION['user'])) { http_response_code(204); exit; }
// एका Session मधून तासाला कमाल 20 नोंदी
$now = time();
$_SESSION['js_err_log'] = array_values(array_filter($_SESSION['js_err_log'] ?? [], fn($t) => $t > $now - 3600));
if (count($_SESSION['js_err_log']) >= 20) { echo json_encode(['ok' => false]); exit; }
$_SESSION['js_err_log'][] = $now;

$in = json_decode(file_get_contents('php://input') ?: '{}', true) ?: [];
$cut = fn($s, $n) => mb_substr(trim((string)$s), 0, $n);
$ref = eowmsErrorRef();
eowmsLogError([
    'ref' => $ref, 'type' => 'JS Error', 'kind' => 'Browser (JavaScript)', 'level' => 'js',
    'message' => $cut($in['message'] ?? '', 500),
    'file' => $cut($in['source'] ?? '', 200), 'line' => (int)($in['line'] ?? 0),
    'trace' => $cut($in['stack'] ?? '', 2000),
    'url' => 'PAGE ' . $cut($in['page'] ?? '', 300),
]);
echo json_encode(['ok' => true, 'ref' => $ref]);
