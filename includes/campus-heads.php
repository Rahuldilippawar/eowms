<?php
/**
 * 🏛️ Campus प्रमुख (Principal / Manager) आणि सर्व Campus चे केंद्रीय प्रमुख
 *  - Super Admin "कॅम्पस व्यवस्थापन → Campus प्रमुख" मधून नेमतो (table campus_heads; campus_id NULL = सर्व Campus)
 *  - Campus प्रमुख → त्याच्या Campus चा Dashboard, आणि त्या Campus चे सगळे विभाग (Tasks/नूतनीकरण/विनंत्या…)
 *  - केंद्रीय प्रमुख → संपूर्ण संस्था (सर्व Campus)
 */

function campusHeadsReady(PDO $pdo) {
    static $ok = null;
    if ($ok !== null) { return $ok; }
    try { $pdo->query("SELECT 1 FROM campus_heads LIMIT 1"); return $ok = true; } catch (PDOException $e) { return $ok = false; }
}

/** हा माणूस कोणत्या Campus चा प्रमुख: ['central' => bool, 'campuses' => [id => ['name'=>…, 'title'=>…]]] */
function campusHeadInfo(PDO $pdo, $userId, $orgId = null) {
    static $cache = [];
    $orgId = $orgId ?? activeOrgId();
    $key = (int)$userId . '|' . (int)$orgId;
    if (isset($cache[$key])) { return $cache[$key]; }
    $out = ['central' => false, 'central_title' => null, 'campuses' => []];
    if (!$userId || !campusHeadsReady($pdo)) { return $cache[$key] = $out; }
    $st = $pdo->prepare("SELECT h.campus_id, h.title, c.name, c.college_type FROM campus_heads h LEFT JOIN campuses c ON c.id = h.campus_id
        WHERE h.user_id = ? AND h.organization_id = ? AND (h.campus_id IS NULL OR c.is_active = 1) ORDER BY c.name");
    $st->execute([(int)$userId, (int)$orgId]);
    foreach ($st->fetchAll() as $r) {
        if ($r['campus_id'] === null) { $out['central'] = true; $out['central_title'] = $r['title']; }
        else { $out['campuses'][(int)$r['campus_id']] = ['name' => $r['name'], 'title' => $r['title'], 'type' => $r['college_type']]; }
    }
    return $cache[$key] = $out;
}

/** Campus प्रमुख असेल तर त्या Campus(es) चे सगळे सक्रिय विभाग; नसेल तर [] */
function campusHeadDepartmentIds(PDO $pdo, $userId) {
    $info = campusHeadInfo($pdo, $userId);
    if (!$info['campuses']) { return []; }
    $ids = array_keys($info['campuses']);
    $st = $pdo->prepare("SELECT id FROM departments WHERE is_active = 1 AND campus_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
    $st->execute($ids);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/**
 * मुख्य Dashboard चा व्याप: null = संपूर्ण संस्था; नाहीतर दिसणाऱ्या विभागांची यादी.
 * Super Admin / केंद्रीय प्रमुख / Management → संस्था; Campus प्रमुख → त्याचा Campus (+ स्वतःचा विभाग); बाकी → स्वतःचा विभाग
 */
function dashboardScopeDeptIds(PDO $pdo) {
    $u = currentUser();
    if (!$u) { return [0]; }
    if (($u['role'] ?? '') === 'super_admin') { return null; }
    $info = campusHeadInfo($pdo, $u['id']);
    if ($info['central']) { return null; }
    if ($info['campuses']) {
        $ids = campusHeadDepartmentIds($pdo, $u['id']);
        if (!empty($u['department_id'])) { $ids[] = (int)$u['department_id']; }
        return array_values(array_unique($ids)) ?: [0];
    }
    if (isManagementOrAbove()) { return null; }
    return [(int)($u['department_id'] ?? 0)];
}

/** SQL तुकडा: " AND <col> IN (?,?)" + params ($ids null = काहीच Filter नाही) */
function scopeInSql($col, $ids) {
    if ($ids === null) { return ['', []]; }
    $ids = $ids ?: [0];
    return [' AND ' . $col . ' IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', array_values($ids)];
}

/** हा माणूस हा Campus Dashboard पाहू शकतो का? */
function campusDashboardAllowed(PDO $pdo, $campusId) {
    $u = currentUser();
    if (!$u) { return false; }
    if (($u['role'] ?? '') === 'super_admin' || isManagementOrAbove() || hasPermission('manage_campuses')) { return true; }
    $info = campusHeadInfo($pdo, $u['id']);
    return $info['central'] || isset($info['campuses'][(int)$campusId]);
}

/** Campus Dashboard निवडीसाठी — हा माणूस कोणते Campus पाहू शकतो */
function campusDashboardList(PDO $pdo, $orgId) {
    $u = currentUser();
    $info = campusHeadInfo($pdo, $u['id'] ?? 0, $orgId);
    $st = $pdo->prepare("SELECT id, name, college_type, city FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY (college_type = 'Trust HQ') DESC, name");
    $st->execute([(int)$orgId]);
    $all = $st->fetchAll();
    if (($u['role'] ?? '') === 'super_admin' || isManagementOrAbove() || hasPermission('manage_campuses') || $info['central']) { return $all; }
    return array_values(array_filter($all, fn($c) => isset($info['campuses'][(int)$c['id']])));
}
