<?php
// 👤 कर्मचारी प्रोफाइल — Smart कामगिरी-झलक (staff/profile.php मधून; $emp, $empId, $isOwnProfile आहेत)
// मागील ९० दिवस विरुद्ध त्याआधीचे ९० दिवस — कामं, पूर्णत्व, Tasks वेळेत, हजेरी; एकत्रित गुण,
// १२ महिन्यांचा कल, सलग हजेरी, सेवा-वर्धापनदिन, रजा-शिल्लक.
// फक्त स्वतःला किंवा ज्यांना या कर्मचाऱ्याचा अहवाल पाहण्याची परवानगी आहे त्यांना (employee-report नियम).
require_once __DIR__ . '/../employee-report-access.php';
$pvVisible = employeeReportVisibleDeptIds();
$canSeePerf = $isOwnProfile || $pvVisible === null || in_array((int)$emp['department_id'], $pvVisible, true);
if (!$canSeePerf) return;

$pq = function ($sql, $params) use ($pdo) {
    try { $st = $pdo->prepare($sql); $st->execute($params); return $st->fetchAll(); } catch (PDOException $e) { return []; }
};
$orgP = activeOrgId();
$win = function ($fromDays, $toDays) use ($pq, $empId, $orgP) {
    $from = date('Y-m-d', strtotime("-$fromDays days")); $to = date('Y-m-d', strtotime("-$toDays days"));
    $w = $pq("SELECT COUNT(*) total, SUM(x.status = 'completed') done FROM (
            SELECT wr.id, wr.status FROM work_records wr WHERE wr.organization_id = ? AND wr.work_date BETWEEN ? AND ? AND wr.employee_id = ?
            UNION SELECT wr.id, wr.status FROM work_records wr JOIN work_record_team wt ON wt.work_record_id = wr.id
            WHERE wr.organization_id = ? AND wr.work_date BETWEEN ? AND ? AND wt.user_id = ?) x", [$orgP, $from, $to, $empId, $orgP, $from, $to, $empId])[0] ?? [];
    $t = $pq("SELECT SUM(status = 'completed') done, SUM(status = 'completed' AND DATE(completed_at) <= target_date) ontime FROM tasks
        WHERE organization_id = ? AND assigned_to = ? AND target_date BETWEEN ? AND ?", [$orgP, $empId, $from, $to])[0] ?? [];
    $a = $pq("SELECT SUM(status = 'present') p, SUM(status = 'half_day') hd, SUM(status = 'absent') ab, SUM(is_late = 1) late FROM staff_attendance
        WHERE user_id = ? AND attendance_date BETWEEN ? AND ?", [$empId, $from, $to])[0] ?? [];
    $wt = (int)($w['total'] ?? 0); $wd = (int)($w['done'] ?? 0);
    $td = (int)($t['done'] ?? 0); $to_ = (int)($t['ontime'] ?? 0);
    $base = (int)($a['p'] ?? 0) + (int)($a['hd'] ?? 0) + (int)($a['ab'] ?? 0);
    return [
        'works' => $wt,
        'wpct' => $wt ? round($wd * 100 / $wt) : null,
        'tpct' => $td ? round($to_ * 100 / $td) : null,
        'apct' => $base ? round(((int)$a['p'] + 0.5 * (int)$a['hd']) * 100 / $base) : null,
        'late' => (int)($a['late'] ?? 0),
    ];
};
$now = $win(89, 0);
$prev = $win(179, 90);
// एकत्रित गुण — उपलब्ध घटकांची सरासरी (काम-पूर्णत्व, Tasks वेळेत, हजेरी)
$parts = array_filter([$now['wpct'], $now['tpct'], $now['apct']], fn($v) => $v !== null);
$score = $parts ? (int)round(array_sum($parts) / count($parts)) : null;
[$scoreLbl, $scoreCol] = $score === null ? ['माहिती अपुरी', 'var(--text-dim)'] : ($score >= 85 ? ['उत्कृष्ट', 'var(--rail)'] : ($score >= 70 ? ['चांगली', 'var(--rail)'] : ($score >= 50 ? ['सुधारणा शक्य', 'var(--amber)'] : ['लक्ष आवश्यक', 'var(--rose)'])));
$trendArrow = function ($n, $p, $suffix = '%') {
    if ($n === null || $p === null) return '';
    $d = $n - $p;
    if ($d == 0) return '<span class="pv-tr flat">→ तसंच</span>';
    return '<span class="pv-tr ' . ($d > 0 ? 'up' : 'down') . '">' . ($d > 0 ? '▲' : '▼') . ' ' . abs($d) . $suffix . '</span>';
};

// १२ महिन्यांचा कल
$m12 = [];
for ($i = 11; $i >= 0; $i--) { $k = date('Y-m', strtotime(date('Y-m-01') . " -$i month")); $m12[$k] = ['l' => date('M y', strtotime($k . '-01')), 't' => 0, 'c' => 0]; }
foreach ($pq("SELECT DATE_FORMAT(x.work_date, '%Y-%m') ym, COUNT(*) total, SUM(x.status = 'completed') done FROM (
        SELECT wr.id, wr.status, wr.work_date FROM work_records wr WHERE wr.organization_id = ? AND wr.work_date >= ? AND wr.employee_id = ?
        UNION SELECT wr.id, wr.status, wr.work_date FROM work_records wr JOIN work_record_team wt ON wt.work_record_id = wr.id
        WHERE wr.organization_id = ? AND wr.work_date >= ? AND wt.user_id = ?) x GROUP BY ym", [$orgP, array_key_first($m12) . '-01', $empId, $orgP, array_key_first($m12) . '-01', $empId]) as $r) {
    if (isset($m12[$r['ym']])) { $m12[$r['ym']]['t'] = (int)$r['total']; $m12[$r['ym']]['c'] = (int)$r['done']; }
}

// सलग हजेरी (आजपासून मागे — हजर/अर्धा दिवस/सुट्टी मोजतो, गैरहजर आल्यावर थांबतो)
$streak = 0;
foreach ($pq("SELECT status FROM staff_attendance WHERE user_id = ? AND attendance_date <= CURDATE() ORDER BY attendance_date DESC LIMIT 120", [$empId]) as $r) {
    if ($r['status'] === 'absent') break;
    if (in_array($r['status'], ['present', 'half_day'], true)) $streak++;
}
// रजा-शिल्लक (असल्यास)
$balances = $pq("SELECT lt.name_local, (lb.allocated_days - lb.used_days) balance FROM leave_balances lb JOIN leave_types lt ON lt.id = lb.leave_type_id
    WHERE lb.user_id = ? AND lb.year = YEAR(CURDATE()) ORDER BY lt.name_local", [$empId]);
// सेवा
$joinD = $emp['joining_date'] ?: null;
$svcYears = $joinD ? (new DateTime($joinD))->diff(new DateTime())->y : null;
$anniv = null;
if ($joinD) { $next = new DateTime(date('Y') . '-' . date('m-d', strtotime($joinD))); if ($next < new DateTime('today')) $next->modify('+1 year'); $anniv = (int)(new DateTime('today'))->diff($next)->days; }
?>
<div class="panel mb-3 pv-wrap">
  <div class="panel-head d-flex justify-content-between align-items-center flex-wrap gap-2">
    <div><div class="title">📊 कामगिरी — मागील ९० दिवस</div><div class="sub">आधीच्या ९० दिवसांच्या तुलनेत</div></div>
    <a href="employee-report.php?id=<?= (int)$empId ?>" class="sm-link">संपूर्ण अहवाल →</a>
  </div>
  <div class="pv-body">
    <div class="pv-score">
      <div class="sm-score" style="--p:<?= $score ?? 0 ?>;--lc:<?= $scoreCol ?>;width:92px;height:92px;"><span style="width:74px;height:74px;font-size:22px;"><?= $score ?? '—' ?></span></div>
      <div class="pv-score-lbl" style="color:<?= $scoreCol ?>;"><?= $scoreLbl ?></div>
      <div class="pv-score-sub">काम · Tasks · हजेरी</div>
    </div>
    <div class="pv-metrics">
      <div><span>कामाच्या नोंदी</span><b><?= $now['works'] ?></b><?= $trendArrow($now['works'], $prev['works'], '') ?></div>
      <div><span>काम पूर्णत्व</span><b><?= $now['wpct'] === null ? '—' : $now['wpct'] . '%' ?></b><?= $trendArrow($now['wpct'], $prev['wpct']) ?></div>
      <div><span>Tasks वेळेत</span><b><?= $now['tpct'] === null ? '—' : $now['tpct'] . '%' ?></b><?= $trendArrow($now['tpct'], $prev['tpct']) ?></div>
      <div><span>हजेरी</span><b><?= $now['apct'] === null ? '—' : $now['apct'] . '%' ?></b><?= $trendArrow($now['apct'], $prev['apct']) ?></div>
    </div>
  </div>
  <div class="pv-badges">
    <?php if ($streak >= 5): ?><span class="pv-badge ok">🔥 सलग <?= $streak ?> दिवस हजर</span><?php endif; ?>
    <?php if ($now['late'] >= 5): ?><span class="pv-badge warn">⏰ ९० दिवसांत <?= $now['late'] ?> वेळा उशीर</span><?php endif; ?>
    <?php if ($svcYears !== null): ?><span class="pv-badge">🏅 सेवा <?= $svcYears ?> वर्षे</span><?php endif; ?>
    <?php if ($anniv !== null && $anniv <= 30): ?><span class="pv-badge ok">🎉 सेवा-वर्धापनदिन <?= $anniv === 0 ? 'आज!' : $anniv . ' दिवसांनी' ?></span><?php endif; ?>
    <?php if (!empty($pendingTasks)): ?><span class="pv-badge <?= $pendingTasks > 5 ? 'warn' : '' ?>">📋 <?= (int)$pendingTasks ?> उघडे Tasks</span><?php endif; ?>
    <?php foreach ($balances as $b): ?><span class="pv-badge">🏖️ <?= h($b['name_local']) ?>: <?= rtrim(rtrim(number_format((float)$b['balance'], 1), '0'), '.') ?> शिल्लक</span><?php endforeach; ?>
  </div>
  <div style="padding:6px 18px 14px;height:190px;position:relative;"><canvas id="pvTrendChart"></canvas></div>
</div>
<style>
  .pv-body { display: flex; gap: 22px; align-items: center; padding: 16px 20px 8px; flex-wrap: wrap; }
  .pv-score { text-align: center; min-width: 110px; }
  .pv-score-lbl { font-weight: 700; font-size: 14px; margin-top: 6px; }
  .pv-score-sub { font-size: 10.5px; color: var(--text-dim); }
  .pv-metrics { flex: 1; display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 10px; min-width: 260px; }
  .pv-metrics > div { background: var(--canvas); border-radius: 10px; padding: 10px 12px; }
  .pv-metrics span { display: block; font-size: 11px; color: var(--text-dim); font-weight: 600; }
  .pv-metrics b { display: block; font-family: 'JetBrains Mono', monospace; font-size: 20px; }
  .pv-tr { font-size: 11px; font-weight: 700; } .pv-tr.up { color: var(--rail); } .pv-tr.down { color: var(--rose); } .pv-tr.flat { color: var(--text-dim); }
  .pv-badges { display: flex; flex-wrap: wrap; gap: 6px; padding: 4px 20px 6px; }
  .pv-badge { font-size: 11.5px; font-weight: 600; background: var(--canvas); border: 1px solid var(--line); border-radius: 20px; padding: 3px 11px; }
  .pv-badge.ok { background: var(--rail-soft); color: var(--rail); border-color: transparent; }
  .pv-badge.warn { background: var(--amber-soft); color: var(--amber); border-color: transparent; }
  @media (max-width: 767px) { .pv-metrics { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
</style>
<script>
window.addEventListener('DOMContentLoaded', function () {
  if (typeof Chart === 'undefined') return;
  var m = <?= json_encode(array_values($m12), JSON_UNESCAPED_UNICODE) ?>, dim = getComputedStyle(document.documentElement).getPropertyValue('--text-dim').trim() || '#6B7080';
  new Chart(document.getElementById('pvTrendChart'), { type: 'bar',
    data: { labels: m.map(function (x) { return x.l; }), datasets: [
      { label: 'एकूण कामं', data: m.map(function (x) { return x.t; }), backgroundColor: 'rgba(107,112,128,.35)', borderRadius: 4, maxBarThickness: 22 },
      { label: 'पूर्ण', data: m.map(function (x) { return x.c; }), backgroundColor: '#1B8A83', borderRadius: 4, maxBarThickness: 22 } ] },
    options: { responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
      plugins: { legend: { position: 'bottom', labels: { color: dim, boxWidth: 10, font: { size: 11 } } } },
      scales: { x: { grid: { display: false }, ticks: { color: dim, font: { size: 10 } } }, y: { beginAtZero: true, ticks: { color: dim, precision: 0 } } } } });
});
</script>
