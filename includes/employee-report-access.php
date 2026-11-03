<?php
// हजेरी/रजा असलेला अहवाल — म्हणून Staff Directory पाहण्याच्या परवानगीपेक्षा कडक नियम:
//   संपूर्ण संस्था — Super Admin, Management, view_staff_track_org_wide
//   विभाग प्रमुख (HOD) — स्वतःचा विभाग + Super Admin ने दिलेले Extra विभाग/Campus
//   इतर कर्मचारी — फक्त स्वतःचा अहवाल
function employeeReportVisibleDeptIds() {
    global $pdo, $user;
    if (hasRole('super_admin') || isManagementOrAbove() || hasPermission('view_staff_track_org_wide')) { return null; }
    $ids = [];
    if (hasAnyCategory(['hod']) && !empty($user['department_id'])) { $ids[] = (int)$user['department_id']; }
    try {
        $st = $pdo->prepare("SELECT department_id FROM user_extra_department_access WHERE user_id = ?");
        $st->execute([$user['id']]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) { $ids[] = (int)$id; }
        $campusIds = [];
        if (hasPermission('view_staff_track_own_campus') && !empty($user['campus_id'])) { $campusIds[] = (int)$user['campus_id']; }
        $st = $pdo->prepare("SELECT campus_id FROM user_extra_campus_access WHERE user_id = ?");
        $st->execute([$user['id']]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) { $campusIds[] = (int)$id; }
        if ($campusIds) {
            $st = $pdo->prepare("SELECT id FROM departments WHERE campus_id IN (" . implode(',', array_fill(0, count($campusIds), '?')) . ") AND is_active = 1");
            $st->execute($campusIds);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) { $ids[] = (int)$id; }
        }
    } catch (PDOException $e) {}
    return array_values(array_unique($ids));
}
