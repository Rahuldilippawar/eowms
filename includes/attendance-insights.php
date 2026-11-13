<?php
// 👆 Staff Attendance — Smart Insights (staff/attendance.php मधून include)
// आजचं चित्र, निवडलेल्या महिन्याचा हजेरी-दर, रोजचा कल, वारंवार उशीर/गैरहजर
// आणि (Management ला) विभागनिहाय हजेरी. Scope: $isMgmt नसेल तर फक्त स्वतःचा विभाग.

$aiDeptSql = $isMgmt ? '' : ' AND u.department_id = ?';
$aiDeptParams = $isMgmt ? [] : [$user['department_id'] ?? 0];
$ai = null;
try {
    // आज
    $st = $pdo->prepare("SELECT COUNT(*) FROM users u WHERE u.organization_id = ? AND u.is_active = 1$aiDeptSql");
    $st->execute(array_merge([$orgId], $aiDeptParams));
    $aiStaff = (int)$st->fetchColumn();
    $st = $pdo->prepare("SELECT
            SUM(sa.status = 'present') present, SUM(sa.status = 'absent') absent, SUM(sa.status = 'half_day') half,
            SUM(sa.status = 'on_leave') on_leave, SUM(sa.status = 'holiday') holiday, SUM(sa.is_late = 1) late, COUNT(*) marked
        FROM staff_attendance sa JOIN users u ON u.id = sa.user_id
        WHERE sa.organization_id = ? AND sa.attendance_date = CURDATE()$aiDeptSql");
    $st->execute(array_merge([$orgId], $aiDeptParams));
    $aiToday = array_map('intval', $st->fetch() ?: []);

    // निवडलेला महिना — रोजचा कल
    $st = $pdo->prepare("SELECT sa.attendance_date d,
            SUM(sa.status IN ('present','half_day')) present, SUM(sa.status = 'absent') absent,
            SUM(sa.status = 'on_leave') on_leave, SUM(sa.is_late = 1) late
        FROM staff_attendance sa JOIN users u ON u.id = sa.user_id
        WHERE sa.organization_id = ? AND sa.attendance_date BETWEEN ? AND LAST_DAY(?)$aiDeptSql
        GROUP BY sa.attendance_date ORDER BY sa.attendance_date");
    $st->execute(array_merge([$orgId, $month . '-01', $month . '-01'], $aiDeptParams));
    $aiDaily = $st->fetchAll();

    $st = $pdo->prepare("SELECT
            SUM(sa.status = 'present') present, SUM(sa.status = 'half_day') half, SUM(sa.status = 'absent') absent,
            SUM(sa.status = 'on_leave') on_leave, SUM(sa.is_late = 1) late, SUM(sa.is_early_leave = 1) early,
            SEC_TO_TIME(ROUND(AVG(CASE WHEN sa.first_punch_time IS NOT NULL THEN TIME_TO_SEC(sa.first_punch_time) END))) avg_in
        FROM staff_attendance sa JOIN users u ON u.id = sa.user_id
        WHERE sa.organization_id = ? AND sa.attendance_date BETWEEN ? AND LAST_DAY(?)$aiDeptSql");
    $st->execute(array_merge([$orgId, $month . '-01', $month . '-01'], $aiDeptParams));
    $aiMonth = $st->fetch() ?: [];
    $workDays = (int)$aiMonth['present'] + (int)$aiMonth['half'] + (int)$aiMonth['absent'];
    $aiRate = $workDays > 0 ? (int)round(((int)$aiMonth['present'] + 0.5 * (int)$aiMonth['half']) * 100 / $workDays) : null;

    // वारंवार उशीर / गैरहजर (महिना)
    $topSql = "SELECT u.name, d.name_local AS dept, SUM(sa.is_late = 1) late, SUM(sa.status = 'absent') absent
        FROM staff_attendance sa JOIN users u ON u.id = sa.user_id LEFT JOIN departments d ON d.id = u.department_id
        WHERE sa.organization_id = ? AND sa.attendance_date BETWEEN ? AND LAST_DAY(?)$aiDeptSql
        GROUP BY u.id, u.name, d.name_local";
    $st = $pdo->prepare("$topSql HAVING late > 0 ORDER BY late DESC LIMIT 5");
    $st->execute(array_merge([$orgId, $month . '-01', $month . '-01'], $aiDeptParams));
    $aiLate = $st->fetchAll();
    $st = $pdo->prepare("$topSql HAVING absent > 0 ORDER BY absent DESC LIMIT 5");
    $st->execute(array_merge([$orgId, $month . '-01', $month . '-01'], $aiDeptParams));
    $aiAbsent = $st->fetchAll();

    // विभागनिहाय हजेरी % (फक्त Management)
    $aiDepts = [];
    if ($isMgmt) {
        $st = $pdo->prepare("SELECT d.name_local AS name,
                SUM(sa.status = 'present') + 0.5 * SUM(sa.status = 'half_day') pres,
                SUM(sa.status IN ('present','half_day','absent')) total
            FROM staff_attendance sa JOIN users u ON u.id = sa.user_id JOIN departments d ON d.id = u.department_id
            WHERE sa.organization_id = ? AND sa.attendance_date BETWEEN ? AND LAST_DAY(?)
            GROUP BY d.id, d.name_local HAVING total > 0");
        $st->execute([$orgId, $month . '-01', $month . '-01']);
        foreach ($st->fetchAll() as $r) { $aiDepts[] = ['name' => $r['name'], 'pct' => (int)round($r['pres'] * 100 / $r['total'])]; }
        usort($aiDepts, fn($a, $b) => $a['pct'] <=> $b['pct']);
    }
    $ai = true;
} catch (PDOException $e) { $ai = null; }

if ($ai):
    $notMarked = max(0, $aiStaff - ($aiToday['marked'] ?? 0));
    $monthLabel = date('M Y', strtotime($month . '-01'));
?>
<div class="ai-wrap mb-4">
  <div class="ai-today">
    <div class="ai-today-head"><i class="fa-solid fa-sun"></i> आज · <?= date('d M') ?> <span><?= $aiStaff ?> कर्मचारी</span></div>
    <div class="ai-chips">
      <div class="ok"><b><?= $aiToday['present'] ?? 0 ?></b><span>हजर</span></div>
      <div class="warn"><b><?= $aiToday['late'] ?? 0 ?></b><span>उशीर</span></div>
      <div class="bad"><b><?= $aiToday['absent'] ?? 0 ?></b><span>गैरहजर</span></div>
      <div><b><?= ($aiToday['on_leave'] ?? 0) + ($aiToday['half'] ?? 0) ?></b><span>रजा / अर्धा दिवस</span></div>
      <div class="dim"><b><?= $notMarked ?></b><span>नोंद नाही</span></div>
    </div>
    <?php if ($aiStaff > 0): $seg = fn($n) => round($n * 100 / max(1, $aiStaff), 1); ?>
      <div class="ai-bar" title="आजचं वितरण">
        <i style="width:<?= $seg(($aiToday['present'] ?? 0) - ($aiToday['late'] ?? 0)) ?>%;background:var(--rail);"></i>
        <i style="width:<?= $seg($aiToday['late'] ?? 0) ?>%;background:var(--amber);"></i>
        <i style="width:<?= $seg(($aiToday['on_leave'] ?? 0) + ($aiToday['half'] ?? 0)) ?>%;background:#3B6FC9;"></i>
        <i style="width:<?= $seg($aiToday['absent'] ?? 0) ?>%;background:var(--rose);"></i>
      </div>
    <?php endif; ?>
  </div>

  <div class="row g-3 mt-0">
    <div class="col-lg-8">
      <div class="panel h-100">
        <div class="panel-head d-flex justify-content-between align-items-center flex-wrap gap-2">
          <div><div class="title">📈 <?= h($monthLabel) ?> — रोजची हजेरी</div><div class="sub">हजर · उशीर · रजा · गैरहजर</div></div>
          <div class="ai-month-kpis">
            <span>हजेरी दर <b class="<?= $aiRate === null ? '' : ($aiRate >= 90 ? 'c-ok' : ($aiRate >= 75 ? 'c-amber' : 'c-rose')) ?>"><?= $aiRate === null ? '—' : $aiRate . '%' ?></b></span>
            <span>उशीर <b class="c-amber"><?= (int)($aiMonth['late'] ?? 0) ?></b></span>
            <span>लवकर गेले <b><?= (int)($aiMonth['early'] ?? 0) ?></b></span>
            <?php if (!empty($aiMonth['avg_in'])): ?><span>सरासरी आगमन <b class="font-mono"><?= h(substr($aiMonth['avg_in'], 0, 5)) ?></b></span><?php endif; ?>
          </div>
        </div>
        <div style="padding:14px 18px;height:250px;position:relative;">
          <?php if (!$aiDaily): ?><div class="sm-empty">या महिन्यात नोंदी नाहीत</div><?php else: ?><canvas id="aiDailyChart"></canvas><?php endif; ?>
        </div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="panel h-100">
        <div class="panel-head"><div class="title">⚠️ लक्ष द्या — <?= h($monthLabel) ?></div><div class="sub">वारंवार उशीर / गैरहजर</div></div>
        <div class="ai-lists">
          <div class="ai-list-title">⏰ सर्वाधिक उशीर</div>
          <?php if (!$aiLate): ?><div class="ai-none">कोणीही उशीर नाही 👍</div><?php endif; ?>
          <?php foreach ($aiLate as $r): ?><div class="ai-person"><span><?= h($r['name']) ?><small><?= h($r['dept'] ?? '') ?></small></span><b class="c-amber"><?= (int)$r['late'] ?> दिवस</b></div><?php endforeach; ?>
          <div class="ai-list-title mt-2">❌ सर्वाधिक गैरहजर</div>
          <?php if (!$aiAbsent): ?><div class="ai-none">कोणीही गैरहजर नाही 👍</div><?php endif; ?>
          <?php foreach ($aiAbsent as $r): ?><div class="ai-person"><span><?= h($r['name']) ?><small><?= h($r['dept'] ?? '') ?></small></span><b class="c-rose"><?= (int)$r['absent'] ?> दिवस</b></div><?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php if ($aiDepts): ?>
    <div class="col-12">
      <div class="panel">
        <div class="panel-head"><div class="title">🏢 विभागनिहाय हजेरी दर — <?= h($monthLabel) ?></div><div class="sub">कमी हजेरी असलेले विभाग आधी</div></div>
        <div class="ai-dept-grid">
          <?php foreach ($aiDepts as $dpt): $c = $dpt['pct'] >= 90 ? 'var(--rail)' : ($dpt['pct'] >= 75 ? 'var(--amber)' : 'var(--rose)'); ?>
            <div class="ai-dept"><div class="ai-dept-name" title="<?= h($dpt['name']) ?>"><?= h($dpt['name']) ?></div>
              <div class="ai-dept-bar"><i style="width:<?= $dpt['pct'] ?>%;background:<?= $c ?>;"></i></div><b style="color:<?= $c ?>;"><?= $dpt['pct'] ?>%</b></div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>
</div>
<?php if ($aiDaily): ?>
<script>
window.addEventListener('DOMContentLoaded', function () {
  if (typeof Chart === 'undefined') return;
  var d = <?= json_encode(array_map(fn($r) => ['d' => date('d', strtotime($r['d'])), 'p' => (int)$r['present'] - (int)$r['late'], 'l' => (int)$r['late'], 'v' => (int)$r['on_leave'], 'a' => (int)$r['absent']], $aiDaily)) ?>;
  var dim = getComputedStyle(document.documentElement).getPropertyValue('--text-dim').trim() || '#6B7080';
  var line = getComputedStyle(document.documentElement).getPropertyValue('--line').trim() || '#E4E6EC';
  new Chart(document.getElementById('aiDailyChart'), {
    type: 'bar',
    data: { labels: d.map(function (x) { return x.d; }), datasets: [
      { label: 'वेळेत हजर', data: d.map(function (x) { return x.p; }), backgroundColor: '#1B8A83', borderRadius: 3 },
      { label: 'उशीर', data: d.map(function (x) { return x.l; }), backgroundColor: '#C97F1D', borderRadius: 3 },
      { label: 'रजा', data: d.map(function (x) { return x.v; }), backgroundColor: '#3B6FC9', borderRadius: 3 },
      { label: 'गैरहजर', data: d.map(function (x) { return x.a; }), backgroundColor: '#B23A48', borderRadius: 3 }
    ] },
    options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
      plugins: { legend: { position: 'bottom', labels: { color: dim, boxWidth: 10, font: { size: 11 } } } },
      scales: { x: { stacked: true, grid: { display: false }, ticks: { color: dim, font: { size: 10 } } },
                y: { stacked: true, beginAtZero: true, ticks: { color: dim, precision: 0 }, grid: { color: line } } } }
  });
});
</script>
<?php endif; ?>
<?php endif; ?>
