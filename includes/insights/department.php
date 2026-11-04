<?php
// 🏢 विभाग Dashboard — Smart भाग (departments/dashboard.php मधून; $deptId, $department, $deptStaffList आहेत)
// 1) "लक्ष द्या" सूचना  2) ३० दिवसांचा कामाचा कल  3) टीम — आजची हजेरी + ३० दिवसांची कामगिरी
// टीमची कामगिरी/हजेरी फक्त HOD/व्यवस्थापनाला (employee-report चाच नियम) — साध्या कर्मचाऱ्याला फक्त नावांची यादी.
require_once __DIR__ . '/../smart-insights.php';
require_once __DIR__ . '/../employee-report-access.php';
require_once __DIR__ . '/../ticket-helpers.php';

$dOrg = activeOrgId();
$erVisible = employeeReportVisibleDeptIds();
$canSeeTeam = $erVisible === null || in_array($deptId, $erVisible, true);

$dq = function ($sql, $params) use ($pdo) {
    try { $st = $pdo->prepare($sql); $st->execute($params); return $st->fetchAll(); } catch (PDOException $e) { return []; }
};
$one = fn($sql, $params) => (int)(($r = $dq($sql, $params)) ? reset($r[0]) : 0);

// ---------- सूचना ----------
$overdueTasks = $one("SELECT COUNT(*) FROM tasks WHERE organization_id = ? AND department_id = ? AND status IN ('pending','in_progress') AND target_date < CURDATE()", [$dOrg, $deptId]);
$slaReq = $one("SELECT COUNT(*) FROM work_requests WHERE organization_id = ? AND department_id = ? AND status IN ('pending','in_progress') AND due_at < NOW()", [$dOrg, $deptId]);
$openReq = $one("SELECT COUNT(*) FROM work_requests WHERE organization_id = ? AND department_id = ? AND status IN ('pending','in_progress')", [$dOrg, $deptId]);
$expired = $one("SELECT COUNT(*) FROM renewal_items WHERE organization_id = ? AND department_id = ? AND status <> 'inactive' AND expiry_date < CURDATE()", [$dOrg, $deptId]);
$todayWork = $one("SELECT COUNT(*) FROM work_records WHERE organization_id = ? AND department_id = ? AND work_date = CURDATE()", [$dOrg, $deptId]);
$al = [];
if ($overdueTasks) $al[] = ['bad', $overdueTasks . ' Tasks ची मुदत संपली', (hasPermission('view_task_tracker') || hasPermission('assign_tasks')) ? '../tasks/index.php?status=overdue&department_id=' . $deptId : '../tasks/my-tasks.php'];
if ($slaReq) $al[] = ['bad', $slaReq . ' कामाच्या विनंत्या SLA बाहेर (एकूण उघड्या ' . $openReq . ')', '../requests/index.php?status=overdue&department_id=' . $deptId];
if ($expired) $al[] = ['bad', $expired . ' नूतनीकरणांची मुदत संपली', '../renewals/index.php?filter=expired&department_id=' . $deptId];
if (!empty($budgetStatus['has_budget']) && $budgetStatus['remaining'] < 0) $al[] = ['bad', 'Budget पेक्षा ₹' . number_format(abs($budgetStatus['remaining'])) . ' जास्त खर्च', ''];
elseif (!empty($budgetStatus['has_budget']) && $budgetStatus['percent_used'] > 80) $al[] = ['warn', 'Budget चा ' . round($budgetStatus['percent_used']) . '% वापर झाला', ''];
if (!$todayWork && (int)date('G') >= 14) $al[] = ['info', 'आज अजून एकही काम नोंदवलेलं नाही', '../daily-work/entry.php'];

// ---------- टीम ----------
$team = [];
if ($canSeeTeam && $deptStaffList) {
    $ids = array_map(fn($s) => (int)$s['id'], $deptStaffList);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $byUid = function ($rows) { $o = []; foreach ($rows as $r) { $o[(int)$r['uid']] = $r; } return $o; };
    $attToday = $byUid($dq("SELECT user_id uid, status, is_late, first_punch_time FROM staff_attendance WHERE attendance_date = CURDATE() AND user_id IN ($ph)", $ids));
    $att30 = $byUid($dq("SELECT user_id uid, SUM(status = 'present') p, SUM(status = 'half_day') hd, SUM(status = 'absent') a FROM staff_attendance
        WHERE attendance_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) AND user_id IN ($ph) GROUP BY user_id", $ids));
    $work30 = $byUid($dq("SELECT x.uid, COUNT(*) total, SUM(x.status = 'completed') done FROM (
            SELECT wr.id, wr.status, wr.employee_id uid FROM work_records wr WHERE wr.organization_id = ? AND wr.work_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) AND wr.employee_id IN ($ph)
            UNION SELECT wr.id, wr.status, wt.user_id uid FROM work_records wr JOIN work_record_team wt ON wt.work_record_id = wr.id
            WHERE wr.organization_id = ? AND wr.work_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) AND wt.user_id IN ($ph)) x GROUP BY x.uid",
        array_merge([$dOrg], $ids, [$dOrg], $ids)));
    $tasksOpen = $byUid($dq("SELECT assigned_to uid, COUNT(*) open_cnt, SUM(target_date < CURDATE()) overdue FROM tasks
        WHERE organization_id = ? AND status IN ('pending','in_progress') AND assigned_to IN ($ph) GROUP BY assigned_to", array_merge([$dOrg], $ids)));
    $onLeave = $byUid($dq("SELECT user_id uid, 1 x FROM leave_applications WHERE status = 'approved' AND CURDATE() BETWEEN start_date AND end_date AND user_id IN ($ph)", $ids));
    foreach ($deptStaffList as $s) {
        if (!$s['is_active']) continue;
        $id = (int)$s['id'];
        $at = $attToday[$id] ?? null;
        if ($at) {
            $todayKey = $at['status'] === 'present' ? ($at['is_late'] ? 'late' : 'present') : $at['status'];
        } else {
            $todayKey = isset($onLeave[$id]) ? 'on_leave' : 'none';
        }
        $a = $att30[$id] ?? null;
        $base = $a ? (int)$a['p'] + (int)$a['hd'] + (int)$a['a'] : 0;
        $team[] = [
            's' => $s, 'today' => $todayKey, 'punch' => $at['first_punch_time'] ?? null,
            'w' => (int)($work30[$id]['total'] ?? 0), 'wd' => (int)($work30[$id]['done'] ?? 0),
            'to' => (int)($tasksOpen[$id]['open_cnt'] ?? 0), 'tod' => (int)($tasksOpen[$id]['overdue'] ?? 0),
            'att' => $base ? (int)round(((int)$a['p'] + 0.5 * (int)$a['hd']) * 100 / $base) : null,
        ];
    }
    $absentNow = count(array_filter($team, fn($t) => $t['today'] === 'absent'));
    $notMarked = count(array_filter($team, fn($t) => $t['today'] === 'none'));
    if ($absentNow) array_unshift($al, ['warn', 'आज ' . $absentNow . ' कर्मचारी गैरहजर', '../staff/attendance.php']);
    if ($notMarked && (int)date('G') >= 11) $al[] = ['info', $notMarked . ' कर्मचाऱ्यांची आजची हजेरी नोंद नाही', '../staff/attendance.php'];
}
smartAlerts($al);

// ---------- ३० दिवसांचा कल ----------
$trend = [];
foreach ($dq("SELECT work_date d, COUNT(*) total, SUM(status = 'completed') done FROM work_records
    WHERE organization_id = ? AND department_id = ? AND work_date >= DATE_SUB(CURDATE(), INTERVAL 29 DAY) GROUP BY work_date", [$dOrg, $deptId]) as $r) { $trend[$r['d']] = $r; }
$series = [];
for ($i = 29; $i >= 0; $i--) { $d = date('Y-m-d', strtotime("-$i days")); $series[] = ['d' => date('d M', strtotime($d)), 't' => (int)($trend[$d]['total'] ?? 0), 'c' => (int)($trend[$d]['done'] ?? 0)]; }
$sum30 = array_sum(array_column($series, 't')); $done30 = array_sum(array_column($series, 'c'));

$todayChip = [
    'present' => ['✅ हजर', 'done'], 'late' => ['⏰ उशीर', 'pending'], 'absent' => ['❌ गैरहजर', 'overdue'],
    'half_day' => ['🟡 अर्धा दिवस', 'pending'], 'on_leave' => ['🏖️ रजेवर', 'pending'], 'holiday' => ['🎉 सुट्टी', 'done'], 'none' => ['— नोंद नाही', ''],
];
?>
<div class="row g-3 mb-4">
  <div class="col-lg-<?= $canSeeTeam ? '5' : '12' ?>">
    <div class="panel h-100">
      <div class="panel-head d-flex justify-content-between align-items-center"><div><div class="title">📈 मागील ३० दिवस — कामाचा कल</div><div class="sub"><?= $sum30 ?> कामं · <?= $done30 ?> पूर्ण<?= $sum30 ? ' (' . round($done30 * 100 / $sum30) . '%)' : '' ?></div></div></div>
      <div style="padding:12px 16px;height:<?= $canSeeTeam ? '300' : '220' ?>px;position:relative;"><canvas id="deptTrendChart"></canvas></div>
    </div>
  </div>
  <?php if ($canSeeTeam): ?>
  <div class="col-lg-7">
    <div class="panel h-100">
      <div class="panel-head d-flex justify-content-between align-items-center">
        <div><div class="title">👥 टीम — आज व मागील ३० दिवस</div><div class="sub">आजची हजेरी, कामं, उघडे Tasks व हजेरी %</div></div>
        <a href="../staff/department-report.php?department_id=<?= $deptId ?>" class="sm-link">संपूर्ण अहवाल →</a>
      </div>
      <div class="table-responsive" style="max-height:330px;overflow-y:auto;">
      <table class="rtable" data-no-filter>
        <thead><tr><th>कर्मचारी</th><th>आज</th><th title="मागील ३० दिवस">कामं (पूर्ण)</th><th>उघडे Tasks</th><th>हजेरी %</th></tr></thead>
        <tbody>
          <?php if (!$team): ?><tr><td colspan="5" class="sm-empty">सक्रिय कर्मचारी नाहीत</td></tr><?php endif; ?>
          <?php foreach ($team as $t): [$lbl, $chip] = $todayChip[$t['today']] ?? [$t['today'], '']; ?>
            <tr>
              <td><a href="../staff/profile.php?id=<?= (int)$t['s']['id'] ?>" style="font-weight:600;"><?= h($t['s']['name']) ?></a><div style="font-size:11px;color:var(--text-dim);"><?= h($t['s']['designation'] ?? '') ?></div></td>
              <td><span class="status-chip <?= $chip ?>" style="<?= $chip ? '' : 'color:var(--text-dim);' ?>"><?= $lbl ?></span><?php if ($t['punch']): ?><div class="font-mono" style="font-size:10.5px;color:var(--text-dim);"><?= substr($t['punch'], 0, 5) ?></div><?php endif; ?></td>
              <td class="font-mono" data-sort="<?= $t['w'] ?>"><?= $t['w'] ?> <span style="color:var(--text-dim);font-size:11px;">(<?= $t['wd'] ?>)</span></td>
              <td class="font-mono" data-sort="<?= $t['to'] ?>"><?= $t['to'] ?><?= $t['tod'] ? ' <span style="color:var(--rose);font-size:11px;">' . $t['tod'] . '⏰</span>' : '' ?></td>
              <td class="font-mono" data-sort="<?= $t['att'] ?? -1 ?>" style="color:<?= $t['att'] === null ? 'var(--text-dim)' : ($t['att'] >= 90 ? 'var(--rail)' : ($t['att'] >= 75 ? 'var(--amber)' : 'var(--rose)')) ?>;"><?= $t['att'] === null ? '—' : $t['att'] . '%' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
<script>
window.addEventListener('DOMContentLoaded', function () {
  if (typeof Chart === 'undefined') return;
  var s = <?= json_encode($series) ?>, dim = getComputedStyle(document.documentElement).getPropertyValue('--text-dim').trim() || '#6B7080';
  new Chart(document.getElementById('deptTrendChart'), { type: 'bar',
    data: { labels: s.map(function (x) { return x.d; }), datasets: [
      { label: 'एकूण', data: s.map(function (x) { return x.t; }), backgroundColor: 'rgba(107,112,128,.35)', borderRadius: 3, maxBarThickness: 14 },
      { label: 'पूर्ण', data: s.map(function (x) { return x.c; }), backgroundColor: '#1B8A83', borderRadius: 3, maxBarThickness: 14 } ] },
    options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
      plugins: { legend: { position: 'bottom', labels: { color: dim, boxWidth: 10, font: { size: 11 } } } },
      scales: { x: { grid: { display: false }, ticks: { color: dim, font: { size: 10 }, maxRotation: 0, autoSkip: true, maxTicksLimit: 8 } }, y: { beginAtZero: true, ticks: { color: dim, precision: 0 } } } } });
});
</script>
