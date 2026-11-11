<?php
// 📋 Biometric हजेरी अहवाल — दिवसानुसार प्रत्येकाचे Punches, आलो/गेलो, प्रत्यक्ष काम, उशीर, लवकर गेले, Overtime; Excel
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/_common.php';
bioRequireViewer();
$orgId = activeOrgId();
$ready = bioReady($pdo);

$isDate = fn($d) => is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d);
$from = $isDate($_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-d');
$to = $isDate($_GET['to'] ?? '') ? $_GET['to'] : $from;
if ($to < $from) { [$from, $to] = [$to, $from]; }
if ((strtotime($to) - strtotime($from)) / 86400 > 62) { $to = date('Y-m-d', strtotime("$from +62 days")); }
$dept = (int)($_GET['dept'] ?? 0);
$f = in_array($_GET['f'] ?? '', ['late', 'early', 'ot', 'missed', 'half', 'absent'], true) ? $_GET['f'] : '';
$q = trim(mb_substr($_GET['q'] ?? '', 0, 60));

$p = [(int)$orgId, $from, $to]; $scope = bioScopeSql($p);
$sql = "SELECT a.*, u.name, u.employee_code, u.biometric_pin, COALESCE(NULLIF(d.name_local,''), d.name) dept
    FROM staff_attendance a JOIN users u ON u.id = a.user_id LEFT JOIN departments d ON d.id = u.department_id
    WHERE a.organization_id = ? AND a.attendance_date BETWEEN ? AND ?$scope";
if ($dept) { $sql .= " AND u.department_id = ?"; $p[] = $dept; }
if ($q !== '') { $sql .= " AND (u.name LIKE ? OR u.employee_code LIKE ?)"; $p[] = "%$q%"; $p[] = "%$q%"; }
$sql .= " ORDER BY a.attendance_date DESC, u.name";
$st = $pdo->prepare($sql);
$st->execute($p);
$rows = $st->fetchAll();
$counts = ['late' => 0, 'early' => 0, 'ot' => 0, 'missed' => 0, 'half' => 0, 'absent' => 0];
foreach ($rows as $r) {
    if ((int)($r['late_minutes'] ?? 0) > 0 || $r['is_late']) { $counts['late']++; }
    if ((int)($r['early_minutes'] ?? 0) > 0 || $r['is_early_leave']) { $counts['early']++; }
    if ((int)($r['overtime_minutes'] ?? 0) > 0) { $counts['ot']++; }
    if (!empty($r['missed_punch'])) { $counts['missed']++; }
    if ($r['status'] === 'half_day') { $counts['half']++; }
    if ($r['status'] === 'absent') { $counts['absent']++; }
}
$match = function ($r) use ($f) {
    return match ($f) {
        'late' => (int)($r['late_minutes'] ?? 0) > 0 || $r['is_late'], 'early' => (int)($r['early_minutes'] ?? 0) > 0 || $r['is_early_leave'],
        'ot' => (int)($r['overtime_minutes'] ?? 0) > 0, 'missed' => !empty($r['missed_punch']), 'half' => $r['status'] === 'half_day',
        'absent' => $r['status'] === 'absent', default => true,
    };
};
$rows = array_values(array_filter($rows, $match));

// सगळे Raw Punches एकाच Query मध्ये
$raw = [];
if ($ready && $rows) {
    $uids = array_values(array_unique(array_map(fn($r) => (int)$r['user_id'], $rows)));
    $ph = implode(',', array_fill(0, count($uids), '?'));
    $rp = $pdo->prepare("SELECT p.user_id, p.punch_time, p.punch_state, p.verify_mode, d.device_name FROM bio_punches p JOIN bio_devices d ON d.id = p.device_id AND d.organization_id = ?
        WHERE p.user_id IN ($ph) AND p.punch_time >= ? AND p.punch_time < ? ORDER BY p.punch_time");
    $rp->execute(array_merge([(int)$orgId], $uids, [date('Y-m-d', strtotime("$from -1 day")), date('Y-m-d', strtotime("$to +2 day"))]));
    foreach ($rp->fetchAll() as $x) {
        $wd = bioWorkDate(bioShift($pdo, (int)$x['user_id']), strtotime($x['punch_time']));
        $raw[$x['user_id'] . '|' . $wd][] = $x;
    }
}
$stLabel = ['present' => ['हजर', '#16A34A'], 'half_day' => ['अर्धा दिवस', '#B45309'], 'absent' => ['गैरहजर', '#DC2626'], 'on_leave' => ['रजा', '#2563EB'], 'holiday' => ['सुट्टी', '#7C3AED']];

if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="biometric-hajeri-' . $from . '_' . $to . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
    $cell = fn($v) => preg_match('/^[=+\-@]/', (string)$v) ? "'" . $v : (string)$v;
    fputcsv($o, ['दिनांक', 'कर्मचारी', 'क्रमांक', 'PIN', 'विभाग', 'आलो', 'गेलो', 'Punches', 'प्रत्यक्ष काम (मि.)', 'उशीर (मि.)', 'लवकर गेले (मि.)', 'Overtime (मि.)', 'स्थिती', 'एकच Punch', 'सर्व Punches', 'शेरा']);
    foreach ($rows as $r) {
        $all = implode(' ', array_map(fn($x) => date('H:i', strtotime($x['punch_time'])), $raw[$r['user_id'] . '|' . $r['attendance_date']] ?? []));
        fputcsv($o, [$r['attendance_date'], $cell($r['name']), $cell($r['employee_code']), $r['biometric_pin'], $cell($r['dept']), $r['first_punch_time'] ? substr($r['first_punch_time'], 0, 5) : '',
            $r['last_punch_time'] ? substr($r['last_punch_time'], 0, 5) : '', (int)$r['total_punches'], (int)($r['worked_minutes'] ?? 0), (int)($r['late_minutes'] ?? 0), (int)($r['early_minutes'] ?? 0),
            (int)($r['overtime_minutes'] ?? 0), $stLabel[$r['status']][0] ?? $r['status'], !empty($r['missed_punch']) ? 'हो' : '', $all, $cell($r['remarks'])]);
    }
    exit;
}

$dp = [(int)$orgId]; $dsc = '';
$vis = getVisibleDepartmentIdsForStaffTracking();
if ($vis !== null) { $dsc = $vis ? ' AND id IN (' . implode(',', array_map('intval', $vis)) . ')' : ' AND 1 = 0'; }
$depts = $pdo->prepare("SELECT id, COALESCE(NULLIF(name_local,''), name) n FROM departments WHERE organization_id = ? AND is_active = 1$dsc ORDER BY n");
$depts->execute($dp);
$depts = $depts->fetchAll();
$qs = fn($over) => '?' . http_build_query(array_filter(array_merge(['from' => $from, 'to' => $to, 'dept' => $dept ?: null, 'q' => $q ?: null, 'f' => $f ?: null], $over), fn($v) => $v !== null && $v !== ''));

$pageTitle = 'Biometric हजेरी अहवाल';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="page-title mb-1">📋 Biometric — हजेरी अहवाल</div>
<?= bioTabs('punches') ?>
<form method="GET" class="panel d-flex flex-wrap gap-2 align-items-end" style="padding:12px 16px;">
  <div><label class="form-label-custom" style="font-size:11px;">पासून</label><input type="date" name="from" value="<?= h($from) ?>" class="form-control-custom"></div>
  <div><label class="form-label-custom" style="font-size:11px;">पर्यंत</label><input type="date" name="to" value="<?= h($to) ?>" class="form-control-custom"></div>
  <div><label class="form-label-custom" style="font-size:11px;">विभाग</label><select name="dept" class="form-select-custom"><option value="">सगळे</option><?php foreach ($depts as $d): ?><option value="<?= (int)$d['id'] ?>" <?= $dept === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['n']) ?></option><?php endforeach; ?></select></div>
  <div style="flex:1;min-width:160px;"><label class="form-label-custom" style="font-size:11px;">कर्मचारी</label><input type="search" name="q" value="<?= h($q) ?>" class="form-control-custom" placeholder="नाव / क्रमांक"></div>
  <?php if ($f): ?><input type="hidden" name="f" value="<?= h($f) ?>"><?php endif; ?>
  <button class="btn" style="background:var(--rail);color:#fff;border-radius:9px;">पाहा</button>
  <a href="<?= h($qs(['csv' => 1])) ?>" class="btn" style="border:1px solid var(--line);border-radius:9px;"><i class="fa-solid fa-file-excel me-1" style="color:#137A4B;"></i>Excel</a>
</form>
<div class="d-flex flex-wrap gap-2 my-3">
  <?php foreach (['' => ['सगळे', null], 'late' => ['⏰ उशिरा', 'late'], 'early' => ['🏃 लवकर गेले', 'early'], 'ot' => ['➕ Overtime', 'ot'], 'missed' => ['☝️ एकच Punch', 'missed'], 'half' => ['🌓 अर्धा दिवस', 'half'], 'absent' => ['🚫 गैरहजर', 'absent']] as $k => [$lbl, $ck]): ?>
    <a href="<?= h($qs(['f' => $k ?: null])) ?>" class="st-chip<?= $f === $k ? ' on' : '' ?>" style="text-decoration:none;"><?= $lbl ?><?= $ck ? ' (' . $counts[$ck] . ')' : '' ?></a>
  <?php endforeach; ?>
</div>
<div class="panel">
  <?php if (!$rows): ?><div class="sm-empty" style="padding:18px;">या कालावधीत नोंदी नाहीत.</div><?php else: ?>
  <div class="table-responsive"><table class="rtable" data-no-filter><thead><tr><th>दिनांक</th><th>कर्मचारी</th><th>आलो</th><th>गेलो</th><th>प्रत्यक्ष काम</th><th>उशीर</th><th>लवकर</th><th>OT</th><th>स्थिती</th></tr></thead><tbody>
  <?php foreach ($rows as $r): $sl = $stLabel[$r['status']] ?? [$r['status'], '#6B7280']; $rp = $raw[$r['user_id'] . '|' . $r['attendance_date']] ?? []; ?>
    <tr>
      <td style="white-space:nowrap;font-size:12.5px;"><?= date('d/m/Y', strtotime($r['attendance_date'])) ?></td>
      <td><b><?= h($r['name']) ?></b><div style="font-size:11px;color:var(--text-dim);"><?= h($r['dept'] ?: '—') ?><?= $r['source'] === 'manual' ? ' · ✏️ हाताने' : '' ?></div>
        <?php if ($rp): ?><details><summary style="font-size:11px;color:var(--rail);cursor:pointer;"><?= count($rp) ?> Punches</summary>
          <div style="font-size:11.5px;line-height:1.7;"><?php foreach ($rp as $x): ?><?= date('h:i A', strtotime($x['punch_time'])) ?> <?= $x['punch_state'] !== null ? '· ' . h(bioStateLabel($x['punch_state'])) : '' ?> · <?= h(bioVerifyLabel($x['verify_mode'])) ?> · <?= h($x['device_name']) ?><br><?php endforeach; ?></div></details><?php endif; ?></td>
      <td class="font-mono"><?= $r['first_punch_time'] ? date('h:i A', strtotime($r['first_punch_time'])) : '—' ?></td>
      <td class="font-mono"><?= $r['last_punch_time'] ? date('h:i A', strtotime($r['last_punch_time'])) : (!empty($r['missed_punch']) ? '<span style="color:#B45309;">Punch नाही</span>' : '—') ?></td>
      <td data-sort="<?= (int)($r['worked_minutes'] ?? 0) ?>"><?= bioMinutes($r['worked_minutes'] ?? 0) ?></td>
      <td data-sort="<?= (int)($r['late_minutes'] ?? 0) ?>" style="color:#B45309;"><?= bioMinutes($r['late_minutes'] ?? 0) ?></td>
      <td data-sort="<?= (int)($r['early_minutes'] ?? 0) ?>" style="color:#B45309;"><?= bioMinutes($r['early_minutes'] ?? 0) ?></td>
      <td data-sort="<?= (int)($r['overtime_minutes'] ?? 0) ?>" style="color:#16A34A;font-weight:600;"><?= bioMinutes($r['overtime_minutes'] ?? 0) ?></td>
      <td><span class="bio-chip" style="color:<?= $sl[1] ?>;border:1px solid <?= $sl[1] ?>;"><?= h($sl[0]) ?></span><?= $r['remarks'] ? '<div style="font-size:10.5px;color:var(--text-dim);max-width:180px;">' . h($r['remarks']) . '</div>' : '' ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</div>
<style>.st-chip{border:1px solid var(--line);background:var(--surface);border-radius:20px;padding:5px 12px;font-size:12.5px;color:var(--text);} .st-chip.on{background:var(--rail);border-color:var(--rail);color:#fff;}</style>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
