<?php
// 🌙 दैनिक अहवाल — Cron चा "Backup": Login केलेल्या कोणत्याही पानावरून पार्श्वभूमीत बोलावलं जातं (includes/footer.php)
require_once __DIR__ . '/includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
if (!currentUser() || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { http_response_code(403); echo '{"ok":false}'; exit; }
require_once __DIR__ . '/includes/report-autosend.php';
$orgId = activeOrgId();
session_write_close();          // बाकीची पानं अडकू नयेत
ignore_user_abort(true);        // पान बदललं तरी पाठवणं पूर्ण होऊ दे
@set_time_limit(240);
try {
    $r = reportAutoSendRun($pdo, $orgId);
} catch (Throwable $e) {
    error_log('report-autosend: ' . $e->getMessage());
    $r = ['ok' => false, 'msg' => 'त्रुटी'];
}
echo json_encode(['ok' => (bool)($r['ok'] ?? false), 'sent' => (bool)($r['sent'] ?? false)], JSON_UNESCAPED_UNICODE);
