<?php
// Login शिवाय वापरता येणारा Cascade Endpoint — फक्त request.php (कामाची
// विनंती — Public Form) साठी. इथून फक्त College/Department ची नावं
// परत मिळतात — कुठलाही Sensitive Data नाही, त्यामुळे Public ठेवणं सुरक्षित.
require_once __DIR__ . '/config/db.php';
header('Content-Type: application/json; charset=utf-8');

$org = $pdo->query("SELECT id FROM organizations WHERE is_active = 1 ORDER BY id LIMIT 1")->fetch();
$orgId = $org['id'] ?? 0;
$action = $_GET['action'] ?? '';

if ($action === 'departments_for_campus') {
    $campusId = ($_GET['campus_id'] ?? '') !== '' ? (int)$_GET['campus_id'] : null;
    if ($campusId) {
        $stmt = $pdo->prepare("SELECT id, name_local, name FROM departments WHERE organization_id = ? AND campus_id = ? AND is_active = 1 ORDER BY name_local");
        $stmt->execute([$orgId, $campusId]);
    } else {
        $stmt = $pdo->prepare("
            SELECT d.id, d.name_local, d.name FROM departments d LEFT JOIN campuses c ON c.id = d.campus_id
            WHERE d.organization_id = ? AND d.is_active = 1 AND (d.campus_id IS NULL OR c.college_type = 'Trust HQ')
            ORDER BY d.name_local
        ");
        $stmt->execute([$orgId]);
    }
    $depts = array_map(fn($d) => ['id' => $d['id'], 'name' => $d['name_local'] ?: $d['name']], $stmt->fetchAll());
    echo json_encode(['departments' => $depts], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['error' => 'अवैध Action']);
exit;
