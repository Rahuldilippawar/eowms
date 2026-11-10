<?php
// 🔍 Access तपासणी — "या व्यक्तीला काय काय दिसतं आणि का?" (पदे व्यवस्थापन → व्यक्तीनुसार Access)
// Sidebar / module-access.php मधलेच नियम — पण कोणत्याही व्यक्तीसाठी (फक्त Login झालेल्यासाठी नाही).
require_once __DIR__ . '/module-access.php';
require_once __DIR__ . '/module-registry.php';

/** मूळ Modules — सगळ्यांना नेहमी (module-access.php: "रोजची मूळ कामं") */
function accessCoreModules() {
    return [
        'daily-work' => ['📝 दैनंदिन कामकाज', 'daily-work/my-work.php'],
        'tasks'      => ['✅ माझी कामे (Tasks)', 'tasks/my-tasks.php'],
        'renewals'   => ['🔁 नूतनीकरण', 'renewals/index.php'],
        'letters'    => ['✉️ पत्रव्यवहार', 'letters/index.php'],
        'requests'   => ['🙋 विनंत्या', 'requests/index.php'],
        'attendance' => ['🕘 हजेरी / रजा', 'staff/profile.php'],
        'messages'   => ['💬 संदेश', 'messages/index.php'],
        'grievances' => ['⚖️ तक्रार', 'grievances/index.php'],
    ];
}

/** Permission ठरवणारे Modules — [key => [label, [permission keys], [dept codes], hodAllowed]] */
function accessGatedModules() {
    $reg = getModuleRegistry();
    $names = ['store' => '📦 भांडार (Store)', 'meditation' => 'ध्यान व सत्संग', 'music' => 'संगीत विभाग', 'events' => 'कार्यक्रम', 'guests' => 'पाहुणे',
        'room-booking' => 'Room/Hall Booking', 'allocations' => 'Issue / Return Register', 'gaushala' => 'गौशाळा', 'vehicles' => 'वाहन विभाग',
        'cctv' => 'CCTV', 'it' => 'IT विभाग', 'deadstock' => 'Dead Stock', 'dept-finance' => 'विभाग Finance'];
    $lbl = fn($k, $f) => $reg[$k]['label'] ?? ($names[$k] ?? $f);
    $deptCodes = moduleAccessDeptCodes();
    $out = [];
    foreach (moduleAccessPermissions() as $k => $perms) {
        $out[$k] = [$lbl($k, $k), $perms, $deptCodes[$k] ?? [], in_array($k, ['dept-finance', 'deadstock'], true)];
    }
    // पानं स्वतः तपासतात — तेच नियम
    $out['hostels']     = [$lbl('hostels', 'वसतिगृह'), ['manage_hostels'], ['HOS'], false];
    $out['residency']   = [$lbl('residency', 'रहिवासी'), ['manage_residency_all_depts'], ['RES'], false];
    $out['maintenance'] = [$lbl('maintenance', 'देखभाल'), ['manage_maintenance_settings'], [], false];
    $out['sop-manage']  = ['SOP तयार करणे', ['manage_sop'], [], false];
    $out['task-tracker'] = ['Task Tracker (सर्वांची कामे)', ['view_task_tracker'], [], false];
    $out['staff-dir']   = ['कर्मचारी यादी', ['view_staff_directory'], [], false];
    $out['emergency']   = ['🚨 SOS Alerts सोडवणे', ['view_emergency_alerts'], [], false];
    return $out;
}

/**
 * एका व्यक्तीचा संपूर्ण Access.
 * परत: ['role'=>row|null, 'perms'=>[key=>source], 'overrides'=>[key=>row], 'modules'=>[key=>[label, ok, reason]], 'restricted'=>[]|null,
 *       'depts'=>[names], 'campuses'=>[names], 'scope'=>string]
 * source = 'role' | 'grant' | 'deny' (पदाचा अधिकार व्यक्तीसाठी काढलेला) | 'deny-none' (पदाला नव्हताच)
 */
function accessExplainUser(PDO $pdo, array $u) {
    $st = $pdo->prepare("SELECT * FROM role_definitions WHERE role_key = ?");
    $st->execute([$u['role']]);
    $role = $st->fetch() ?: null;
    $isSA = $u['role'] === 'super_admin';

    $st = $pdo->prepare("SELECT permission_key FROM role_permissions WHERE role_key = ?");
    $st->execute([$u['role']]);
    $rolePerms = array_flip($st->fetchAll(PDO::FETCH_COLUMN));

    $overrides = [];
    try {
        $st = $pdo->prepare("SELECT up.*, g.name AS granted_by_name FROM user_permissions up LEFT JOIN users g ON g.id = up.granted_by WHERE up.user_id = ?");
        $st->execute([(int)$u['id']]);
        foreach ($st->fetchAll() as $r) { $overrides[$r['permission_key']] = $r; }
    } catch (PDOException $e) {}
    $today = date('Y-m-d');
    $live = fn($r) => !$r['expires_on'] || $r['expires_on'] >= $today;

    $perms = [];
    foreach ($rolePerms as $k => $_) { $perms[$k] = 'role'; }
    foreach ($overrides as $k => $r) {
        if (!$live($r)) { continue; }
        if ($r['mode'] === 'deny') { if (isset($perms[$k])) { $perms[$k] = 'deny'; } }
        else { $perms[$k] = isset($rolePerms[$k]) ? 'role' : 'grant'; }
    }
    $has = fn($k) => $isSA || (($perms[$k] ?? '') === 'role' || ($perms[$k] ?? '') === 'grant');

    $deptCode = '';
    if (!empty($u['department_id'])) {
        $st = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
        $st->execute([(int)$u['department_id']]);
        $deptCode = (string)($st->fetchColumn() ?: '');
    }
    $cat = $isSA ? 'super_admin' : ($role['category'] ?? 'staff');

    $restricted = null;
    if (!$isSA && $role && !empty($role['restricted_mode']) && !empty($role['restricted_modules'])) {
        $restricted = array_filter(array_map('trim', explode(',', $role['restricted_modules'])));
    }

    $modules = [];
    foreach (accessCoreModules() as $k => [$label]) {
        $modules[$k] = [$label, true, 'मूळ Module — सगळ्यांना'];
    }
    foreach (accessGatedModules() as $k => [$label, $pk, $codes, $hodOk]) {
        if ($isSA) { $modules[$k] = [$label, true, 'Super Admin']; continue; }
        $ok = false; $why = 'Permission नाही';
        foreach ($pk as $p) {
            if ($has($p)) { $ok = true; $why = ($perms[$p] === 'grant' ? '👤 व्यक्तीला दिलेला' : 'पदाला') . ' अधिकार: ' . $p; break; }
            if (($perms[$p] ?? '') === 'deny') { $why = '⛔ व्यक्तीसाठी काढलेला: ' . $p; }
        }
        if (!$ok && $codes && in_array($deptCode, $codes, true)) { $ok = true; $why = 'स्वतःचा विभाग (' . $deptCode . ')'; }
        if (!$ok && $hodOk && $cat === 'hod') { $ok = true; $why = 'HOD — स्वतःचा विभाग'; }
        $modules[$k] = [$label, $ok, $why];
    }
    if ($restricted !== null) {
        $reg = getModuleRegistry();
        foreach ($modules as $k => &$m) {
            $inList = in_array($k, $restricted, true);
            $m[1] = $inList; $m[2] = $inList ? '🎯 Restricted पदात निवडलेला' : '🎯 Restricted पद — फक्त निवडलेले Modules';
        }
        unset($m);
        foreach ($restricted as $k) { if (!isset($modules[$k]) && isset($reg[$k])) { $modules[$k] = [$reg[$k]['label'], true, '🎯 Restricted पदात निवडलेला']; } }
    }

    $depts = []; $camps = [];
    try {
        $st = $pdo->prepare("SELECT d.name_local FROM user_extra_department_access x JOIN departments d ON d.id = x.department_id WHERE x.user_id = ? ORDER BY d.name_local");
        $st->execute([(int)$u['id']]); $depts = $st->fetchAll(PDO::FETCH_COLUMN);
        $st = $pdo->prepare("SELECT c.name FROM user_extra_campus_access x JOIN campuses c ON c.id = x.campus_id WHERE x.user_id = ? ORDER BY c.name");
        $st->execute([(int)$u['id']]); $camps = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (PDOException $e) {}

    $scope = $isSA ? 'संपूर्ण System (सर्व संस्था)'
        : ($cat === 'management' ? 'संपूर्ण संस्था' : ($cat === 'hod' ? 'स्वतःचा विभाग (HOD)' : 'स्वतःचा विभाग / स्वतःची कामे'));
    if (!$isSA && $has('view_org_wide_dashboards')) { $scope .= ' + संस्थेचे Dashboards'; }

    return ['role' => $role, 'category' => $cat, 'perms' => $perms, 'overrides' => $overrides, 'modules' => $modules,
        'restricted' => $restricted, 'depts' => $depts, 'campuses' => $camps, 'scope' => $scope, 'dept_code' => $deptCode];
}
