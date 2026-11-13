<?php
// हजेरी/रजा असलेला अहवाल — म्हणून Staff Directory पाहण्याच्या परवानगीपेक्षा कडक नियम:
//   संपूर्ण संस्था — Super Admin, Management, view_staff_track_org_wide
//   विभाग प्रमुख (HOD Role, किंवा departments.hod_id म्हणून नेमलेले) — स्वतःचा/नेमलेला विभाग + Super Admin ने दिलेले Extra विभाग/Campus
//   इतर कर्मचारी — फक्त स्वतःचा अहवाल
function employeeReportVisibleDeptIds() {
    global $pdo;
    $user = currentUser(); // header.php नसलेल्या पानांतूनही (उदा. Print/PDF)
    if (!$user) { return []; }
    if (hasRole('super_admin') || isManagementOrAbove() || hasPermission('view_staff_track_org_wide')) { return null; }
    $ids = [];
    if (hasAnyCategory(['hod']) && !empty($user['department_id'])) { $ids[] = (int)$user['department_id']; }
    try {
        // विभागाच्या नोंदीत "विभाग प्रमुख" म्हणून नेमलेले — Role कोणताही असो
        $st = $pdo->prepare("SELECT id FROM departments WHERE hod_id = ? AND is_active = 1");
        $st->execute([$user['id']]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) { $ids[] = (int)$id; }

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

/**
 * 🔒 सहकाऱ्यांची खाजगी माहिती (Mobile, Email, पत्ता, जन्मतारीख, Profile, यादी Print) कोणाला दिसावी:
 * विभाग प्रमुख, Campus प्रमुख, Management, Super Admin. साधा कर्मचारी — फक्त स्वतःची.
 */
function staffCanSeeColleagueDetails() {
    global $pdo;
    $user = currentUser();
    if (!$user) return false;
    static $ok = null;
    if ($ok !== null) return $ok;
    if (employeeReportVisibleDeptIds() !== []) return $ok = true;
    require_once __DIR__ . '/campus-heads.php';
    try {
        if (!empty(campusHeadInfo($pdo, $user['id'])['central']) || campusHeadDepartmentIds($pdo, $user['id'])) return $ok = true;
    } catch (Throwable $e) {}
    return $ok = false;
}
