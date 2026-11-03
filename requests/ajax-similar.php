<?php
// 🔎 नवीन विनंती नोंदवताना — त्याच विभागात आधीच अशीच उघडी विनंती आहे का (दुहेरी नोंद टाळण्यासाठी)
// फक्त क्रमांक, छोटं शीर्षक, स्थिती व किती जुनी — इतर तपशील नाही.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ticket-helpers.php';
requireLogin('../');
header('Content-Type: application/json; charset=utf-8');
session_write_close();

$deptId = (int)($_GET['department_id'] ?? 0);
$text = mb_strtolower(trim((string)($_GET['q'] ?? '')));
$words = array_values(array_filter(array_unique(preg_split('/[^\p{L}\p{N}]+/u', $text)), fn($w) => mb_strlen($w) >= 3));
if (!$deptId || count($words) < 2) { echo json_encode(['matches' => []]); exit; }
$words = array_slice($words, 0, 8);

try {
    $st = $pdo->prepare("SELECT request_code, title, work_description, status, created_at, location FROM work_requests
        WHERE organization_id = ? AND department_id = ? AND status IN ('pending','in_progress') AND created_at >= DATE_SUB(NOW(), INTERVAL 60 DAY)
        ORDER BY created_at DESC LIMIT 200");
    $st->execute([activeOrgId(), $deptId]);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $hay = mb_strtolower(($r['title'] ?? '') . ' ' . $r['work_description'] . ' ' . ($r['location'] ?? ''));
        $hits = count(array_filter($words, fn($w) => mb_strpos($hay, $w) !== false));
        $score = $hits / count($words);
        if ($score >= 0.5) {
            $label = $r['title'] ?: mb_substr($r['work_description'], 0, 60) . (mb_strlen($r['work_description']) > 60 ? '…' : '');
            $out[] = ['code' => $r['request_code'], 'title' => $label, 'status' => TICKET_STATUS_LABELS[$r['status']] ?? $r['status'], 'age' => ticketAgeLabel($r['created_at']), 'score' => $score];
        }
    }
    usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
    echo json_encode(['matches' => array_slice($out, 0, 3)], JSON_UNESCAPED_UNICODE);
} catch (PDOException $e) {
    echo json_encode(['matches' => []]);
}
