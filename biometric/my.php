<?php
// 👤 माझी हजेरी — आजचे Punches, महिन्याचा हिशोब (उशीर, प्रत्यक्ष काम, Overtime). प्रत्येक कर्मचाऱ्यासाठी.
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/_common.php';
$me = currentUser();
$orgId = activeOrgId();
$ready = bioReady($pdo);
$month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
$mStart = "$month-01"; $mEnd = date('Y-m-t', strtotime($mStart));

$st = $pdo->prepare("SELECT * FROM staff_attendance WHERE user_id = ? AND attendance_date BETWEEN ? AND ? ORDER BY attendance_date DESC");
$st->execute([(int)$me['id'], $mStart, $mEnd]);
$rows = $st->fetchAll();
$today = [];
if ($ready) {
    $t = $pdo->prepare("SELECT p.punch_time, p.punch_state, p.verify_mode, d.device_name, d.location FROM bio_punches p JOIN bio_devices d ON d.id = p.device_id
        WHERE p.user_id = ? AND p.punch_time >= CURDATE() ORDER BY p.punch_time");
    $t->execute([(int)$me['id']]);
    $today = $t->fetchAll();
}
$sum = ['days' => 0, 'late' => 0, 'lateMin' => 0, 'worked' => 0, 'ot' => 0, 'half' => 0, 'missed' => 0];
foreach ($rows as $r) {
    if (in_array($r['status'], ['present', 'half_day'], true)) { $sum['days']++; }
    if ((int)($r['late_minutes'] ?? 0) > 0) { $sum['late']++; $sum['lateMin'] += (int)$r['late_minutes']; }
    $sum['worked'] += (int)($r['worked_minutes'] ?? 0); $sum['ot'] += (int)($r['overtime_minutes'] ?? 0);
    if ($r['status'] === 'half_day') { $sum['half']++; }
    if (!empty($r['missed_punch'])) { $sum['missed']++; }
}
$sh = bioShift($pdo, (int)$me['id']);
$stLabel = ['present' => ['हजर', '#16A34A'], 'half_day' => ['अर्धा दिवस', '#B45309'], 'absent' => ['गैरहजर', '#DC2626'], 'on_leave' => ['रजा', '#2563EB'], 'holiday' => ['सुट्टी', '#7C3AED']];

$pageTitle = 'माझी हजेरी';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/smart-insights.php';
?>
<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
  <div class="page-title mb-1">👤 माझी हजेरी</div>
  <form method="GET"><input type="month" name="month" value="<?= h($month) ?>" class="form-control-custom" style="width:auto;" onchange="this.form.submit()"></form>
</div>
<?= bioTabs('my') ?>
<div style="font-size:12.5px;color:var(--text-dim);margin-bottom:6px;">
  माझी Shift: <b><?= date('h:i A', strtotime($sh['start'])) ?> – <?= date('h:i A', strtotime($sh['end'])) ?></b> · <?= (int)$sh['grace'] ?> मि. सवलत
  · Machine PIN: <b><?= h($me['biometric_pin'] ?? '') ?: 'दिलेला नाही (Admin ला सांगा)' ?></b>
</div>
<?php smartTiles([
    ['label' => '✅ हजर दिवस', 'value' => $sum['days'], 'sub' => $sum['half'] ? $sum['half'] . ' अर्धे दिवस' : 'या महिन्यात', 'tone' => 'ok'],
    ['label' => '⏰ उशिरा', 'value' => $sum['late'], 'sub' => $sum['lateMin'] ? 'एकूण ' . bioMinutes($sum['lateMin']) : 'एकदाही नाही ✓', 'tone' => $sum['late'] ? 'warn' : 'ok'],
    ['label' => '🕘 प्रत्यक्ष काम', 'value' => intdiv($sum['worked'], 60) . ' तास', 'sub' => $sum['days'] ? 'रोज सरासरी ' . bioMinutes(intdiv($sum['worked'], max(1, $sum['days']))) : ''],
    ['label' => '➕ Overtime', 'value' => bioMinutes($sum['ot']), 'tone' => $sum['ot'] ? 'ok' : ''],
    ['label' => '☝️ एकच Punch', 'value' => $sum['missed'], 'sub' => 'बाहेर जाताना Punch विसरले', 'tone' => $sum['missed'] ? 'warn' : 'ok'],
]); ?>
<div class="row g-3">
  <div class="col-lg-4">
    <div class="panel h-100"><div class="panel-head"><div class="title">🖐️ आजचे Punches</div></div>
      <div style="padding:6px 16px 12px;">
        <?php if (!$today): ?><div class="sm-empty">आज अजून Punch नाही.</div><?php endif; ?>
        <?php foreach ($today as $x): ?><div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px dashed var(--line);font-size:13px;">
          <b class="font-mono"><?= date('h:i A', strtotime($x['punch_time'])) ?></b><small style="color:var(--text-dim);"><?= $x['punch_state'] !== null ? h(bioStateLabel($x['punch_state'])) . ' · ' : '' ?><?= h(bioVerifyLabel($x['verify_mode'])) ?> · <?= h($x['device_name']) ?></small></div><?php endforeach; ?>
        <div style="font-size:11.5px;color:var(--text-dim);margin-top:8px;">चुकीची नोंद? <a href="../staff/regularization.php">दुरुस्ती विनंती करा</a></div>
      </div>
    </div>
  </div>
  <div class="col-lg-8">
    <div class="panel"><div class="panel-head"><div class="title">📅 <?= h(date('F Y', strtotime($mStart))) ?></div></div>
      <?php if (!$rows): ?><div class="sm-empty" style="padding:16px;">या महिन्यात नोंदी नाहीत.</div><?php else: ?>
      <div class="table-responsive"><table class="rtable" data-no-filter><thead><tr><th>दिनांक</th><th>आलो</th><th>गेलो</th><th>काम</th><th>उशीर</th><th>OT</th><th>स्थिती</th></tr></thead><tbody>
      <?php foreach ($rows as $r): $sl = $stLabel[$r['status']] ?? [$r['status'], '#6B7280']; ?>
        <tr><td style="white-space:nowrap;"><?= date('d/m D', strtotime($r['attendance_date'])) ?></td>
          <td class="font-mono"><?= $r['first_punch_time'] ? date('h:i A', strtotime($r['first_punch_time'])) : '—' ?></td>
          <td class="font-mono"><?= $r['last_punch_time'] ? date('h:i A', strtotime($r['last_punch_time'])) : (!empty($r['missed_punch']) ? '<span style="color:#B45309;">नाही</span>' : '—') ?></td>
          <td><?= bioMinutes($r['worked_minutes'] ?? 0) ?></td><td style="color:#B45309;"><?= bioMinutes($r['late_minutes'] ?? 0) ?></td><td style="color:#16A34A;"><?= bioMinutes($r['overtime_minutes'] ?? 0) ?></td>
          <td><span class="bio-chip" style="color:<?= $sl[1] ?>;border:1px solid <?= $sl[1] ?>;"><?= h($sl[0]) ?></span></td></tr>
      <?php endforeach; ?>
      </tbody></table></div><?php endif; ?>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
