<?php
// 🏛️ Campus Dashboard — एका Campus चं संपूर्ण चित्र (Campus प्रमुख / केंद्रीय प्रमुख / Management / Super Admin)
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/campus-heads.php';
require_once __DIR__ . '/../includes/dashboard-helpers.php';
$me = currentUser();
$orgId = activeOrgId();
$list = campusDashboardList($pdo, $orgId);
if (!$list) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">तुम्हाला कोणत्याही Campus चा Dashboard पाहण्याचा Access नाही. <a href="../index.php">' . h(t('s16')) . '</a></div>'); }
$campusId = (int)($_GET['id'] ?? $list[0]['id']);
if (!campusDashboardAllowed($pdo, $campusId)) { $campusId = (int)$list[0]['id']; }
$st = $pdo->prepare("SELECT * FROM campuses WHERE id = ? AND organization_id = ?");
$st->execute([$campusId, $orgId]);
$campus = $st->fetch();
if (!$campus) { header('Location: dashboard.php'); exit; }

$deptSt = $pdo->prepare("SELECT d.id, COALESCE(NULLIF(d.name_local,''), d.name) AS name, u.name AS hod_name FROM departments d LEFT JOIN users u ON u.id = d.hod_id
    WHERE d.organization_id = ? AND d.campus_id = ? AND d.is_active = 1 ORDER BY name");
$deptSt->execute([$orgId, $campusId]);
$depts = $deptSt->fetchAll();
$deptIds = array_map('intval', array_column($depts, 'id')) ?: [0];
$ph = implode(',', array_fill(0, count($deptIds), '?'));
$M = getDashboardRowMetrics($pdo, $orgId, $campusId, false);
$q = function ($sql, $params = []) use ($pdo) { $s = $pdo->prepare($sql); $s->execute($params); return $s; };
$one = fn($sql, $params = []) => (int)$q($sql, $params)->fetchColumn();

$todayWork = $one("SELECT COUNT(*) FROM work_records WHERE department_id IN ($ph) AND work_date = CURDATE()", $deptIds);
$todayDone = $one("SELECT COUNT(*) FROM work_records WHERE department_id IN ($ph) AND work_date = CURDATE() AND status = 'completed'", $deptIds);
$att = ['present' => 0, 'absent' => 0, 'late' => 0, 'marked' => 0];
$absentList = [];
try {
    foreach ($q("SELECT sa.status, sa.is_late FROM staff_attendance sa JOIN users u ON u.id = sa.user_id WHERE u.department_id IN ($ph) AND u.is_active = 1 AND sa.attendance_date = CURDATE()", $deptIds)->fetchAll() as $r) {
        $att['marked']++;
        if ($r['status'] === 'absent') { $att['absent']++; } else { $att['present']++; }
        if ((int)$r['is_late']) { $att['late']++; }
    }
    $absentList = $q("SELECT u.name, u.designation, sa.status, sa.is_late, COALESCE(NULLIF(d.name_local,''), d.name) AS dept FROM staff_attendance sa JOIN users u ON u.id = sa.user_id LEFT JOIN departments d ON d.id = u.department_id
        WHERE u.department_id IN ($ph) AND sa.attendance_date = CURDATE() AND (sa.status = 'absent' OR sa.is_late = 1) ORDER BY sa.status = 'absent' DESC, u.name LIMIT 10", $deptIds)->fetchAll();
} catch (PDOException $e) {}
$openReq = 0;
try { $openReq = $one("SELECT COUNT(*) FROM work_requests WHERE department_id IN ($ph) AND status IN ('pending','in_progress')", $deptIds); } catch (PDOException $e) {}
$overdueTasks = $q("SELECT t.title, t.target_date, t.status, u.name AS assignee, COALESCE(NULLIF(d.name_local,''), d.name) AS dept FROM tasks t LEFT JOIN users u ON u.id = t.assigned_to LEFT JOIN departments d ON d.id = t.department_id
    WHERE t.department_id IN ($ph) AND t.status <> 'completed' AND t.target_date < CURDATE() ORDER BY t.target_date LIMIT 8", $deptIds)->fetchAll();
$renewSoon = $q("SELECT r.item_name, r.expiry_date, COALESCE(NULLIF(d.name_local,''), d.name) AS dept FROM renewal_items r LEFT JOIN departments d ON d.id = r.department_id
    WHERE r.department_id IN ($ph) AND r.status <> 'inactive' AND r.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) ORDER BY r.expiry_date LIMIT 8", $deptIds)->fetchAll();

// विभागनिहाय
$per = [];
foreach ($depts as $d) { $per[(int)$d['id']] = ['name' => $d['name'], 'hod' => $d['hod_name'], 'staff' => 0, 'today' => 0, 'tasks' => 0, 'ren' => 0]; }
foreach ($q("SELECT department_id k, COUNT(*) c FROM users WHERE department_id IN ($ph) AND is_active = 1 GROUP BY department_id", $deptIds)->fetchAll() as $r) { if (isset($per[(int)$r['k']])) $per[(int)$r['k']]['staff'] = (int)$r['c']; }
foreach ($q("SELECT department_id k, COUNT(*) c FROM work_records WHERE department_id IN ($ph) AND work_date = CURDATE() GROUP BY department_id", $deptIds)->fetchAll() as $r) { if (isset($per[(int)$r['k']])) $per[(int)$r['k']]['today'] = (int)$r['c']; }
foreach ($q("SELECT department_id k, COUNT(*) c FROM tasks WHERE department_id IN ($ph) AND status <> 'completed' GROUP BY department_id", $deptIds)->fetchAll() as $r) { if (isset($per[(int)$r['k']])) $per[(int)$r['k']]['tasks'] = (int)$r['c']; }
foreach ($q("SELECT department_id k, COUNT(*) c FROM renewal_items WHERE department_id IN ($ph) AND status <> 'inactive' AND expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) GROUP BY department_id", $deptIds)->fetchAll() as $r) { if (isset($per[(int)$r['k']])) $per[(int)$r['k']]['ren'] = (int)$r['c']; }

$heads = [];
if (campusHeadsReady($pdo)) {
    $heads = $q("SELECT u.name, h.title, h.campus_id FROM campus_heads h JOIN users u ON u.id = h.user_id WHERE h.organization_id = ? AND (h.campus_id = ? OR h.campus_id IS NULL) ORDER BY h.campus_id IS NULL, u.name", [$orgId, $campusId])->fetchAll();
}
$attPct = $att['marked'] ? (int)round($att['present'] * 100 / $att['marked']) : null;

$pageTitle = 'Campus Dashboard — ' . $campus['name'];
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
$kpi = function ($label, $value, $sub = '', $tone = '') {
    $c = ['bad' => 'var(--rose)', 'warn' => 'var(--amber)', 'ok' => '#16A34A'][$tone] ?? 'inherit';
    return '<div class="col-6 col-md-4 col-xl-2"><div class="kpi-card h-100"><span class="kpi-label">' . $label . '</span><div class="kpi-value" style="color:' . $c . ';">' . $value . '</div>' . ($sub ? '<div style="font-size:11.5px;color:var(--text-dim);">' . $sub . '</div>' : '') . '</div></div>';
};
?>
<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-2 flex-wrap gap-2">
  <div>
    <div class="page-title mb-0">🏛️ <?= h($campus['name']) ?></div>
    <div style="font-size:12.5px;color:var(--text-dim);"><?= h(($campus['college_type'] ?: 'Campus') . ($campus['city'] ? ' · ' . $campus['city'] : '')) ?> · Campus Dashboard</div>
    <?php if ($heads): ?><div class="d-flex gap-2 flex-wrap mt-1">
      <?php foreach ($heads as $hd): ?><span style="font-size:12px;background:var(--canvas);border:1px solid var(--line);border-radius:99px;padding:3px 10px;"><?= $hd['campus_id'] === null ? '🌐' : '👤' ?> <b><?= h($hd['name']) ?></b><?= $hd['title'] ? ' — ' . h($hd['title']) : ($hd['campus_id'] === null ? ' — केंद्रीय प्रमुख' : ' — Campus प्रमुख') ?></span><?php endforeach; ?>
    </div><?php endif; ?>
  </div>
  <div class="d-flex gap-2 flex-wrap no-print">
    <?php if (count($list) > 1): ?>
      <form method="GET"><select name="id" class="form-select-custom" onchange="this.form.submit()"><?php foreach ($list as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$c['id'] === $campusId ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?></select></form>
    <?php endif; ?>
    <button type="button" onclick="window.print()" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:8px 14px;"><i class="fa-solid fa-print me-1"></i>Print</button>
    <?php if (hasRole('super_admin')): ?><a href="heads.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:8px 14px;"><i class="fa-solid fa-user-tie me-1"></i>Campus प्रमुख</a><?php endif; ?>
  </div>
</div>

<div class="row g-3 mb-3">
  <?= $kpi('विभाग', (int)$M['dept_count'], (int)$M['staff_count'] . ' कर्मचारी') ?>
  <?= $kpi('आजची कामं', $todayWork, $todayDone . ' पूर्ण', $todayWork ? 'ok' : 'warn') ?>
  <?= $kpi('आजची हजेरी', $attPct === null ? '—' : $attPct . '%', $att['marked'] ? $att['absent'] . ' गैरहजर · ' . $att['late'] . ' उशीर' : 'नोंद नाही', $attPct === null ? '' : ($attPct >= 90 ? 'ok' : ($attPct >= 75 ? 'warn' : 'bad'))) ?>
  <?= $kpi('प्रलंबित Tasks', (int)$M['pending_tasks'], count($overdueTasks) . ' मुदतबाह्य', count($overdueTasks) ? 'bad' : 'ok') ?>
  <?= $kpi('नूतनीकरण (३० दिवस)', (int)$M['renewals_due'], (int)$M['renewals_expired'] . ' मुदत संपली', $M['renewals_expired'] ? 'bad' : ($M['renewals_due'] ? 'warn' : 'ok')) ?>
  <?= $kpi('उघड्या विनंत्या', $openReq, (int)$M['pending_letters'] . ' पत्रं प्रलंबित', $openReq ? 'warn' : 'ok') ?>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-7"><div class="panel h-100"><div class="panel-head"><div class="title">📈 मागचे ७ दिवस — कामाच्या नोंदी</div></div><div style="padding:12px 16px;height:240px;"><canvas id="cdTrend"></canvas></div></div></div>
  <div class="col-lg-5"><div class="panel h-100"><div class="panel-head"><div class="title">🕘 आज गैरहजर / उशीर</div></div>
    <div style="padding:6px 16px 14px;">
      <?php if (!$absentList): ?><div style="color:var(--text-dim);font-size:13px;padding:12px 0;">✅ कोणी गैरहजर/उशीर नाही (किंवा हजेरी अजून नोंदली नाही)</div><?php endif; ?>
      <?php foreach ($absentList as $a): ?><div style="display:flex;justify-content:space-between;gap:8px;padding:6px 0;border-bottom:1px solid var(--line);font-size:13px;"><span><b><?= h($a['name']) ?></b> <span style="color:var(--text-dim);font-size:11.5px;"><?= h($a['dept']) ?></span></span><span style="font-weight:700;color:<?= $a['status'] === 'absent' ? 'var(--rose)' : 'var(--amber)' ?>;"><?= $a['status'] === 'absent' ? 'गैरहजर' : 'उशीर' ?></span></div><?php endforeach; ?>
    </div></div></div>
</div>

<div class="panel mb-3">
  <div class="panel-head"><div class="title">🗂️ विभागनिहाय (<?= count($depts) ?>)</div></div>
  <div class="table-responsive"><table class="rtable">
    <thead><tr><th>विभाग</th><th>HOD</th><th>कर्मचारी</th><th>आजची कामं</th><th>प्रलंबित Tasks</th><th>नूतनीकरण (३० दिवस)</th></tr></thead>
    <tbody>
      <?php if (!$depts): ?><tr><td colspan="6" style="text-align:center;color:var(--text-dim);padding:20px;">या Campus मध्ये अजून विभाग नाहीत.</td></tr><?php endif; ?>
      <?php foreach ($per as $p): ?><tr>
        <td style="font-weight:600;"><?= h($p['name']) ?></td><td><?= h($p['hod'] ?: '—') ?></td><td class="font-mono"><?= $p['staff'] ?></td>
        <td class="font-mono" style="color:<?= $p['today'] ? '#16A34A' : 'var(--text-dim)' ?>;font-weight:700;"><?= $p['today'] ?></td>
        <td class="font-mono" style="color:<?= $p['tasks'] ? 'var(--amber)' : 'inherit' ?>;"><?= $p['tasks'] ?></td>
        <td class="font-mono" style="color:<?= $p['ren'] ? 'var(--rose)' : 'inherit' ?>;"><?= $p['ren'] ?></td>
      </tr><?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<div class="row g-3 mb-3">
  <div class="col-lg-6"><div class="panel h-100"><div class="panel-head"><div class="title">⏰ मुदतबाह्य Tasks</div></div><div style="padding:6px 16px 14px;">
    <?php if (!$overdueTasks): ?><div style="color:var(--text-dim);font-size:13px;padding:12px 0;">✅ एकही Task मुदतबाह्य नाही</div><?php endif; ?>
    <?php foreach ($overdueTasks as $t): ?><div style="padding:7px 0;border-bottom:1px solid var(--line);font-size:13px;"><b><?= h($t['title']) ?></b><div style="font-size:11.5px;color:var(--text-dim);"><?= h(($t['assignee'] ?: '—') . ' · ' . $t['dept']) ?> · <span style="color:var(--rose);font-weight:600;">मुदत <?= h(date('d-m-Y', strtotime($t['target_date']))) ?></span></div></div><?php endforeach; ?>
  </div></div></div>
  <div class="col-lg-6"><div class="panel h-100"><div class="panel-head"><div class="title">🛡️ नूतनीकरण — ३० दिवसांत / संपलेले</div></div><div style="padding:6px 16px 14px;">
    <?php if (!$renewSoon): ?><div style="color:var(--text-dim);font-size:13px;padding:12px 0;">✅ ३० दिवसांत कोणतंही नूतनीकरण नाही</div><?php endif; ?>
    <?php foreach ($renewSoon as $r): $late = $r['expiry_date'] < date('Y-m-d'); ?><div style="padding:7px 0;border-bottom:1px solid var(--line);font-size:13px;display:flex;justify-content:space-between;gap:8px;"><span><b><?= h($r['item_name']) ?></b> <span style="font-size:11.5px;color:var(--text-dim);"><?= h($r['dept']) ?></span></span><span style="font-weight:700;white-space:nowrap;color:<?= $late ? 'var(--rose)' : 'var(--amber)' ?>;"><?= h(date('d-m-Y', strtotime($r['expiry_date']))) ?></span></div><?php endforeach; ?>
  </div></div></div>
</div>
<style>@media print{ .no-print,.rail-sidebar,.topbar,.mb-nav,footer,.sos-fab{display:none !important;} .content{margin:0 !important;padding:0 !important;} }</style>
<script>
window.addEventListener('load', function () {
  if (!window.Chart) return;
  new Chart(document.getElementById('cdTrend'), { type: 'bar',
    data: { labels: <?= json_encode($M['trend_labels'], JSON_UNESCAPED_UNICODE) ?>, datasets: [
      { label: 'एकूण', data: <?= json_encode($M['trend_total']) ?>, backgroundColor: 'rgba(217,83,15,.75)', borderRadius: 6 },
      { label: 'पूर्ण', data: <?= json_encode($M['trend_completed']) ?>, backgroundColor: 'rgba(22,163,74,.75)', borderRadius: 6 } ] },
    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } } });
});
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
