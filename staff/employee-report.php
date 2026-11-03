<?php
// 📄 कर्मचारी — संपूर्ण कामगिरी अहवाल (रुजू झाल्यापासून किंवा निवडलेल्या कालावधीचा)
// कामाच्या नोंदी, Tasks, हजेरी, रजा व कामाच्या विनंत्या — सारांश, महिनानिहाय
// विभाजन आणि संपूर्ण यादी. Print / PDF / CSV.
// Access: staff/profile.php प्रमाणेच — स्वतःचा अहवाल नेहमी; इतरांचा फक्त
// getVisibleDepartmentIdsForStaffTracking() मधील विभागांचा (HOD = स्वतःचा विभाग).
$pageTitle = 'कर्मचारी — संपूर्ण अहवाल';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$orgId = activeOrgId();
require_once __DIR__ . '/../includes/employee-report-access.php';
$visibleDeptIds = employeeReportVisibleDeptIds();
$hasBroadAccess = $visibleDeptIds === null;

// कोणत्या कर्मचाऱ्यांचा अहवाल काढता येईल — निवड-यादीसाठी
if ($hasBroadAccess) {
    $pickStmt = $pdo->prepare("SELECT u.id, u.name, u.designation, d.name_local AS dept FROM users u LEFT JOIN departments d ON d.id = u.department_id
        WHERE u.organization_id = ? ORDER BY d.name_local, u.name");
    $pickStmt->execute([$orgId]);
} elseif ($visibleDeptIds) {
    $ph = implode(',', array_fill(0, count($visibleDeptIds), '?'));
    $pickStmt = $pdo->prepare("SELECT u.id, u.name, u.designation, d.name_local AS dept FROM users u LEFT JOIN departments d ON d.id = u.department_id
        WHERE u.organization_id = ? AND (u.department_id IN ($ph) OR u.id = ?) ORDER BY d.name_local, u.name");
    $pickStmt->execute(array_merge([$orgId], $visibleDeptIds, [$user['id']]));
} else {
    $pickStmt = $pdo->prepare("SELECT u.id, u.name, u.designation, d.name_local AS dept FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.id = ?");
    $pickStmt->execute([$user['id']]);
}
$pickList = $pickStmt->fetchAll();
$allowedIds = array_map(fn($p) => (int)$p['id'], $pickList);

$empId = (int)($_GET['id'] ?? 0) ?: (int)$user['id'];
if (!in_array($empId, $allowedIds, true)) {
    http_response_code(403);
    echo '<div class="alert alert-danger">या कर्मचाऱ्याचा अहवाल पाहण्याची तुम्हाला परवानगी नाही.</div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$stmt = $pdo->prepare("SELECT u.*, d.name_local AS dept_name, c.name AS campus_name FROM users u
    LEFT JOIN departments d ON d.id = u.department_id LEFT JOIN campuses c ON c.id = u.campus_id
    WHERE u.id = ? AND u.organization_id = ?");
$stmt->execute([$empId, $orgId]);
$emp = $stmt->fetch();
if (!$emp) { echo '<div class="alert alert-danger">कर्मचारी सापडला नाही.</div>'; require_once __DIR__ . '/../includes/footer.php'; exit; }

// ---------- कालावधी ----------
$joinDate = $emp['joining_date'] ?: substr((string)$emp['created_at'], 0, 10);
$validDate = fn($d) => ($x = DateTime::createFromFormat('!Y-m-d', (string)$d)) && $x->format('Y-m-d') === $d;
$range = $_GET['range'] ?? 'join';
$today = date('Y-m-d');
switch ($range) {
    case 'month': $from = date('Y-m-01'); $to = $today; break;
    case '3m': $from = date('Y-m-d', strtotime('-3 months +1 day')); $to = $today; break;
    case 'year': $from = date('Y-01-01'); $to = $today; break;
    case 'fy': $fyStart = (int)date('n') >= 4 ? date('Y') : date('Y') - 1; $from = $fyStart . '-04-01'; $to = $today; break;
    case 'custom':
        $from = $validDate($_GET['from'] ?? '') ? $_GET['from'] : $joinDate;
        $to = $validDate($_GET['to'] ?? '') ? $_GET['to'] : $today;
        break;
    default: $range = 'join'; $from = $joinDate; $to = $today;
}
if ($from > $to) { [$from, $to] = [$to, $from]; }
$days = (int)((strtotime($to) - strtotime($from)) / 86400) + 1;

// ---------- कामाच्या नोंदी (स्वतः किंवा Team मध्ये) ----------
$wrScope = "wr.organization_id = ? AND wr.work_date BETWEEN ? AND ?
    AND (wr.employee_id = ? OR EXISTS (SELECT 1 FROM work_record_team wt WHERE wt.work_record_id = wr.id AND wt.user_id = ?))";
$wrParams = [$orgId, $from, $to, $empId, $empId];
$st = $pdo->prepare("SELECT wr.id, wr.work_code, wr.work_date, wr.title, wr.status, wr.priority, wr.completion_date, wr.actual_cost, d.name_local AS dept
    FROM work_records wr LEFT JOIN departments d ON d.id = wr.department_id WHERE $wrScope ORDER BY wr.work_date DESC, wr.id DESC");
$st->execute($wrParams);
$works = $st->fetchAll();
$wDone = count(array_filter($works, fn($w) => $w['status'] === 'completed'));
$wOpen = count($works) - $wDone;

// ---------- Tasks (या व्यक्तीला दिलेले) ----------
$tasks = [];
try {
    $st = $pdo->prepare("SELECT t.task_code, t.title, t.priority, t.status, t.target_date, t.completed_at, t.created_at, t.verified_at, u.name AS assigner
        FROM tasks t LEFT JOIN users u ON u.id = t.assigned_by
        WHERE t.organization_id = ? AND t.assigned_to = ? AND DATE(t.created_at) BETWEEN ? AND ? ORDER BY t.created_at DESC");
    $st->execute([$orgId, $empId, $from, $to]);
    $tasks = $st->fetchAll();
} catch (PDOException $e) {}
$tDone = array_filter($tasks, fn($t) => $t['status'] === 'completed');
$tOnTime = count(array_filter($tDone, fn($t) => $t['completed_at'] && substr($t['completed_at'], 0, 10) <= $t['target_date']));
$tOverdue = count(array_filter($tasks, fn($t) => in_array($t['status'], ['pending', 'in_progress'], true) && $t['target_date'] < $today));

// ---------- हजेरी ----------
$att = ['present' => 0, 'absent' => 0, 'half_day' => 0, 'on_leave' => 0, 'holiday' => 0, 'late' => 0, 'early' => 0];
$attByMonth = [];
try {
    $st = $pdo->prepare("SELECT attendance_date, status, is_late, is_early_leave FROM staff_attendance WHERE user_id = ? AND attendance_date BETWEEN ? AND ?");
    $st->execute([$empId, $from, $to]);
    foreach ($st->fetchAll() as $a) {
        if (isset($att[$a['status']])) $att[$a['status']]++;
        if ($a['is_late']) $att['late']++;
        if ($a['is_early_leave']) $att['early']++;
        $m = substr($a['attendance_date'], 0, 7);
        $attByMonth[$m] = $attByMonth[$m] ?? ['p' => 0, 'a' => 0, 'l' => 0];
        if ($a['status'] === 'present') $attByMonth[$m]['p']++;
        if ($a['status'] === 'half_day') $attByMonth[$m]['p'] += 0.5;
        if ($a['status'] === 'absent') $attByMonth[$m]['a']++;
        if ($a['is_late']) $attByMonth[$m]['l']++;
    }
} catch (PDOException $e) {}
$attBase = $att['present'] + $att['half_day'] + $att['absent'];
$attPct = $attBase > 0 ? round(($att['present'] + 0.5 * $att['half_day']) * 100 / $attBase) : null;

// ---------- रजा ----------
$leaves = [];
try {
    $st = $pdo->prepare("SELECT la.start_date, la.end_date, la.total_days, la.status, la.reason, lt.name_local AS type
        FROM leave_applications la LEFT JOIN leave_types lt ON lt.id = la.leave_type_id
        WHERE la.user_id = ? AND la.start_date <= ? AND la.end_date >= ? ORDER BY la.start_date DESC");
    $st->execute([$empId, $to, $from]);
    $leaves = $st->fetchAll();
} catch (PDOException $e) {}
$leaveDays = array_sum(array_map(fn($l) => $l['status'] === 'approved' ? (float)$l['total_days'] : 0, $leaves));

// ---------- कामाच्या विनंत्या (या व्यक्तीला नेमलेल्या) ----------
$reqs = ['total' => 0, 'done' => 0];
try {
    $st = $pdo->prepare("SELECT COUNT(*) total, SUM(status IN ('completed','closed')) done FROM work_requests
        WHERE organization_id = ? AND assigned_to_user_id = ? AND DATE(created_at) BETWEEN ? AND ?");
    $st->execute([$orgId, $empId, $from, $to]);
    $reqs = array_map('intval', $st->fetch() ?: $reqs);
} catch (PDOException $e) {}

// ---------- महिनानिहाय विभाजन ----------
$months = [];
$cur = new DateTime(substr($from, 0, 7) . '-01');
$end = new DateTime(substr($to, 0, 7) . '-01');
while ($cur <= $end) { $months[$cur->format('Y-m')] = ['w' => 0, 'wd' => 0, 't' => 0, 'td' => 0]; $cur->modify('+1 month'); }
foreach ($works as $w) { $m = substr($w['work_date'], 0, 7); if (isset($months[$m])) { $months[$m]['w']++; if ($w['status'] === 'completed') $months[$m]['wd']++; } }
foreach ($tasks as $t) { $m = substr($t['created_at'], 0, 7); if (isset($months[$m])) { $months[$m]['t']++; if ($t['status'] === 'completed') $months[$m]['td']++; } }
$months = array_reverse($months, true);

// सेवा-कालावधी
$svc = (new DateTime($joinDate))->diff(new DateTime($today));
$svcText = ($svc->y ? $svc->y . ' वर्षे ' : '') . $svc->m . ' महिने';

$statusLbl = ['pending' => 'प्रलंबित', 'in_progress' => 'सुरू आहे', 'completed' => 'पूर्ण', 'cancelled' => 'रद्द', 'approved' => 'मंजूर', 'rejected' => 'नामंजूर'];
$statusChip = ['pending' => 'pending', 'in_progress' => 'pending', 'completed' => 'done', 'cancelled' => 'overdue', 'approved' => 'done', 'rejected' => 'overdue'];
$qs = fn($r) => '?id=' . $empId . '&range=' . $r;

// मागील / पुढील कर्मचारी (याच विभागातील) — HOD ला एकामागून एक अहवाल पाहता / Print करता यावा
$sameDept = array_values(array_filter($pickList, fn($p) => $p['dept'] === $emp['dept_name']));
$pos = array_search($empId, array_map(fn($p) => (int)$p['id'], $sameDept), true);
$navQs = '&range=' . urlencode($range) . ($range === 'custom' ? '&from=' . urlencode($from) . '&to=' . urlencode($to) : '');
$prevEmp = ($pos !== false && $pos > 0) ? $sameDept[$pos - 1] : null;
$nextEmp = ($pos !== false && $pos < count($sameDept) - 1) ? $sameDept[$pos + 1] : null;
?>

<div class="page-eyebrow no-print"><a href="profile.php?id=<?= $empId ?>" style="color:inherit;text-decoration:none;">← प्रोफाइल</a></div>
<div class="d-flex justify-content-between align-items-start mb-2 flex-wrap gap-2">
  <div class="page-title mb-0">📄 कर्मचारी — संपूर्ण अहवाल</div>
  <div class="d-flex gap-2 no-print">
    <?php if ($hasBroadAccess || $visibleDeptIds): ?><a href="department-report.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 16px;"><i class="fa-solid fa-people-group me-1"></i>विभाग सारांश</a><?php endif; ?>
    <?php if (count($sameDept) > 1): ?>
      <a href="<?= $prevEmp ? h('?id=' . (int)$prevEmp['id'] . $navQs) : '#' ?>" class="btn <?= $prevEmp ? '' : 'disabled' ?>" title="<?= $prevEmp ? h('मागील: ' . $prevEmp['name']) : '' ?>" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 12px;"><i class="fa-solid fa-chevron-left"></i></a>
      <span style="align-self:center;font-size:12px;color:var(--text-dim);white-space:nowrap;"><?= $pos + 1 ?> / <?= count($sameDept) ?></span>
      <a href="<?= $nextEmp ? h('?id=' . (int)$nextEmp['id'] . $navQs) : '#' ?>" class="btn <?= $nextEmp ? '' : 'disabled' ?>" title="<?= $nextEmp ? h('पुढील: ' . $nextEmp['name']) : '' ?>" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 12px;"><i class="fa-solid fa-chevron-right"></i></a>
    <?php endif; ?>
    <button type="button" class="btn" onclick="window.print()" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;"><i class="fa-solid fa-print"></i> Print / PDF</button>
  </div>
</div>

<div class="panel mb-3 no-print">
  <form method="GET" class="er-filter">
    <label>कर्मचारी
      <select name="id" class="form-select-custom" onchange="this.form.submit()">
        <?php $lastDept = null; foreach ($pickList as $p): if ($p['dept'] !== $lastDept): if ($lastDept !== null) echo '</optgroup>'; $lastDept = $p['dept']; ?><optgroup label="<?= h($p['dept'] ?: '—') ?>"><?php endif; ?>
          <option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === $empId ? 'selected' : '' ?>><?= h($p['name']) ?><?= $p['designation'] ? ' — ' . h($p['designation']) : '' ?></option>
        <?php endforeach; if ($lastDept !== null) echo '</optgroup>'; ?>
      </select>
    </label>
    <div class="er-ranges">
      <?php foreach (['join' => 'रुजू झाल्यापासून', 'year' => 'हे वर्ष', 'fy' => 'आर्थिक वर्ष', '3m' => '३ महिने', 'month' => 'हा महिना'] as $k => $lbl): ?>
        <a href="<?= h($qs($k)) ?>" class="period-btn <?= $range === $k ? 'active' : '' ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
    </div>
    <input type="hidden" name="range" value="custom">
    <label>पासून <input type="date" name="from" class="form-control-custom" value="<?= h($from) ?>"></label>
    <label>पर्यंत <input type="date" name="to" class="form-control-custom" value="<?= h($to) ?>"></label>
    <button type="submit" class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;padding:8px 14px;">दाखवा</button>
  </form>
</div>

<!-- ओळख -->
<div class="panel mb-3 er-head">
  <div class="er-avatar"><?= h(mb_strtoupper(mb_substr($emp['name'], 0, 1))) ?></div>
  <div class="sm-main">
    <div style="font-size:19px;font-weight:700;"><?= h($emp['name']) ?></div>
    <div class="sm-s" style="white-space:normal;"><?= h($emp['designation'] ?: '—') ?> · <?= h($emp['dept_name'] ?: '—') ?><?= $emp['campus_name'] ? ' · ' . h($emp['campus_name']) : '' ?><?= !empty($emp['employee_code']) ? ' · ' . h($emp['employee_code']) : '' ?></div>
    <div class="sm-s" style="white-space:normal;">रुजू: <b><?= date('d M Y', strtotime($joinDate)) ?></b> (<?= h($svcText) ?>)<?= empty($emp['is_active']) ? ' · <span style="color:var(--rose);">निष्क्रिय</span>' : '' ?></div>
  </div>
  <div class="er-period"><span>अहवाल कालावधी</span><b><?= date('d M Y', strtotime($from)) ?> — <?= date('d M Y', strtotime($to)) ?></b><small><?= $days ?> दिवस</small></div>
</div>

<?php require_once __DIR__ . '/../includes/smart-insights.php';
smartTiles([
    ['label' => 'कामाच्या नोंदी', 'value' => count($works), 'sub' => $wDone . ' पूर्ण · ' . $wOpen . ' बाकी', 'tone' => ''],
    ['label' => 'काम पूर्णत्व दर', 'value' => count($works) ? round($wDone * 100 / count($works)) . '%' : '—', 'tone' => count($works) && $wDone * 100 / count($works) >= 80 ? 'ok' : 'warn'],
    ['label' => 'Tasks', 'value' => count($tasks), 'sub' => count($tDone) . ' पूर्ण · वेळेत ' . (count($tDone) ? round($tOnTime * 100 / count($tDone)) : 0) . '%' . ($tOverdue ? ' · ' . $tOverdue . ' मुदतबाह्य' : ''), 'tone' => $tOverdue ? 'bad' : ''],
    ['label' => 'हजेरी दर', 'value' => $attPct === null ? '—' : $attPct . '%', 'sub' => $att['present'] . ' हजर · ' . $att['absent'] . ' गैरहजर · ' . $att['late'] . ' उशीर', 'tone' => $attPct === null ? '' : ($attPct >= 90 ? 'ok' : ($attPct >= 75 ? 'warn' : 'bad'))],
    ['label' => 'रजा (मंजूर)', 'value' => rtrim(rtrim(number_format($leaveDays, 1), '0'), '.') . ' दिवस', 'sub' => 'विनंत्या हाताळल्या: ' . $reqs['done'] . '/' . $reqs['total']],
]); ?>

<!-- महिनानिहाय -->
<div class="panel mb-3">
  <div class="panel-head"><div class="title">📅 महिनानिहाय कामगिरी</div><div class="sub">कामं, Tasks व हजेरी — प्रत्येक महिन्याचं चित्र</div></div>
  <div class="table-responsive">
  <table class="rtable" data-no-filter>
    <thead><tr><th>महिना</th><th>कामं</th><th>पूर्ण</th><th>Tasks</th><th>Tasks पूर्ण</th><th>हजर दिवस</th><th>गैरहजर</th><th>उशीर</th></tr></thead>
    <tbody>
      <?php foreach ($months as $m => $r): $a = $attByMonth[$m] ?? ['p' => 0, 'a' => 0, 'l' => 0]; ?>
        <tr>
          <td data-sort="<?= $m ?>"><b><?= date('M Y', strtotime($m . '-01')) ?></b></td>
          <td class="font-mono"><?= $r['w'] ?></td>
          <td class="font-mono"><?= $r['wd'] ?><?= $r['w'] ? ' <span style="color:var(--text-dim);font-size:11px;">(' . round($r['wd'] * 100 / $r['w']) . '%)</span>' : '' ?></td>
          <td class="font-mono"><?= $r['t'] ?></td>
          <td class="font-mono"><?= $r['td'] ?></td>
          <td class="font-mono"><?= rtrim(rtrim(number_format($a['p'], 1), '0'), '.') ?></td>
          <td class="font-mono" style="<?= $a['a'] ? 'color:var(--rose);' : '' ?>"><?= $a['a'] ?></td>
          <td class="font-mono" style="<?= $a['l'] ? 'color:var(--amber);' : '' ?>"><?= $a['l'] ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<!-- कामाच्या नोंदी -->
<div class="panel mb-3">
  <div class="panel-head"><div class="title">🛠️ कामाच्या नोंदी (<?= count($works) ?>)</div><div class="sub">स्वतः केलेली किंवा Team मध्ये सहभागी</div></div>
  <?php if (!$works): ?><div class="sm-empty">या कालावधीत नोंदी नाहीत</div><?php else: ?>
  <div class="table-responsive">
  <table class="rtable">
    <thead><tr><th>दिनांक</th><th>क्रमांक</th><th>काम</th><th>विभाग</th><th>स्थिती</th><th>पूर्ण दिनांक</th></tr></thead>
    <tbody>
      <?php foreach ($works as $w): ?>
        <tr>
          <td class="font-mono"><?= date('d M Y', strtotime($w['work_date'])) ?></td>
          <td class="font-mono" style="font-size:11.5px;"><?= h($w['work_code']) ?></td>
          <td><?= h($w['title']) ?></td>
          <td><?= h($w['dept'] ?? '') ?></td>
          <td><span class="status-chip <?= $statusChip[$w['status']] ?? 'pending' ?>"><?= $statusLbl[$w['status']] ?? h($w['status']) ?></span></td>
          <td class="font-mono"><?= $w['completion_date'] ? date('d M Y', strtotime($w['completion_date'])) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<!-- Tasks -->
<div class="panel mb-3">
  <div class="panel-head"><div class="title">✅ Tasks (<?= count($tasks) ?>)</div><div class="sub">या कर्मचाऱ्याला दिलेले · वेळेत पूर्ण <?= $tOnTime ?> / <?= count($tDone) ?></div></div>
  <?php if (!$tasks): ?><div class="sm-empty">या कालावधीत Tasks नाहीत</div><?php else: ?>
  <div class="table-responsive">
  <table class="rtable">
    <thead><tr><th>दिलेला दिनांक</th><th>Task</th><th>कोणी दिला</th><th>मुदत</th><th>पूर्ण</th><th>स्थिती</th></tr></thead>
    <tbody>
      <?php foreach ($tasks as $t):
        $late = $t['status'] === 'completed' && $t['completed_at'] && substr($t['completed_at'], 0, 10) > $t['target_date'];
        $overdue = in_array($t['status'], ['pending', 'in_progress'], true) && $t['target_date'] < $today; ?>
        <tr>
          <td class="font-mono"><?= date('d M Y', strtotime($t['created_at'])) ?></td>
          <td><?= h($t['title']) ?><div style="font-size:11px;color:var(--text-dim);" class="font-mono"><?= h($t['task_code']) ?></div></td>
          <td><?= h($t['assigner'] ?? '—') ?></td>
          <td class="font-mono"><?= date('d M Y', strtotime($t['target_date'])) ?></td>
          <td class="font-mono"><?= $t['completed_at'] ? date('d M Y', strtotime($t['completed_at'])) . ($late ? ' <span style="color:var(--rose);font-size:11px;">उशिरा</span>' : '') : '—' ?></td>
          <td><span class="status-chip <?= $overdue ? 'overdue' : ($statusChip[$t['status']] ?? 'pending') ?>"><?= $overdue ? 'मुदत संपली' : ($statusLbl[$t['status']] ?? h($t['status'])) ?><?= $t['verified_at'] ? ' ✔' : '' ?></span></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?php endif; ?>
</div>

<!-- रजा -->
<div class="panel mb-3">
  <div class="panel-head"><div class="title">🏖️ रजा (<?= count($leaves) ?>)</div><div class="sub">मंजूर एकूण <?= rtrim(rtrim(number_format($leaveDays, 1), '0'), '.') ?> दिवस · हजेरी: अर्धा दिवस <?= $att['half_day'] ?>, लवकर गेले <?= $att['early'] ?></div></div>
  <?php if (!$leaves): ?><div class="sm-empty">या कालावधीत रजा-अर्ज नाहीत</div><?php else: ?>
  <table class="rtable" data-no-filter>
    <thead><tr><th>पासून</th><th>पर्यंत</th><th>दिवस</th><th>प्रकार</th><th>कारण</th><th>स्थिती</th></tr></thead>
    <tbody>
      <?php foreach ($leaves as $l): ?>
        <tr>
          <td class="font-mono"><?= date('d M Y', strtotime($l['start_date'])) ?></td>
          <td class="font-mono"><?= date('d M Y', strtotime($l['end_date'])) ?></td>
          <td class="font-mono"><?= rtrim(rtrim(number_format((float)$l['total_days'], 1), '0'), '.') ?></td>
          <td><?= h($l['type'] ?? '—') ?></td>
          <td><?= h($l['reason']) ?></td>
          <td><span class="status-chip <?= $statusChip[$l['status']] ?? 'pending' ?>"><?= $statusLbl[$l['status']] ?? h($l['status']) ?></span></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</div>

<div class="er-sign">
  <div>दिनांक: <?= date('d M Y') ?></div>
  <div>विभाग प्रमुख — सही</div>
</div>

<style>
  .er-filter { display: flex; flex-wrap: wrap; gap: 12px; align-items: end; padding: 14px 18px; }
  .er-filter label { display: flex; flex-direction: column; gap: 4px; font-size: 11.5px; color: var(--text-dim); font-weight: 600; }
  .er-filter select { min-width: 240px; }
  .er-ranges { display: flex; flex-wrap: wrap; gap: 4px; background: var(--canvas); border-radius: 9px; padding: 4px; }
  .er-head { display: flex; align-items: center; gap: 16px; padding: 16px 20px; flex-wrap: wrap; }
  .er-avatar { width: 56px; height: 56px; border-radius: 14px; background: var(--rail); color: #fff; font-size: 24px; font-weight: 700; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
  .er-period { text-align: right; } .er-period span { display: block; font-size: 11px; color: var(--text-dim); font-weight: 600; } .er-period small { display: block; color: var(--text-dim); }
  .er-sign { display: none; }
  @media print {
    .no-print, .tbl-filter, .tbl-csv { display: none !important; }
    .er-sign { display: flex; justify-content: space-between; margin-top: 40px; font-size: 13px; }
    .er-sign div:last-child { border-top: 1px solid #333; padding-top: 6px; min-width: 200px; text-align: center; }
    .table-responsive { overflow: visible !important; }
    .rtable { font-size: 11px; } .rtable td, .rtable th { padding: 5px 8px !important; }
  }
</style>

<?php if (($_GET['autoprint'] ?? '') === '1'): ?>
<script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 500); });</script>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
