<?php
// पदे व्यवस्थापन — Permissions चे गट व "संवेदनशील" (संपूर्ण संस्था / पैसा / रचना बदलणारे) अधिकार.
// roles/index.php (Matrix) आणि roles/edit.php (गटवार निवड) दोघेही हेच वापरतात.
// नवीन Permission Key इथे नसेल तर ती आपोआप "इतर" गटात दिसते.

function rolePermissionGroups() {
    return [
        'tasks'    => ['✅ Tasks', ['assign_tasks', 'view_task_tracker', 'assign_tasks_org_wide']],
        'org'      => ['🏢 संपूर्ण संस्था — पाहणे', ['view_org_wide_dashboards', 'view_org_wide_reports', 'manage_requests_org_wide', 'manage_letters_org_wide']],
        'staff'    => ['👥 कर्मचारी व हजेरी', ['view_staff_directory', 'view_staff_track_own_campus', 'view_staff_track_org_wide', 'manage_attendance']],
        'setup'    => ['⚙️ रचना व आर्थिक', ['manage_campuses', 'manage_departments', 'manage_budget', 'manage_dept_finance', 'manage_sop', 'manage_maintenance_settings']],
        'facility' => ['🏗️ सुविधा व मालमत्ता', ['manage_hostels', 'manage_hostel_students', 'manage_residency_all_depts', 'manage_vehicles', 'manage_store', 'manage_dead_stock_master', 'manage_checkout', 'manage_asset_allocations', 'manage_room_booking', 'manage_it', 'manage_cctv', 'manage_gaushala']],
        'program'  => ['🎓 उपक्रम', ['manage_academics', 'manage_music', 'manage_meditation', 'manage_events', 'manage_guests']],
        'comm'     => ['📢 संवाद व तक्रारी', ['manage_announcements', 'manage_documents', 'manage_suggestions', 'manage_grievances', 'view_emergency_alerts']],
    ];
}

/** संपूर्ण संस्थेचा Data, पैसा किंवा संस्थेची रचना बदलू देणारे अधिकार */
function roleSensitivePermissions() {
    return ['assign_tasks_org_wide', 'view_task_tracker', 'view_org_wide_dashboards', 'view_org_wide_reports', 'manage_requests_org_wide',
        'manage_letters_org_wide', 'view_staff_directory', 'view_staff_track_org_wide', 'manage_campuses', 'manage_departments',
        'manage_budget', 'manage_dept_finance'];
}

/** [groupKey => ['label' => .., 'perms' => [permRow, …]]] — DB मधल्या क्रमानेच */
function roleGroupedPermissions(array $allPermissions) {
    $groups = rolePermissionGroups();
    $where = [];
    foreach ($groups as $gk => [, $keys]) { foreach ($keys as $k) { $where[$k] = $gk; } }
    $out = [];
    foreach ($groups as $gk => [$label]) { $out[$gk] = ['label' => $label, 'perms' => []]; }
    $out['other'] = ['label' => '📦 इतर', 'perms' => []];
    foreach ($allPermissions as $p) { $out[$where[$p['permission_key']] ?? 'other']['perms'][] = $p; }
    return array_filter($out, fn($g) => $g['perms']);
}
