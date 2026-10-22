<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin('');
header('Content-Type: application/json; charset=utf-8');

$orgId = activeOrgId();
$action = $_GET['action'] ?? '';

if ($action === 'departments_for_campus') {
    $campusId = ($_GET['campus_id'] ?? '') !== '' ? (int)$_GET['campus_id'] : null;
    if ($campusId) {
        $stmt = $pdo->prepare("SELECT id, name_local, name FROM departments WHERE organization_id = ? AND campus_id = ? AND is_active = 1 ORDER BY name_local");
        $stmt->execute([$orgId, $campusId]);
    } else {
        // Trust-स्तरीय (कुठल्याही College खाली नसलेले, किंवा Trust HQ Campus खालचे)
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

if ($action === 'staff_for_department') {
    $deptId = (int)($_GET['department_id'] ?? 0);
    if (!$deptId) { echo json_encode(['staff' => [], 'hod_id' => null]); exit; }
    $stmt = $pdo->prepare("SELECT id, name, designation FROM users WHERE organization_id = ? AND department_id = ? AND is_active = 1 ORDER BY name");
    $stmt->execute([$orgId, $deptId]);
    $staff = array_map(fn($u) => ['id' => $u['id'], 'name' => $u['name'], 'designation' => $u['designation']], $stmt->fetchAll());
    $hodStmt = $pdo->prepare("SELECT hod_id FROM departments WHERE id = ? AND organization_id = ?");
    $hodStmt->execute([$deptId, $orgId]);
    $hodId = $hodStmt->fetch()['hod_id'] ?? null;
    echo json_encode(['staff' => $staff, 'hod_id' => $hodId], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'search_letters') {
    $q = trim($_GET['q'] ?? '');
    if (mb_strlen($q) < 2) { echo json_encode(['letters' => []]); exit; }
    $stmt = $pdo->prepare("
        SELECT id, file_number, subject FROM letters
        WHERE organization_id = ? AND (file_number LIKE ? OR subject LIKE ?)
        ORDER BY created_at DESC LIMIT 8
    ");
    $like = "%$q%";
    $stmt->execute([$orgId, $like, $like]);
    echo json_encode(['letters' => $stmt->fetchAll()], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['error' => 'अवैध Action']);
exit;
