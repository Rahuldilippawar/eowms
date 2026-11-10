<?php
// ⬇️ पदे व अधिकार — CSV (Excel): type=matrix (पद × अधिकार) | people (व्यक्तीनुसार अधिकार)
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$type = ($_GET['type'] ?? 'matrix') === 'people' ? 'people' : 'matrix';
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="roles-' . $type . '-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF");
if ($type === 'matrix') {
    $roles = $pdo->query("SELECT role_key, COALESCE(NULLIF(label_local,''), label) l FROM role_definitions WHERE role_key <> 'super_admin' ORDER BY FIELD(category,'management','hod','staff'), label")->fetchAll();
    $rp = [];
    foreach ($pdo->query("SELECT role_key, permission_key FROM role_permissions")->fetchAll() as $r) { $rp[$r['permission_key']][$r['role_key']] = 1; }
    fputcsv($out, array_merge(['अधिकार', 'Key'], array_column($roles, 'l'), ['किती पदे']));
    foreach ($pdo->query("SELECT permission_key, COALESCE(NULLIF(label_local,''), label) l FROM permissions ORDER BY sort_order")->fetchAll() as $p) {
        $row = [$p['l'], $p['permission_key']]; $n = 0;
        foreach ($roles as $r) { $has = !empty($rp[$p['permission_key']][$r['role_key']]); $n += $has; $row[] = $has ? '✓' : ''; }
        $row[] = $n;
        fputcsv($out, $row);
    }
} else {
    fputcsv($out, ['व्यक्ती', 'पद', 'विभाग', 'अधिकार', 'Key', 'प्रकार', 'मुदत', 'कारण', 'कोणी दिला', 'कधी']);
    try {
        $st = $pdo->prepare("SELECT u.name, u.role, d.name_local dept, COALESCE(NULLIF(p.label_local,''), p.label, up.permission_key) pl, up.permission_key, up.mode, up.expires_on, up.note, g.name gb, up.created_at
            FROM user_permissions up JOIN users u ON u.id = up.user_id LEFT JOIN departments d ON d.id = u.department_id
            LEFT JOIN permissions p ON p.permission_key = up.permission_key COLLATE utf8mb4_unicode_ci LEFT JOIN users g ON g.id = up.granted_by
            WHERE u.organization_id = ? ORDER BY u.name, p.sort_order");
        $st->execute([activeOrgId()]);
        foreach ($st->fetchAll() as $r) {
            fputcsv($out, [$r['name'], $r['role'], $r['dept'], $r['pl'], $r['permission_key'], $r['mode'] === 'deny' ? 'काढला' : 'दिला',
                $r['expires_on'] ?: 'कायम', $r['note'], $r['gb'], $r['created_at']]);
        }
    } catch (PDOException $e) {}
}
fclose($out);
