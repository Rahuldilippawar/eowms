<?php
require_once __DIR__ . '/includes/auth.php';
requireLogin('');
require_once __DIR__ . '/includes/module-registry.php';
header('Content-Type: application/json; charset=utf-8');

$q = trim($_GET['q'] ?? '');
$orgId = activeOrgId();
$results = [];

if (mb_strlen($q) < 1) { echo json_encode(['results' => []]); exit; }

// 1. Module Registry — Module नावं जुळली तर
foreach (getModuleRegistry() as $modKey => $modInfo) {
    if (mb_stripos($modInfo['label'], $q) !== false) {
        $results[] = ['label' => $modInfo['label'], 'sub' => 'Module', 'url' => $modInfo['dashboard'], 'icon' => $modInfo['icon']];
    }
}

// 2. सामान्य, रोजच्या-वापरातली Pages — Static यादी
$commonPages = [
    ['label' => 'Command Center', 'url' => 'index.php', 'icon' => 'fa-solid fa-house', 'keywords' => 'dashboard command center home मुख्य'],
    ['label' => 'दैनंदिन काम-नोंद', 'url' => 'daily-work/entry.php', 'icon' => 'fa-solid fa-pen', 'keywords' => 'daily work entry काम नोंद'],
    ['label' => 'नूतनीकरण', 'url' => 'renewals/index.php', 'icon' => 'fa-solid fa-rotate', 'keywords' => 'renewal renewals नूतनीकरण'],
    ['label' => 'पत्रव्यवहार', 'url' => 'letters/index.php', 'icon' => 'fa-solid fa-envelope', 'keywords' => 'letters पत्र correspondence'],
    ['label' => 'Task', 'url' => 'tasks/index.php', 'icon' => 'fa-solid fa-list-check', 'keywords' => 'task tasks काम'],
    ['label' => 'कामाची विनंती', 'url' => 'requests/index.php', 'icon' => 'fa-solid fa-clipboard-question', 'keywords' => 'request requests विनंती'],
    ['label' => 'Staff Directory', 'url' => 'staff/index.php', 'icon' => 'fa-solid fa-users', 'keywords' => 'staff directory कर्मचारी'],
    ['label' => 'Attendance', 'url' => 'staff/attendance.php', 'icon' => 'fa-solid fa-calendar-check', 'keywords' => 'attendance हजेरी'],
    ['label' => 'रजा अर्ज', 'url' => 'staff/leave-apply.php', 'icon' => 'fa-solid fa-plane-departure', 'keywords' => 'leave रजा'],
    ['label' => 'Reports', 'url' => 'reports/index.php', 'icon' => 'fa-solid fa-chart-line', 'keywords' => 'reports अहवाल'],
];
foreach ($commonPages as $p) {
    if (mb_stripos($p['label'], $q) !== false || mb_stripos($p['keywords'], $q) !== false) {
        $results[] = ['label' => $p['label'], 'sub' => 'पान', 'url' => $p['url'], 'icon' => $p['icon']];
    }
}

// 3. व्यक्ती — Staff Directory मधून नावाने शोध
if (mb_strlen($q) >= 2) {
    $peopleStmt = $pdo->prepare("SELECT id, name, designation FROM users WHERE organization_id = ? AND is_active = 1 AND name LIKE ? LIMIT 5");
    $peopleStmt->execute([$orgId, '%' . $q . '%']);
    foreach ($peopleStmt->fetchAll() as $p) {
        $results[] = ['label' => $p['name'], 'sub' => $p['designation'] ?: 'Staff Profile', 'url' => 'staff/profile.php?id=' . $p['id'], 'icon' => 'fa-solid fa-user'];
    }
}

echo json_encode(['results' => array_slice($results, 0, 10)], JSON_UNESCAPED_UNICODE);
exit;
