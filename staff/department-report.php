<?php
// 👥 विभाग सारांश — विभागातील सर्व कर्मचाऱ्यांची निवडलेल्या कालावधीतील कामगिरी एकाच
// Table मध्ये (कामं, Tasks, हजेरी, रजा) — प्रत्येकाचा संपूर्ण अहवाल एका Click वर.
// Access: includes/employee-report-access.php (HOD = स्वतःचा विभाग, Management = सगळे).
$pageTitle = 'विभाग — कर्मचारी कामगिरी सारांश';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/employee-report-access.php';

$orgId = activeOrgId();
$visibleDeptIds = employeeReportVisibleDeptIds();
if ($visibleDeptIds !== null && !$visibleDeptIds) {
    http_response_code(403);
    echo '<div class="alert alert-danger">विभाग सारांश फक्त विभाग प्रमुख व व्यवस्थापनासाठी आहे. <a href="employee-report.php">तुमचा स्वतःचा अहवाल पहा →</a></div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}

$deptSql = "SELECT id, name_local, name FROM departments WHERE organization_id = ? AND is_active = 1";
$deptParams = [$orgId];
if ($visibleDeptIds !== null) {
    $deptSql .= " AND id IN (" . implode(',', array_fill(0, count($visibleDeptIds), '?')) . ")";
    $deptParams = array_merge($deptParams, $visibleDeptIds);
}
$st = $pdo->prepare($deptSql . " ORDER BY name_local");
$st->execute($deptParams);
$depts = $st->fetchAll();
$deptIds = array_map(fn($d) => (int)$d['id'], $depts);
$deptId = (int)($_GET['department_id'] ?? 0);
if (!in_array($deptId, $deptIds, true)) { $deptId = $deptIds[0] ?? 0; }

$validDate = fn($d) => ($x = DateTime::createFromFormat('!Y-m-d', (string)$d)) && $x->format('Y-m-d') === $d;
$range = $_GET['range'] ?? 'month';
$today = date('Y-m-d');
switch ($range) {
    case '3m': $from = date('Y-m-d', strtotime('-3 months +1 day')); $to = $today; break;
    case 'year': $from = date('Y-01-01'); $to = $today; break;
    case 'fy': $fyStart = (int)date('n') >= 4 ? date('Y') : date('Y') - 1; $from = $fyStart . '-04-01'; $to = $today; break;
    case 'custom':
        $from = $validDate($_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
        $to = $validDate($_GET['to'] ?? '') ? $_GET['to'] : $today;
        break;
    default: $range = 'month'; $from = date('Y-m-01'); $to = $today;
}
if ($from > $to) { [$from, $to] = [$to, $from]; }

$st = $pdo->prepare("SELECT id, name, designation, joining_date, is_active FROM users WHERE organization_id = ? AND department_id = ? ORDER BY is_active DESC, name");
$st->execute([$orgId, $deptId]);
$staff = $st->fetchAll();
$ids = array_map(fn($s) => (int)$s['id'], $staff);
$rows = [];
if ($ids) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $grab = function ($sql, $params) use ($pdo) {
        $out = [];
        try { $st = $pdo->prepare($sql); $st->execute($params); foreach ($st->fetchAll() as $r) { $out[(int)$r['uid']] = $r; } } catch (PDOException $e) {}
        return $out;
    };
    // कामं — स्वतः किंवा Team मध्ये (एकाच नोंदीसाठी एकदाच मोजतो)
    $work = $grab("SELECT x.uid, COUNT(*) total, SUM(x.status = 'completed') done FROM (
            SELECT wr.id, wr.status, wr.employee_id uid FROM work_records wr WHERE wr.organization_id = ? AND wr.work_date BETWEEN ? AND ? AND wr.employee_id IN ($ph)
            UNION
            SELECT wr.id, wr.status, wt.user_id uid FROM work_records wr JOIN work_record_team wt ON wt.work_record_id = wr.id WHERE wr.organization_id = ? AND wr.work_date BETWEEN ? AND ? AND wt.user_id IN ($ph)
        ) x GROUP BY x.uid", array_merge([$orgId, $from, $to], $ids, [$orgId, $from, $to], $ids));
    $tasks = $grab("SELECT assigned_to uid, COUNT(*) total, SUM(status = 'completed') done,
            SUM(status = 'completed' AND DATE(completed_at) <= target_date) ontime,
            SUM(status IN ('pending','in_progress') AND target_date < CURDATE()) overdue
        FROM tasks WHERE organization_id = ? AND DATE(created_at) BETWEEN ? AND ? AND assigned_to IN ($ph) GROUP BY assigned_to", array_merge([$orgId, $from, $to], $ids));
    $att = $grab("SELECT user_id uid, SUM(status = 'present') p, SUM(status = 'half_day') hd, SUM(status = 'absent') a, SUM(is_late = 1) late
        FROM staff_attendance WHERE attendance_date BETWEEN ? AND ? AND user_id IN ($ph) GROUP BY user_id", array_merge([$from, $to], $ids));
    $leave = $grab("SELECT user_id uid, SUM(total_days) d FROM leave_applications WHERE status = 'approved' AND start_date <= ? AND end_date >= ? AND user_id IN ($ph) GROUP BY user_id", array_merge([$to, $from], $ids));
    foreach ($staff as $s) {
        $id = (int)$s['id'];
        $w = $work[$id] ?? []; $t = $tasks[$id] ?? []; $a = $att[$id] ?? [];
        $base = (int)($a['p'] ?? 0) + (int)($a['hd'] ?? 0) + (int)($a['a'] ?? 0);
        $rows[] = [
            's' => $s,
            'w' => (int)($w['total'] ?? 0), 'wd' => (int)($w['done'] ?? 0),
            't' => (int)($t['total'] ?? 0), 'td' => (int)($t['done'] ?? 0), 'ton' => (int)($t['ontime'] ?? 0), 'tod' => (int)($t['overdue'] ?? 0),
            'att' => $base ? (int)round(((int)$a['p'] + 0.5 * (int)$a['hd']) * 100 / $base) : null,
            'abs' => (int)($a['a'] ?? 0), 'late' => (int)($a['late'] ?? 0),
            'leave' => (float)($leave[$id]['d'] ?? 0),
        ];
    }
}
$deptName = '';
foreach ($depts as $d) { if ((int)$d['id'] === $deptId) $deptName = $d['name_local'] ?: $d['name']; }
$tot = ['w' => array_sum(array_column($rows, 'w')), 'wd' => array_sum(array_column($rows, 'wd')), 't' => array_sum(array_column($rows, 't')), 'td' => array_sum(array_column($rows, 'td')), 'tod' => array_sum(array_column($rows, 'tod'))];
$qs = fn($r) => '?department_id=' . $deptId . '&range=' . $r;
$pct = fn($a, $b) => $b ? (int)round($a * 100 / $b) : null;
?>

<div class="d-flex justify-content-between align-items-start mb-2 flex-wrap gap-2">
  <div class="page-title mb-0">👥 विभाग — कर्मचारी कामगिरी सारांश</div>
  <div class="d-flex gap-2 no-print">
    <a href="employee-report.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 16px;"><i class="fa-solid fa-file-lines me-1"></i>वैयक्तिक अहवाल</a>
    <button type="button" class="btn" onclick="window.print()" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 18px;font-weight:600;"><i class="fa-solid fa-print"></i> Print / PDF</button>
  </div>
</div>

<div class="panel mb-3 no-print">
  <form method="GET" class="er-filter">
    <label>विभाग
      <select name="department_id" class="form-select-custom" onchange="this.form.submit()">
        <?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>" <?= (int)$d['id'] === $deptId ? 'selected' : '' ?>><?= h($d['name_local'] ?: $d['name']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <div class="er-ranges">
      <?php foreach (['month' => 'हा महिना', '3m' => '३ महिने', 'fy' => 'आर्थिक वर्ष', 'year' => 'हे वर्ष'] as $k => $lbl): ?>
        <a href="<?= h($qs($k)) ?>" class="period-btn <?= $range === $k ? 'active' : '' ?>"><?= $lbl ?></a>
      <?php endforeach; ?>
    </div>
    <input type="hidden" name="range" value="custom">
    <label>पासून <input type="date" name="from" class="form-control-custom" value="<?= h($from) ?>"></label>
    <label>पर्यंत <input type="date" name="to" class="form-control-custom" value="<?= h($to) ?>"></label>
    <button type="submit" class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;padding:8px 14px;">दाखवा</button>
  </form>
</div>

<div class="panel mb-3" style="padding:14px 20px;">
  <div style="font-size:17px;font-weight:700;"><?= h($deptName) ?></div>
  <div style="font-size:12.5px;color:var(--text-dim);"><?= date('d M Y', strtotime($from)) ?> — <?= date('d M Y', strtotime($to)) ?> · <?= count($staff) ?> कर्मचारी</div>
</div>

<?php require_once __DIR__ . '/../includes/smart-insights.php';
smartTiles([
    ['label' => 'एकूण कामं', 'value' => $tot['w'], 'sub' => $tot['wd'] . ' पूर्ण (' . ($pct($tot['wd'], $tot['w']) ?? 0) . '%)'],
    ['label' => 'Tasks', 'value' => $tot['t'], 'sub' => $tot['td'] . ' पूर्ण', 'tone' => ''],
    ['label' => 'मुदत संपलेले Tasks', 'value' => $tot['tod'], 'tone' => $tot['tod'] ? 'bad' : 'ok'],
    ['label' => 'सरासरी हजेरी', 'value' => ($v = array_filter(array_column($rows, 'att'), fn($x) => $x !== null)) ? round(array_sum($v) / count($v)) . '%' : '—'],
]); ?>

<div class="panel">
  <div class="table-responsive">
  <table class="rtable">
    <thead><tr><th>कर्मचारी</th><th>कामं</th><th>पूर्ण %</th><th>Tasks</th><th>वेळेत %</th><th>मुदतबाह्य</th><th>हजेरी %</th><th>गैरहजर</th><th>उशीर</th><th>रजा</th><th data-no-sort></th></tr></thead>
    <tbody>
      <?php if (!$rows): ?><tr><td colspan="11" class="sm-empty">या विभागात कर्मचारी नाहीत</td></tr><?php endif; ?>
      <?php foreach ($rows as $r): $s = $r['s']; $wp = $pct($r['wd'], $r['w']); $tp = $pct($r['ton'], $r['td']); ?>
        <tr style="<?= $s['is_active'] ? '' : 'opacity:.6;' ?>">
          <td><b><?= h($s['name']) ?></b><div style="font-size:11px;color:var(--text-dim);"><?= h($s['designation'] ?: '') ?><?= $s['is_active'] ? '' : ' · निष्क्रिय' ?></div></td>
          <td class="font-mono"><?= $r['w'] ?></td>
          <td class="font-mono" data-sort="<?= $wp ?? -1 ?>" style="color:<?= $wp === null ? 'var(--text-dim)' : ($wp >= 80 ? 'var(--rail)' : ($wp >= 50 ? 'var(--amber)' : 'var(--rose)')) ?>;"><?= $wp === null ? '—' : $wp . '%' ?></td>
          <td class="font-mono"><?= $r['t'] ?></td>
          <td class="font-mono" data-sort="<?= $tp ?? -1 ?>"><?= $tp === null ? '—' : $tp . '%' ?></td>
          <td class="font-mono" style="<?= $r['tod'] ? 'color:var(--rose);font-weight:700;' : '' ?>"><?= $r['tod'] ?></td>
          <td class="font-mono" data-sort="<?= $r['att'] ?? -1 ?>" style="color:<?= $r['att'] === null ? 'var(--text-dim)' : ($r['att'] >= 90 ? 'var(--rail)' : ($r['att'] >= 75 ? 'var(--amber)' : 'var(--rose)')) ?>;"><?= $r['att'] === null ? '—' : $r['att'] . '%' ?></td>
          <td class="font-mono"><?= $r['abs'] ?></td>
          <td class="font-mono"><?= $r['late'] ?></td>
          <td class="font-mono"><?= rtrim(rtrim(number_format($r['leave'], 1), '0'), '.') ?></td>
          <td class="no-print"><a href="employee-report.php?id=<?= (int)$s['id'] ?>&range=custom&from=<?= h($from) ?>&to=<?= h($to) ?>" style="font-size:12px;white-space:nowrap;">संपूर्ण अहवाल →</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</div>

<style>
  .er-filter { display: flex; flex-wrap: wrap; gap: 12px; align-items: end; padding: 14px 18px; }
  .er-filter label { display: flex; flex-direction: column; gap: 4px; font-size: 11.5px; color: var(--text-dim); font-weight: 600; }
  .er-ranges { display: flex; flex-wrap: wrap; gap: 4px; background: var(--canvas); border-radius: 9px; padding: 4px; }
  @media print { .no-print, .tbl-filter { display: none !important; } .table-responsive { overflow: visible !important; } .rtable { font-size: 11px; } }
</style>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
