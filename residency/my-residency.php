<?php
// 🏠 माझं निवासस्थान — रहिवासी विभागाने (HOD) मला दिलेली Room आणि तिचा सगळा तपशील:
//   Room/इमारत · सोबत राहणारे व कुटुंबीय · खोलीतलं साहित्य · Movement (प्रवेश → खोली-बदल → खोली सोडली) ·
//   तपासण्या · देखभाल नोंदी · पाहुणे · बिलं · माझ्या निवास-विनंत्या
// फक्त स्वतःचा Data (user_id = मी) — दुसऱ्याचा काहीही दिसत नाही.
$pageTitle = 'माझं निवासस्थान';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$orgId = activeOrgId();
$uid = (int)$user['id'];
$q = function ($sql, array $p, $mode = 'all') use ($pdo) {
    try { $st = $pdo->prepare($sql); $st->execute($p); return $mode === 'one' ? $st->fetch() : $st->fetchAll(); }
    catch (PDOException $e) { error_log('my-residency: ' . $e->getMessage()); return $mode === 'one' ? null : []; }   // एखादं Table नसेल (जुना DB) तरी पान चालावं
};

// माझी सध्याची नोंद (कर्मचारी म्हणून, किंवा कुटुंबीय-कर्मचारी म्हणून)
$my = $q("SELECT o.*, ml.name AS room_name, ml.location_code, ml.floor, ml.capacity, b.name AS building_name, c.name AS campus_name, cb.name AS allotted_by_name
          FROM residency_occupants o JOIN maintenance_locations ml ON ml.id = o.location_id
          LEFT JOIN buildings b ON b.id = ml.building_id LEFT JOIN campuses c ON c.id = COALESCE(b.campus_id, ml.campus_id)
          LEFT JOIN users cb ON cb.id = o.created_by
          WHERE o.organization_id = ? AND o.user_id = ? AND o.status = 'active' ORDER BY o.occupant_type = 'staff' DESC, o.id DESC LIMIT 1", [$orgId, $uid], 'one');

// माझ्या सगळ्या नोंदी (आधीच्या — सोडलेल्या — सकट) → Movement इतिहास
$allMine = $q("SELECT o.*, ml.name AS room_name, b.name AS building_name, cb.name AS by_name, vb.name AS vacated_by_name
               FROM residency_occupants o JOIN maintenance_locations ml ON ml.id = o.location_id LEFT JOIN buildings b ON b.id = ml.building_id
               LEFT JOIN users cb ON cb.id = o.created_by LEFT JOIN users vb ON vb.id = o.vacated_by
               WHERE o.organization_id = ? AND o.user_id = ? ORDER BY o.id", [$orgId, $uid]);
$myIds = array_map('intval', array_column($allMine, 'id'));
$transfers = $myIds ? $q("SELECT t.*, fl.name AS from_room, fb.name AS from_building, tl.name AS to_room, tb.name AS to_building, u.name AS by_name
               FROM residency_room_transfers t LEFT JOIN maintenance_locations fl ON fl.id = t.from_location_id LEFT JOIN buildings fb ON fb.id = fl.building_id
               JOIN maintenance_locations tl ON tl.id = t.to_location_id LEFT JOIN buildings tb ON tb.id = tl.building_id LEFT JOIN users u ON u.id = t.transferred_by
               WHERE t.occupant_id IN (" . implode(',', $myIds) . ") ORDER BY t.transfer_date, t.id", []) : [];
$requests = $q("SELECT r.*, ml.name AS room_name, b.name AS building_name, pb.name AS pref_building, u.name AS approved_by_name
               FROM residency_allotment_requests r LEFT JOIN maintenance_locations ml ON ml.id = r.allotted_location_id LEFT JOIN buildings b ON b.id = ml.building_id
               LEFT JOIN buildings pb ON pb.id = r.preferred_building_id LEFT JOIN users u ON u.id = r.approved_by
               WHERE r.organization_id = ? AND r.requested_by = ? ORDER BY r.id DESC", [$orgId, $uid]);

// Movement Timeline — [date, icon, tone, title, detail]
$roomLbl = fn($bld, $room) => trim(($bld ? $bld . ' — ' : '') . (string)$room);
$timeline = [];
foreach ($requests as $r) {
    $timeline[] = [$r['created_at'], '📝', 'info', 'निवासासाठी विनंती केली', ($r['pref_building'] ? 'पसंती: ' . $r['pref_building'] . ' · ' : '') . 'कुटुंब: ' . (int)$r['family_size'] . ' जण'];
    if ($r['status'] !== 'pending' && $r['approved_at']) {
        $st = ['approved' => ['✅', 'good', 'विनंती मंजूर — Room दिली'], 'rejected' => ['❌', 'bad', 'विनंती नामंजूर'], 'waitlisted' => ['⏳', 'warn', 'प्रतीक्षा यादीत']][$r['status']] ?? ['•', 'info', $r['status']];
        $timeline[] = [$r['approved_at'], $st[0], $st[1], $st[2], trim(($r['room_name'] ? $roomLbl($r['building_name'], $r['room_name']) . ' · ' : '') . ($r['approved_by_name'] ? 'by ' . $r['approved_by_name'] : '') . ($r['remarks'] ? ' · ' . $r['remarks'] : ''), ' ·')];
    }
}
// प्रत्येक नोंदीची सुरुवातीची खोली = पहिल्या खोली-बदलाची "from", नाहीतर सध्याची
$firstRoom = [];
foreach ($transfers as $t) { $firstRoom[(int)$t['occupant_id']] ??= $roomLbl($t['from_building'], $t['from_room']); }
foreach ($allMine as $o) {
    $start = $firstRoom[(int)$o['id']] ?? $roomLbl($o['building_name'], $o['room_name']);
    $timeline[] = [$o['moved_in_date'] ?: $o['created_at'], '🔑', 'good', 'Room मिळाली — राहायला सुरुवात', $start . ($o['by_name'] ? ' · नोंद: ' . $o['by_name'] : '') . ($o['occupant_type'] === 'family' ? ' · (कुटुंबीय म्हणून)' : '')];
    if ($o['status'] === 'vacated') {
        $timeline[] = [$o['vacated_date'] ?: $o['created_at'], '🚪', 'bad', 'Room सोडली', $roomLbl($o['building_name'], $o['room_name']) . ($o['vacated_remark'] ? ' · ' . $o['vacated_remark'] : '') . ($o['vacated_by_name'] ? ' · नोंद: ' . $o['vacated_by_name'] : '')];
    }
}
foreach ($transfers as $t) {
    $timeline[] = [$t['transfer_date'], '🔁', 'warn', 'खोली बदलली', $roomLbl($t['from_building'], $t['from_room']) . ' → ' . $roomLbl($t['to_building'], $t['to_room']) . ($t['reason'] ? ' · कारण: ' . $t['reason'] : '') . ($t['by_name'] ? ' · by ' . $t['by_name'] : '')];
}
usort($timeline, fn($a, $b) => strcmp((string)$b[0], (string)$a[0]));   // नवीन आधी

if ($my) {
    $loc = (int)$my['location_id'];
    $people = $q("SELECT o.*, u.name AS user_name, u.designation FROM residency_occupants o LEFT JOIN users u ON u.id = o.user_id
                  WHERE o.location_id = ? AND o.status = 'active' AND o.id <> ? ORDER BY o.occupant_type = 'staff' DESC, COALESCE(u.name, o.name)", [$loc, $my['id']]);
    $items = $q("SELECT ri.*, u.name AS added_by_name FROM residency_room_items ri LEFT JOIN users u ON u.id = ri.added_by WHERE ri.location_id = ? ORDER BY ri.ownership, ri.item_name", [$loc]);
    $inspections = $q("SELECT i.*, u.name AS by_name FROM residency_inspections i LEFT JOIN users u ON u.id = i.inspected_by WHERE i.location_id = ? ORDER BY i.inspection_date DESC, i.id DESC LIMIT 10", [$loc]);
    $mlogs = $q("SELECT m.*, u.name AS by_name, COALESCE(NULLIF(d.name_local,''), d.name) AS dept FROM maintenance_logs m LEFT JOIN users u ON u.id = m.performed_by LEFT JOIN departments d ON d.id = m.department_id
                 WHERE m.location_id = ? ORDER BY m.work_date DESC, m.id DESC LIMIT 15", [$loc]);
    $guests = $q("SELECT * FROM residency_guests WHERE location_id = ? ORDER BY status = 'checked_in' DESC, arrival_date DESC LIMIT 10", [$loc]);
    $myBills = $q("SELECT rb.*, s.status AS my_status, s.amount_due, s.paid_at FROM residency_bills rb JOIN residency_bill_shares s ON s.bill_id = rb.id
                   WHERE s.occupant_id = ? ORDER BY rb.billing_month DESC LIMIT 12", [$my['id']]);
    $pendingCount = count(array_filter($myBills, fn($b) => $b['my_status'] === 'pending'));
    $totalPending = array_sum(array_map(fn($b) => $b['my_status'] === 'pending' ? $b['amount_due'] : 0, $myBills));
    $since = $my['moved_in_date'] ?: substr((string)$my['created_at'], 0, 10);
    $days = $since ? max(0, (int)floor((time() - strtotime($since)) / 86400)) : null;
    $stayLbl = $days === null ? '—' : ($days >= 365 ? floor($days / 365) . ' वर्ष ' . floor(($days % 365) / 30) . ' महिने' : ($days >= 30 ? floor($days / 30) . ' महिने ' . ($days % 30) . ' दिवस' : $days . ' दिवस'));
    $lastInsp = $inspections[0] ?? null;
}
$ratingLabels = ['excellent' => ['उत्तम', '#16A34A'], 'good' => ['चांगली', '#65A30D'], 'fair' => ['ठीक', '#D97706'], 'poor' => ['वाईट', '#DC2626']];
$reqLabels = ['pending' => ['⏳ प्रलंबित', 'pending'], 'approved' => ['✅ मंजूर', 'done'], 'rejected' => ['❌ नामंजूर', 'overdue'], 'waitlisted' => ['🕒 प्रतीक्षा यादी', 'pending']];
$hasPendingReq = (bool)array_filter($requests, fn($r) => in_array($r['status'], ['pending', 'waitlisted'], true));
?>
<style>
  .mr-hero{position:relative;overflow:hidden;border-radius:20px;padding:20px 22px;color:#fff;margin-bottom:14px;
    background:radial-gradient(110% 140% at 100% 0%,rgba(217,83,15,.35) 0%,transparent 48%),linear-gradient(135deg,#141833 0%,#1F2450 60%,#262B5E 100%);box-shadow:0 14px 34px -18px rgba(20,24,51,.65);}
  .mr-hero .ey{font-size:11px;font-weight:800;letter-spacing:.12em;color:#FDBA8C;text-transform:uppercase;}
  .mr-hero h1{font-size:24px;font-weight:800;margin:4px 0 2px;color:#fff;}
  .mr-hero .sub{font-size:12.5px;color:rgba(255,255,255,.72);}
  .mr-chips{display:flex;flex-wrap:wrap;gap:8px;margin-top:14px;}
  .mr-chip{background:rgba(255,255,255,.09);border:1px solid rgba(255,255,255,.14);border-radius:12px;padding:8px 12px;min-width:120px;}
  .mr-chip .l{font-size:11px;color:rgba(255,255,255,.65);font-weight:600;} .mr-chip .v{font-size:15px;font-weight:800;color:#fff;}
  .mr-sec{font-size:15px;font-weight:800;margin:0;}
  .mr-person{display:flex;gap:10px;align-items:center;padding:8px 0;border-bottom:1px dashed var(--line);font-size:13px;}
  .mr-person:last-child{border-bottom:0;} .mr-person .av{width:34px;height:34px;border-radius:50%;display:grid;place-items:center;font-weight:800;background:var(--rail-soft,#FDF0E8);color:var(--rail);flex:0 0 34px;}
  .mr-person small{display:block;color:var(--text-dim);font-size:11.5px;}
  .mr-tl{position:relative;padding-left:26px;}
  .mr-tl::before{content:'';position:absolute;left:10px;top:4px;bottom:4px;width:2px;background:var(--line);}
  .mr-ev{position:relative;padding:0 0 14px;}
  .mr-ev .dot{position:absolute;left:-26px;top:0;width:22px;height:22px;border-radius:50%;display:grid;place-items:center;font-size:11px;background:var(--surface,#fff);border:2px solid var(--tc);}
  .mr-ev .t{font-size:13px;font-weight:700;} .mr-ev .d{font-size:12px;color:var(--text-dim);margin-top:1px;} .mr-ev .w{font-size:11px;color:var(--text-dim);font-family:'JetBrains Mono',monospace;}
  .mr-badge{display:inline-block;font-size:11px;font-weight:700;border-radius:99px;padding:2px 9px;color:#fff;}
  .mr-empty{padding:16px 18px;color:var(--text-dim);font-size:12.5px;}
  .mr-acts{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px;}
  .mr-acts a{display:inline-flex;align-items:center;gap:7px;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);color:#fff;text-decoration:none;border-radius:10px;padding:7px 12px;font-size:12.5px;font-weight:700;}
  .mr-acts a:hover{background:rgba(255,255,255,.2);color:#fff;} .mr-acts a.hot{background:#B42318;border-color:#B42318;}
  .mr-by{font-size:11px;color:rgba(255,255,255,.55);margin-top:10px;}
  .mr-none{display:flex;flex-wrap:wrap;gap:6px 8px;align-items:center;background:var(--surface,#fff);border:1px dashed var(--line);border-radius:14px;padding:10px 14px;}
  .mr-none .t{font-size:12px;font-weight:800;color:var(--text-dim);margin-right:4px;}
  .mr-none .c{font-size:12px;background:var(--canvas);border-radius:99px;padding:3px 10px;color:var(--text-dim);}
  @media (max-width:600px){ .mr-hero{padding:16px;} .mr-hero h1{font-size:19px;} .mr-chips{gap:6px;} .mr-chip{flex:1 1 calc(50% - 6px);min-width:0;padding:7px 10px;} .mr-chip .v{font-size:14px;} .mr-acts a{flex:1 1 auto;justify-content:center;} }
</style>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>

<?php if (!$my): ?>
  <div class="panel" style="padding:36px;text-align:center;">
    <div style="font-size:40px;margin-bottom:10px;">🏠</div>
    <div style="font-size:15px;font-weight:700;margin-bottom:4px;">तुम्हाला सध्या कुठलीही Room वाटप झालेली नाही.</div>
    <div style="font-size:12.5px;color:var(--text-dim);margin-bottom:16px;">रहिवासी विभागाने Room दिल्यावर तिचा सगळा तपशील इथे आपोआप दिसेल.</div>
    <?php if (!$hasPendingReq): ?><a href="request-allotment.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:9px 20px;font-weight:600;">निवासासाठी विनंती करा</a>
    <?php else: ?><span class="status-chip pending">⏳ तुमची निवास-विनंती रहिवासी विभागाकडे प्रलंबित आहे</span><?php endif; ?>
  </div>
<?php else: ?>

  <!-- ---------- 🏠 Room — मुख्य तपशील ---------- -->
  <div class="mr-hero">
    <div class="ey">🏠 माझं निवासस्थान<?= $my['campus_name'] ? ' · ' . h($my['campus_name']) : '' ?></div>
    <h1><?= h($my['building_name'] ? $my['building_name'] . ' — ' : '') ?><?= h($my['room_name']) ?></h1>
    <div class="sub">Room Code <b><?= h($my['location_code']) ?></b> · मजला <?= (int)$my['floor'] === 0 ? 'तळमजला' : (int)$my['floor'] ?><?= $my['occupant_type'] === 'family' ? ' · कुटुंबीय म्हणून नोंद' . ($my['relation'] ? ' (' . h($my['relation']) . ')' : '') : '' ?></div>
    <div class="mr-chips">
      <div class="mr-chip"><div class="l">राहायला सुरुवात</div><div class="v"><?= $since ? date('d M Y', strtotime($since)) : '—' ?></div></div>
      <div class="mr-chip"><div class="l">इथे राहून</div><div class="v"><?= h($stayLbl) ?></div></div>
      <div class="mr-chip"><div class="l">खोलीत एकूण</div><div class="v"><?= count($people) + 1 ?><?= $my['capacity'] ? ' / ' . (int)$my['capacity'] : '' ?> जण</div></div>
      <div class="mr-chip"><div class="l">बाकी बिलं</div><div class="v" style="color:<?= $pendingCount ? '#FCA5A5' : '#86EFAC' ?>;"><?= $pendingCount ?> · ₹<?= number_format($totalPending, 0) ?></div></div>
      <?php if ($lastInsp): $rl = $ratingLabels[$lastInsp['condition_rating']] ?? ['—', '#999']; ?><div class="mr-chip"><div class="l">शेवटची तपासणी</div><div class="v"><span style="color:<?= $rl[1] ?>;">●</span> <?= h($rl[0]) ?> <small style="font-size:11px;font-weight:600;opacity:.7;"><?= date('d M', strtotime($lastInsp['inspection_date'])) ?></small></div></div><?php endif; ?>
    </div>
    <div class="mr-acts">
      <a href="../requests/create.php"><i class="fa-solid fa-screwdriver-wrench"></i> दुरुस्ती / काम सांगा</a>
      <?php if (!$hasPendingReq): ?><a href="request-allotment.php"><i class="fa-solid fa-right-left"></i> Room बदल विनंती</a><?php endif; ?>
      <?php if ($pendingCount): ?><a href="#mrBills" class="hot"><i class="fa-solid fa-indian-rupee-sign"></i> <?= $pendingCount ?> बिल बाकी</a><?php endif; ?>
    </div>
    <?php if ($my['allotted_by_name']): ?><div class="mr-by">नोंद: <?= h($my['allotted_by_name']) ?></div><?php endif; ?>
  </div>
  <?php
  // 📭 अजून नोंद नसलेले भाग — प्रत्येकासाठी मोठा रिकामा Panel नको, एकच छोटी पट्टी
  $emptySecs = [];
  if (!$people) $emptySecs[] = ['👥', 'खोलीत तुम्ही एकटेच'];
  if (!$items) $emptySecs[] = ['🛋️', 'साहित्याची नोंद नाही'];
  if (!$inspections) $emptySecs[] = ['🔍', 'तपासणी झाली नाही'];
  if (!$mlogs) $emptySecs[] = ['🔧', 'देखभालीची नोंद नाही'];
  if (empty($myBills)) $emptySecs[] = ['💰', 'बिल अजून नाही'];
  if (!$guests) $emptySecs[] = ['🧳', 'पाहुण्यांची नोंद नाही'];
  if ($emptySecs): ?>
  <div class="mr-none mb-3"><span class="t">📭 अजून नोंद नाही</span><?php foreach ($emptySecs as [$ic, $tx]): ?><span class="c"><?= $ic ?> <?= h($tx) ?></span><?php endforeach; ?></div>
  <?php endif; ?>

  <?php if ($people || $items): ?>
  <div class="row g-3 mb-3">
    <?php if ($people): ?>
    <!-- ---------- 👥 सोबत राहणारे ---------- -->
    <div class="col-lg">
      <div class="panel h-100">
        <div class="panel-head"><div class="mr-sec">👥 या खोलीत सोबत</div><div class="sub"><?= count($people) ?> जण</div></div>
        <div style="padding:6px 18px 12px;">
          <?php if (!$people): ?><div class="mr-empty" style="padding:10px 0;">या खोलीत तुम्ही एकटेच आहात.</div><?php endif; ?>
          <?php foreach ($people as $pp): $nm = $pp['user_name'] ?: $pp['name']; ?>
            <div class="mr-person"><div class="av"><?= h(mb_substr((string)$nm, 0, 1)) ?></div>
              <div style="flex:1;min-width:0;"><b><?= h($nm) ?></b>
                <small><?= $pp['occupant_type'] === 'family' ? '👪 कुटुंबीय' . ($pp['relation'] ? ' · ' . h($pp['relation']) : '') . ($pp['age'] ? ' · ' . (int)$pp['age'] . ' वर्षे' : '') : '🧑‍💼 कर्मचारी' . ($pp['designation'] ? ' · ' . h($pp['designation']) : '') ?></small></div>
              <?php if ($pp['moved_in_date']): ?><small style="color:var(--text-dim);white-space:nowrap;"><?= date('M Y', strtotime($pp['moved_in_date'])) ?> पासून</small><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endif; if ($items): ?>
    <!-- ---------- 🛋️ खोलीतलं साहित्य ---------- -->
    <div class="col-lg">
      <div class="panel h-100">
        <div class="panel-head"><div class="mr-sec">🛋️ खोलीतलं साहित्य</div><div class="sub"><?= count($items) ?> नोंदी · संस्थेचं साहित्य सोडताना परत द्यावं लागतं</div></div>
        <?php if (!$items): ?><div class="mr-empty">रहिवासी विभागाने अजून साहित्याची नोंद केलेली नाही.</div><?php else: ?>
        <div style="overflow-x:auto;"><table class="rtable">
          <thead><tr><th>साहित्य</th><th>मालकी</th><th style="text-align:right;">संख्या</th><th>नोंद</th></tr></thead>
          <tbody><?php foreach ($items as $it): ?>
            <tr><td><b><?= h($it['item_name']) ?></b></td>
              <td><span class="status-chip <?= $it['ownership'] === 'संस्थेचे' ? 'done' : 'pending' ?>"><?= h($it['ownership']) ?></span></td>
              <td style="text-align:right;" class="font-mono"><?= (int)$it['quantity'] ?></td>
              <td style="font-size:12px;color:var(--text-dim);"><?= h((string)$it['remarks']) ?></td></tr>
          <?php endforeach; ?></tbody>
        </table></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
<?php endif; ?>

<div class="row g-3 mb-3">
  <!-- ---------- 🔁 Movement — प्रवेश, खोली-बदल, सोडलं, विनंत्या ---------- -->
  <div class="col-lg">
    <div class="panel h-100">
      <div class="panel-head"><div class="mr-sec">🔁 Movement / इतिहास</div><div class="sub">Room मिळाली, बदलली, सोडली — नवीन आधी</div></div>
      <div style="padding:14px 18px 4px;">
        <?php if (!$timeline): ?><div class="mr-empty" style="padding:0 0 12px;">अजून कुठलीही नोंद नाही.</div><?php else: ?>
        <div class="mr-tl">
          <?php $tc = ['good' => '#16A34A', 'warn' => '#D97706', 'bad' => '#DC2626', 'info' => '#2563EB'];
          foreach ($timeline as $ev): ?>
            <div class="mr-ev" style="--tc:<?= $tc[$ev[2]] ?>;"><span class="dot"><?= $ev[1] ?></span>
              <div class="w"><?= $ev[0] ? date('d M Y', strtotime($ev[0])) : '—' ?></div>
              <div class="t"><?= h($ev[3]) ?></div>
              <?php if ($ev[4] !== ''): ?><div class="d"><?= h($ev[4]) ?></div><?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if ($my && ($inspections || $mlogs)): ?>
  <!-- ---------- 🔍 तपासणी + 🔧 देखभाल ---------- -->
  <div class="col-lg-7">
    <?php if ($inspections): ?>
    <div class="panel mb-3">
      <div class="panel-head"><div class="mr-sec">🔍 खोलीची तपासणी</div><div class="sub">रहिवासी विभागाने केलेल्या</div></div>
      <?php if (!$inspections): ?><div class="mr-empty">अजून तपासणी झालेली नाही.</div><?php else: ?>
      <div style="overflow-x:auto;"><table class="rtable">
        <thead><tr><th>तारीख</th><th>स्थिती</th><th>शेरा</th><th>तपासणी</th></tr></thead>
        <tbody><?php foreach ($inspections as $in): $rl = $ratingLabels[$in['condition_rating']] ?? [$in['condition_rating'], '#999']; ?>
          <tr><td class="font-mono" style="white-space:nowrap;"><?= date('d M Y', strtotime($in['inspection_date'])) ?></td>
            <td><span class="mr-badge" style="background:<?= $rl[1] ?>;"><?= h($rl[0]) ?></span></td>
            <td style="font-size:12.5px;"><?= h((string)$in['remarks']) ?: '—' ?></td>
            <td style="font-size:12px;color:var(--text-dim);"><?= h((string)$in['by_name']) ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
      <?php endif; ?>
    </div>
    <?php endif; if ($mlogs): ?>
    <div class="panel">
      <div class="panel-head"><div class="mr-sec">🔧 देखभाल / दुरुस्ती नोंदी</div><div class="sub">या खोलीत झालेली कामं</div></div>
      <?php if (!$mlogs): ?><div class="mr-empty">या खोलीसाठी अजून देखभालीची नोंद नाही.</div><?php else: ?>
      <div style="overflow-x:auto;"><table class="rtable">
        <thead><tr><th>तारीख</th><th>काम</th><th>विभाग</th><th>शेरा</th></tr></thead>
        <tbody><?php foreach ($mlogs as $m): ?>
          <tr><td class="font-mono" style="white-space:nowrap;"><?= date('d M Y', strtotime($m['work_date'])) ?></td>
            <td><b><?= h($m['item_description']) ?></b> <span class="status-chip done" style="margin-left:4px;"><?= h($m['work_type']) ?></span></td>
            <td style="font-size:12px;"><?= h((string)$m['dept']) ?></td>
            <td style="font-size:12px;color:var(--text-dim);"><?= h((string)$m['remarks']) ?><?= $m['by_name'] ? ' · ' . h($m['by_name']) : '' ?></td></tr>
        <?php endforeach; ?></tbody>
      </table></div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php if ($my && (!empty($myBills) || $guests)): ?>
<div class="row g-3 mb-3">
  <?php if (!empty($myBills)): ?>
  <!-- ---------- 💰 बिलं ---------- -->
  <div class="col-lg" id="mrBills">
    <div class="panel h-100">
      <div class="panel-head"><div class="mr-sec">💰 माझं बिल-इतिहास (भाडं + वीज)</div><div class="sub">बाकी: ₹<?= number_format($totalPending, 0) ?></div></div>
      <div style="overflow-x:auto;"><table class="rtable">
        <thead><tr><th>महिना</th><th>खोली-भाडं</th><th>वीज-बिल</th><th>माझा वाटा</th><th>स्थिती</th></tr></thead>
        <tbody>
          <?php if (empty($myBills)): ?><tr><td colspan="5" style="text-align:center;color:var(--text-dim);padding:20px;">अजून कुठलंही बिल तयार झालेलं नाही</td></tr><?php endif; ?>
          <?php foreach ($myBills as $b): ?>
            <tr>
              <td><?= h($b['billing_month']) ?></td>
              <td class="font-mono">₹<?= number_format($b['room_rent'], 0) ?></td>
              <td class="font-mono">₹<?= number_format($b['electricity_bill'], 0) ?></td>
              <td class="font-mono" style="font-weight:600;color:var(--rail);">₹<?= number_format($b['amount_due'], 0) ?> <span style="font-size:10px;color:var(--text-dim);font-weight:400;">(<?= (int)$b['billing_unit_count'] ?> जणांत विभागलं)</span></td>
              <td><?php if ($b['my_status'] === 'paid'): ?><span class="status-chip done">✅ भरलं<?= $b['paid_at'] ? ' (' . date('d M Y', strtotime($b['paid_at'])) . ')' : '' ?></span><?php else: ?><span class="status-chip pending">⏳ बाकी</span><?php endif; ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>
  </div>
  <?php endif; if ($guests): ?>
  <!-- ---------- 🧳 पाहुणे ---------- -->
  <div class="col-lg">
    <div class="panel h-100">
      <div class="panel-head"><div class="mr-sec">🧳 या खोलीतले पाहुणे</div><div class="sub">रहिवासी विभागाकडे नोंदवलेले</div></div>
      <?php if (!$guests): ?><div class="mr-empty">पाहुण्यांची नोंद नाही.</div><?php else: ?>
      <div style="padding:6px 18px 12px;">
        <?php foreach ($guests as $g): ?>
          <div class="mr-person"><div class="av" style="background:#EEF2FF;color:#4338CA;"><?= h(mb_substr($g['guest_name'], 0, 1)) ?></div>
            <div style="flex:1;min-width:0;"><b><?= h($g['guest_name']) ?></b><small><?= h(trim(($g['relation'] ?: '') . ($g['purpose'] ? ' · ' . $g['purpose'] : ''), ' ·')) ?></small></div>
            <div style="text-align:right;"><span class="status-chip <?= $g['status'] === 'checked_in' ? 'pending' : 'done' ?>"><?= $g['status'] === 'checked_in' ? 'सध्या आहेत' : 'गेले' ?></span>
              <small style="display:block;color:var(--text-dim);font-size:11px;"><?= date('d M', strtotime($g['arrival_date'])) ?><?= $g['actual_departure_date'] ? ' → ' . date('d M', strtotime($g['actual_departure_date'])) : ($g['expected_departure_date'] ? ' → ~' . date('d M', strtotime($g['expected_departure_date'])) : '') ?></small></div>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($requests): ?>
  <!-- ---------- 📝 माझ्या निवास-विनंत्या ---------- -->
  <div class="panel mb-3">
    <div class="panel-head"><div class="mr-sec">📝 माझ्या निवास-विनंत्या</div></div>
    <div style="overflow-x:auto;"><table class="rtable">
      <thead><tr><th>तारीख</th><th>पसंतीची इमारत</th><th>कुटुंब</th><th>स्थिती</th><th>दिलेली Room / शेरा</th></tr></thead>
      <tbody><?php foreach ($requests as $r): $rl = $reqLabels[$r['status']] ?? [$r['status'], 'pending']; ?>
        <tr><td class="font-mono" style="white-space:nowrap;"><?= date('d M Y', strtotime($r['created_at'])) ?></td>
          <td><?= h((string)$r['pref_building']) ?: '—' ?></td><td><?= (int)$r['family_size'] ?> जण</td>
          <td><span class="status-chip <?= $rl[1] ?>"><?= $rl[0] ?></span></td>
          <td style="font-size:12.5px;"><?= $r['room_name'] ? '<b>' . h($roomLbl($r['building_name'], $r['room_name'])) . '</b>' : '' ?><?= $r['remarks'] ? ' <span style="color:var(--text-dim);">' . h($r['remarks']) . '</span>' : '' ?></td></tr>
      <?php endforeach; ?></tbody>
    </table></div>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
