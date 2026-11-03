<?php
// 🏠 वसतिगृह — Smart आढावा (hostels/index.php मधून include; $hostels, $orgId आधीच आहेत)
// प्रत्येक वसतिगृहाची भरती (Occupancy), खाटा, आजची हजेरी, बाहेर गेलेले/परत न आलेले,
// थकीत Fee व आरोग्य-घटना — आणि वरती "लक्ष द्या" सूचना.

$hi = [];
$hiTotals = ['students' => 0, 'capacity' => 0, 'out' => 0, 'overdue' => 0, 'dues' => 0.0, 'health' => 0];
$hiIds = array_map(fn($h) => (int)$h['id'], array_filter($hostels, fn($h) => $h['is_active']));
if ($hiIds) {
    $ph = implode(',', array_fill(0, count($hiIds), '?'));
    $q = function ($sql) use ($pdo, $hiIds) {
        $out = [];
        try { $st = $pdo->prepare($sql); $st->execute($hiIds); foreach ($st->fetchAll() as $r) { $out[(int)$r['hostel_id']] = $r; } } catch (PDOException $e) {}
        return $out;
    };
    $beds = $q("SELECT r.hostel_id, SUM(r.capacity) beds, (SELECT COUNT(*) FROM hostel_room_allocations a JOIN hostel_rooms r2 ON r2.id = a.room_id WHERE r2.hostel_id = r.hostel_id AND a.status = 'active') used
        FROM hostel_rooms r WHERE r.is_active = 1 AND r.hostel_id IN ($ph) GROUP BY r.hostel_id");
    // आजचं शेवटचं नोंदवलेलं सत्र (सकाळ/दुपार/संध्याकाळ)
    $att = $q("SELECT a.hostel_id, a.session, SUM(a.status = 'present') present, COUNT(*) total
        FROM hostel_student_attendance a
        WHERE a.attendance_date = CURDATE() AND a.hostel_id IN ($ph)
          AND a.session = (SELECT a2.session FROM hostel_student_attendance a2 WHERE a2.hostel_id = a.hostel_id AND a2.attendance_date = CURDATE() ORDER BY FIELD(a2.session,'evening','afternoon','morning') LIMIT 1)
        GROUP BY a.hostel_id, a.session");
    $leave = $q("SELECT s.hostel_id, COUNT(*) out_cnt, SUM(l.expected_return_date < CURDATE()) overdue
        FROM hostel_leave_records l JOIN hostel_students s ON s.id = l.student_id
        WHERE l.status = 'out' AND s.hostel_id IN ($ph) GROUP BY s.hostel_id");
    $fees = $q("SELECT s.hostel_id, SUM(f.amount_due - COALESCE((SELECT SUM(p.amount_paid) FROM hostel_fee_payments p WHERE p.fee_id = f.id), 0)) dues,
            SUM(f.due_date < CURDATE()) overdue_fees
        FROM hostel_fees f JOIN hostel_students s ON s.id = f.student_id
        WHERE f.status IN ('pending','partial') AND s.hostel_id IN ($ph) GROUP BY s.hostel_id");
    $health = $q("SELECT s.hostel_id, COUNT(*) cnt FROM hostel_health_incidents i JOIN hostel_students s ON s.id = i.student_id
        WHERE i.incident_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) AND s.hostel_id IN ($ph) GROUP BY s.hostel_id");

    foreach ($hostels as $h) {
        if (!$h['is_active']) continue;
        $id = (int)$h['id'];
        $students = (int)$h['student_count'];
        $cap = (int)($h['capacity'] ?: ($beds[$id]['beds'] ?? 0));
        $row = [
            'id' => $id, 'name' => $h['name'], 'campus' => $h['campus_name'] ?? '',
            'students' => $students, 'capacity' => $cap,
            'occ' => $cap > 0 ? (int)round($students * 100 / $cap) : null,
            'beds' => (int)($beds[$id]['beds'] ?? 0), 'bedsUsed' => (int)($beds[$id]['used'] ?? 0),
            'attPct' => isset($att[$id]) && $att[$id]['total'] > 0 ? (int)round($att[$id]['present'] * 100 / $att[$id]['total']) : null,
            'attAbsent' => isset($att[$id]) ? (int)$att[$id]['total'] - (int)$att[$id]['present'] : 0,
            'session' => $att[$id]['session'] ?? null,
            'out' => (int)($leave[$id]['out_cnt'] ?? 0), 'overdue' => (int)($leave[$id]['overdue'] ?? 0),
            'dues' => max(0, (float)($fees[$id]['dues'] ?? 0)), 'overdueFees' => (int)($fees[$id]['overdue_fees'] ?? 0),
            'health' => (int)($health[$id]['cnt'] ?? 0),
        ];
        $hi[] = $row;
        $hiTotals['students'] += $students; $hiTotals['capacity'] += $cap;
        $hiTotals['out'] += $row['out']; $hiTotals['overdue'] += $row['overdue'];
        $hiTotals['dues'] += $row['dues']; $hiTotals['health'] += $row['health'];
    }
}
$hiSession = ['morning' => 'सकाळ', 'afternoon' => 'दुपार', 'evening' => 'संध्याकाळ'];

if ($hi):
    $hiAlerts = [];
    if ($hiTotals['overdue']) $hiAlerts[] = ['bad', '🚪 ' . $hiTotals['overdue'] . ' विद्यार्थी परतीच्या तारखेनंतरही परत आलेले नाहीत', 'leave.php'];
    foreach ($hi as $r) {
        if ($r['occ'] !== null && $r['occ'] > 100) $hiAlerts[] = ['bad', '🛏️ ' . $r['name'] . ' — क्षमतेपेक्षा जास्त (' . $r['occ'] . '%)', 'room-allocation.php'];
        if ($r['attPct'] !== null && $r['attPct'] < 85) $hiAlerts[] = ['warn', '📋 ' . $r['name'] . ' — आजची हजेरी फक्त ' . $r['attPct'] . '%', 'attendance.php?hostel_id=' . $r['id']];
        if ($r['students'] > 0 && $r['attPct'] === null && (int)date('G') >= 10) $hiAlerts[] = ['info', '📋 ' . $r['name'] . ' — आज हजेरी अजून नोंदवली नाही', 'attendance.php?hostel_id=' . $r['id']];
    }
    if ($hiTotals['health']) $hiAlerts[] = ['warn', '🏥 मागील ७ दिवसांत ' . $hiTotals['health'] . ' आरोग्य-घटना', 'health.php'];
?>
<div class="hi-totals mb-3">
  <div><span>एकूण विद्यार्थी</span><b><?= $hiTotals['students'] ?></b><?php if ($hiTotals['capacity']): ?><small><?= (int)round($hiTotals['students'] * 100 / $hiTotals['capacity']) ?>% भरती</small><?php endif; ?></div>
  <div><span>सध्या बाहेर (सुट्टी)</span><b class="c-amber"><?= $hiTotals['out'] ?></b><?php if ($hiTotals['overdue']): ?><small class="c-rose"><?= $hiTotals['overdue'] ?> उशिराने</small><?php endif; ?></div>
  <div><span>थकीत Fee</span><b class="c-rose">₹<?= number_format($hiTotals['dues']) ?></b></div>
  <div><span>आरोग्य-घटना (७ दिवस)</span><b><?= $hiTotals['health'] ?></b></div>
</div>
<?php if ($hiAlerts): ?>
  <div class="sm-attn mb-3"><div class="sm-attn-title"><i class="fa-solid fa-bell"></i> लक्ष द्या</div>
    <div class="sm-attn-list"><?php foreach ($hiAlerts as [$sev, $txt, $href]): ?><a class="sm-attn-item <?= $sev ?>" href="<?= h($href) ?>"><span><?= h($txt) ?></span><i class="fa-solid fa-chevron-right sm-go"></i></a><?php endforeach; ?></div>
  </div>
<?php endif; ?>
<div class="hi-grid mb-3">
  <?php foreach ($hi as $r): $oc = $r['occ'] ?? 0; $occCol = $oc > 100 ? 'var(--rose)' : ($oc >= 90 ? 'var(--amber)' : 'var(--rail)'); ?>
    <a class="hi-card" href="dashboard.php?id=<?= $r['id'] ?>">
      <div class="d-flex align-items-center gap-3">
        <div class="sm-score" style="--p:<?= min(100, $oc) ?>;--lc:<?= $occCol ?>;width:54px;height:54px;"><span style="width:43px;height:43px;font-size:12px;"><?= $r['occ'] === null ? '—' : $r['occ'] . '%' ?></span></div>
        <div class="sm-main"><div class="sm-t" style="font-size:14.5px;"><?= h($r['name']) ?></div>
          <div class="sm-s"><?= h($r['campus'] ?: '—') ?> · <?= $r['students'] ?><?= $r['capacity'] ? ' / ' . $r['capacity'] : '' ?> विद्यार्थी</div></div>
      </div>
      <div class="hi-stats">
        <div><span>खाटा</span><b><?= $r['beds'] ? $r['bedsUsed'] . '/' . $r['beds'] : '—' ?></b></div>
        <div><span>आज हजेरी<?= $r['session'] ? ' (' . h($hiSession[$r['session']] ?? '') . ')' : '' ?></span><b class="<?= $r['attPct'] === null ? '' : ($r['attPct'] >= 95 ? 'c-ok' : ($r['attPct'] >= 85 ? 'c-amber' : 'c-rose')) ?>"><?= $r['attPct'] === null ? 'नोंद नाही' : $r['attPct'] . '%' ?></b></div>
        <div><span>बाहेर</span><b class="<?= $r['overdue'] ? 'c-rose' : '' ?>"><?= $r['out'] ?><?= $r['overdue'] ? ' (' . $r['overdue'] . '⏰)' : '' ?></b></div>
        <div><span>थकीत Fee</span><b class="<?= $r['dues'] > 0 ? 'c-rose' : '' ?>">₹<?= number_format($r['dues']) ?></b></div>
      </div>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
