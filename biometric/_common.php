<?php
// 🖐️ Biometric पानांसाठी सामायिक — Tabs व Setup माहिती
require_once __DIR__ . '/../includes/bio-helpers.php';

/** पानाच्या वरचे Tabs — $active: index|devices|pins|punches|my */
function bioTabs($active) {
    $tabs = [];
    if (hasRole('super_admin') || isManagementOrAbove()) {
        $tabs['index'] = ['index.php', '📡 Live'];
        $tabs['punches'] = ['punches.php', '📋 हजेरी अहवाल'];
    }
    if (hasRole('super_admin') || isManagementOrAbove() || hasPermission('manage_attendance')) {
        $tabs['enroll'] = ['enroll.php', '🧑‍🔧 नोंदणी बाकी'];
    }
    if (hasRole('super_admin')) {
        $tabs['devices'] = ['devices.php', '🖥️ Machines'];
        $tabs['pins'] = ['pins.php', '🔗 PIN जोडणी'];
    }
    $tabs['my'] = ['my.php', '👤 माझी हजेरी'];
    $html = '<div class="bio-tabs">';
    foreach ($tabs as $k => [$href, $label]) {
        $html .= '<a href="' . $href . '" class="' . ($k === $active ? 'on' : '') . '">' . $label . '</a>';
    }
    return $html . '</div><style>
      .bio-tabs{display:flex;gap:6px;flex-wrap:wrap;margin:6px 0 16px;}
      .bio-tabs a{padding:7px 14px;border:1px solid var(--line);border-radius:20px;font-size:13px;text-decoration:none;color:var(--text);background:var(--surface);}
      .bio-tabs a.on{background:var(--rail);border-color:var(--rail);color:#fff;font-weight:600;}
      .bio-dot{display:inline-block;width:9px;height:9px;border-radius:50%;margin-right:5px;vertical-align:middle;}
      .bio-dot.on{background:#16A34A;box-shadow:0 0 0 3px rgba(22,163,74,.18);} .bio-dot.off{background:#DC2626;} .bio-dot.pend{background:#F59E0B;}
      .bio-chip{display:inline-block;font-size:11px;border-radius:10px;padding:1px 8px;font-weight:600;}
    </style>';
}

/** Machine Setup साठी Server पत्ता (Domain) */
function bioServerHost() {
    $h = defined('SITE_BASE_URL') ? parse_url(SITE_BASE_URL, PHP_URL_HOST) : null;
    return $h ?: preg_replace('/:\d+$/', '', $_SERVER['HTTP_HOST'] ?? 'तुमचं-domain');
}

function bioMinutes($m) {
    $m = (int)$m;
    if ($m <= 0) { return '—'; }
    return ($m >= 60 ? intdiv($m, 60) . ' ता. ' : '') . ($m % 60 ? ($m % 60) . ' मि.' : '');
}

/** Live / अहवालासाठी पाहण्याचा अधिकार — Super Admin किंवा व्यवस्थापन */
function bioRequireViewer($rootPath = '../') {
    if (!hasRole('super_admin') && !isManagementOrAbove()) { header('Location: my.php'); exit; }
}

/** पाहणाऱ्याच्या अधिकारातले विभाग — SQL तुकडा (u. alias) */
function bioScopeSql(&$params) {
    $ids = getVisibleDepartmentIdsForStaffTracking();
    if ($ids === null) { return ''; }
    if (!$ids) { return ' AND 1 = 0'; }
    $params = array_merge($params, $ids);
    return ' AND u.department_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
}

/**
 * आजचा Live आढावा — Tiles, Punch यादी (नवीनतम), अजून न आलेले, उशिरा आलेले, Machines, इशारे.
 * $afterId दिला तर Punch यादीत फक्त त्यानंतरचे.
 */
function bioLiveData(PDO $pdo, $orgId, $afterId = 0) {
    $today = date('Y-m-d');
    $p = [(int)$orgId]; $scope = bioScopeSql($p);
    $st = $pdo->prepare("SELECT u.id, u.name, u.biometric_pin, u.campus_id, COALESCE(NULLIF(d.name_local,''), d.name) dept,
            a.status, a.first_punch_time, a.last_punch_time, a.total_punches, a.late_minutes, a.missed_punch, a.source
        FROM users u LEFT JOIN departments d ON d.id = u.department_id
        LEFT JOIN staff_attendance a ON a.user_id = u.id AND a.attendance_date = '$today'
        WHERE u.organization_id = ? AND u.is_active = 1 AND u.biometric_pin IS NOT NULL AND u.biometric_pin <> ''$scope ORDER BY u.name");
    $st->execute($p);
    $staff = $st->fetchAll();
    // आज रजा मंजूर / सुट्टी — "अजून आले नाहीत" मधून वगळा
    $onLeave = [];
    try {
        $l = $pdo->prepare("SELECT user_id FROM leave_applications WHERE organization_id = ? AND status = 'approved' AND ? BETWEEN start_date AND end_date");
        $l->execute([(int)$orgId, $today]);
        $onLeave = array_flip($l->fetchAll(PDO::FETCH_COLUMN));
    } catch (PDOException $e) {}
    $hol = $pdo->prepare("SELECT campus_id FROM holidays WHERE organization_id = ? AND holiday_date = ?");
    $hol->execute([(int)$orgId, $today]);
    $holCampus = $hol->fetchAll(PDO::FETCH_COLUMN);
    $orgHoliday = in_array(null, $holCampus, true);
    $dow = (int)date('w');

    $present = $inside = $late = []; $notArrived = []; $leave = 0;
    foreach ($staff as $s) {
        if ($s['first_punch_time']) {
            $present[] = $s;
            if ((int)$s['total_punches'] % 2 === 1) { $inside[] = $s; }
            if ((int)$s['late_minutes'] > 0) { $late[] = $s; }
            continue;
        }
        if (isset($onLeave[$s['id']]) || $s['status'] === 'on_leave') { $leave++; continue; }
        if ($orgHoliday || ($s['campus_id'] && in_array((string)$s['campus_id'], array_map('strval', $holCampus), true))) { continue; }
        $sh = bioShift($pdo, (int)$s['id']);
        if (in_array($dow, array_map('intval', $sh['off']), true)) { continue; }
        if (time() > strtotime("$today {$sh['start']}") + $sh['grace'] * 60) { $notArrived[] = $s + ['shift' => substr($sh['start'], 0, 5)]; }
    }
    usort($late, fn($a, $b) => $b['late_minutes'] <=> $a['late_minutes']);

    $d = $pdo->prepare("SELECT * FROM bio_devices WHERE organization_id = ? AND status = 'active' ORDER BY device_name");
    $d->execute([(int)$orgId]);
    $devices = $d->fetchAll();
    $online = count(array_filter($devices, 'bioDeviceOnline'));

    $fp = [(int)$orgId, (int)$afterId]; $fscope = '';
    $ids = getVisibleDepartmentIdsForStaffTracking();
    if ($ids !== null) { $fscope = $ids ? ' AND (u.department_id IN (' . implode(',', array_map('intval', $ids)) . '))' : ' AND 1 = 0'; }
    $f = $pdo->prepare("SELECT p.id, p.pin, p.punch_time, p.punch_state, p.verify_mode, u.name, d.device_name, d.location
        FROM bio_punches p JOIN bio_devices d ON d.id = p.device_id AND d.organization_id = ? AND d.status = 'active'
        LEFT JOIN users u ON u.id = p.user_id
        WHERE p.id > ? AND p.punch_time >= CURDATE()$fscope ORDER BY p.punch_time DESC, p.id DESC LIMIT 40");
    $f->execute($fp);
    $feed = $f->fetchAll();

    $pend = (int)$pdo->query("SELECT COUNT(*) FROM bio_devices WHERE status = 'pending'")->fetchColumn();
    $u = $pdo->prepare("SELECT COUNT(DISTINCT p.pin) FROM bio_punches p JOIN bio_devices d ON d.id = p.device_id AND d.organization_id = ? AND d.status = 'active'
        WHERE p.user_id IS NULL AND p.punch_time >= CURDATE() - INTERVAL 7 DAY");
    $u->execute([(int)$orgId]);
    $unmapped = (int)$u->fetchColumn();

    return ['staff' => count($staff), 'present' => $present, 'inside' => $inside, 'late' => $late, 'notArrived' => $notArrived, 'leave' => $leave,
        'devices' => $devices, 'online' => $online, 'feed' => $feed, 'pending' => $pend, 'unmapped' => $unmapped,
        'maxId' => $feed ? max(array_column($feed, 'id')) : (int)$afterId];
}
