<?php
$pageTitle = 'वाहन व्यवस्थापन';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/vehicle-helpers.php';

$orgId = activeOrgId();

// कोणती वाहनं दिसतील — dashboard.php चाच नियम:
//   Super Admin / manage_vehicles / Management / वाहतूक (TRP) विभाग — संस्थेची सगळी वाहनं
//   चालक — फक्त स्वतःला नेमलेली वाहनं
$canManageVeh = canManageVehicles();
$vehDeptCodeStmt = $pdo->prepare("SELECT code FROM departments WHERE id = ? AND organization_id = ?");
$vehDeptCodeStmt->execute([$user['department_id'] ?? 0, $orgId]);
$isTransportStaff = ($vehDeptCodeStmt->fetch()['code'] ?? null) === 'TRP';
$seesAllVehicles = $canManageVeh || $isTransportStaff || isManagementOrAbove();
$driverFilterSql = $seesAllVehicles ? '' : ' AND v.driver_id = ?';

$stmt = $pdo->prepare("
    SELECT v.*, u.name AS driver_name, c.name AS campus_name,
      (SELECT COALESCE(SUM(distance_km),0) FROM vehicle_trips WHERE vehicle_id = v.id) AS total_km,
      (SELECT COALESCE(SUM(liters),0) FROM vehicle_fuel_log WHERE vehicle_id = v.id) AS total_liters
    FROM vehicles v
    LEFT JOIN users u ON u.id = v.driver_id
    LEFT JOIN campuses c ON c.id = v.campus_id
    WHERE v.organization_id = ?" . $driverFilterSql . "
    ORDER BY v.vehicle_number
");
$stmt->execute($seesAllVehicles ? [$orgId] : [$orgId, $user['id']]);
$vehicles = $stmt->fetchAll();

if (!$seesAllVehicles && empty($vehicles)) {
    http_response_code(403);
    die('<div style="font-family:sans-serif;padding:40px;">तुम्हाला कोणतेही वाहन नेमलेले नाही. <a href="../index.php">Dashboard</a></div>');
}

$fuelTypeLabels = ['petrol' => 'पेट्रोल', 'diesel' => 'डिझेल', 'cng' => 'सीएनजी', 'electric' => 'इलेक्ट्रिक', 'other' => 'इतर'];

$pendingReqCount = 0;
try {
    $prStmt = $pdo->prepare("SELECT COUNT(*) c FROM vehicle_correction_requests r JOIN vehicles v ON v.id = r.vehicle_id WHERE v.organization_id = ? AND r.status = 'pending'");
    $prStmt->execute([$orgId]);
    $pendingReqCount = (int)$prStmt->fetch()['c'];
} catch (PDOException $e) {}
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-1">
  <div class="page-title mb-0">🚐 वाहन व्यवस्थापन</div>
  <div class="d-flex gap-2 flex-wrap">
    <?php if (hasRole('super_admin')): ?>
    <a href="requests.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13.5px;padding:9px 18px;position:relative;">
      <i class="fa-solid fa-flag me-1"></i>सुधारणा विनंत्या
      <?php if ($pendingReqCount > 0): ?><span style="background:var(--rose);color:#fff;border-radius:20px;font-size:10.5px;padding:1px 7px;margin-left:4px;"><?= $pendingReqCount ?></span><?php endif; ?>
    </a>
    <?php endif; ?>
    <?php if ($seesAllVehicles): ?>
    <a href="analytics.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 16px;"><i class="fa-solid fa-chart-line me-1"></i>Fleet विश्लेषण</a>
    <?php endif; ?>
    <?php if ($canManageVeh): ?>
    <a href="import.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 16px;"><i class="fa-solid fa-file-import me-1"></i>Import</a>
    <a href="create.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:9px 20px;font-weight:600;"><i class="fa-solid fa-plus me-1"></i>नवीन वाहन जोडा</a>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/insights/vehicles.php'; ?>
<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<?php
// 🧠 प्रत्येक वाहनाचा आजचा "स्वभाव" — कागदपत्रं, सर्व्हिस, वापर, खर्च — Filter/शोध/क्रमासाठी
$vIdsAll = array_map(fn($v) => (int)$v['id'], $vehicles);
$monthStats = vehicleFleetStats($pdo, $vIdsAll, date('Y-m-01'), date('Y-m-d'));
$q90Stats = vehicleFleetStats($pdo, $vIdsAll, date('Y-m-d', strtotime('-89 days')), date('Y-m-d'));
$svc = vehicleServiceStatus($pdo, $vehicles);
$onTripToday = [];
if ($vIdsAll) {
    $st = $pdo->prepare("SELECT DISTINCT vehicle_id FROM vehicle_trips WHERE trip_date = CURDATE() AND vehicle_id IN (" . implode(',', array_fill(0, count($vIdsAll), '?')) . ")");
    $st->execute($vIdsAll);
    $onTripToday = array_flip(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)));
}
$cards = []; $cnt = ['all' => 0, 'attn' => 0, 'blocked' => 0, 'docsoon' => 0, 'service' => 0, 'idle' => 0, 'nodriver' => 0, 'inactive' => 0, 'today' => 0];
foreach ($vehicles as $v) {
    $vid = (int)$v['id'];
    $docs = vehicleDocs($v);
    $blocked = (bool)array_filter($docs, fn($d) => $d['critical'] && $d['state'] === 'expired');
    $docExpired = count(array_filter($docs, fn($d) => $d['state'] === 'expired'));
    $docSoon = count(array_filter($docs, fn($d) => $d['state'] === 'soon'));
    $nextDoc = null; foreach ($docs as $d) { if ($nextDoc === null || $d['days'] < $nextDoc) $nextDoc = $d['days']; }
    $sv = $svc[$vid] ?? null;
    $ms = $monthStats[$vid]; $q9 = $q90Stats[$vid];
    $idleDays = $ms['last_trip'] ? (int)floor((strtotime(date('Y-m-d')) - strtotime($ms['last_trip'])) / 86400) : null;
    $tags = [];
    if (!$v['is_active']) { $tags[] = 'inactive'; }
    else {
        if ($blocked) $tags[] = 'blocked';
        if ($docSoon || ($docExpired && !$blocked)) $tags[] = 'docsoon';
        if ($sv && $sv['state'] !== 'ok') $tags[] = 'service';
        if ($idleDays === null || $idleDays >= 15) $tags[] = 'idle';
        if (!$v['driver_id']) $tags[] = 'nodriver';
        if (isset($onTripToday[$vid])) $tags[] = 'today';
        if (array_intersect($tags, ['blocked', 'docsoon', 'service'])) $tags[] = 'attn';
    }
    $cnt['all']++; foreach ($tags as $t) { $cnt[$t]++; }
    $cards[] = compact('v', 'docs', 'blocked', 'docExpired', 'docSoon', 'nextDoc', 'sv', 'ms', 'q9', 'idleDays', 'tags');
}
$chipDefs = [
    'all' => ['सगळी', ''], 'attn' => ['⚠️ लक्ष हवं', 'warn'], 'blocked' => ['🚫 चालवू नये', 'bad'], 'docsoon' => ['📄 कागदपत्र मुदत', 'warn'],
    'service' => ['🔧 सर्व्हिस बाकी', 'warn'], 'today' => ['🛣️ आज प्रवासात', 'ok'], 'idle' => ['💤 15+ दिवस वापर नाही', ''],
    'nodriver' => ['👤 चालक नाही', ''], 'inactive' => ['⏸ निष्क्रिय', ''],
];
?>

<div class="vh-bar no-print">
  <div class="vh-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" id="vhQ" placeholder="वाहन क्रमांक / प्रकार / मॉडेल / चालक / Campus शोधा" autocomplete="off"></div>
  <select id="vhSort" class="form-control-custom" style="width:auto;">
    <option value="num">क्रम: वाहन क्रमांक</option>
    <option value="attn">आधी लक्ष हवं असलेली</option>
    <option value="doc">कागदपत्र मुदत — जवळची आधी</option>
    <option value="km">या महिन्याचे km — जास्त आधी</option>
    <option value="costkm">खर्च / km — जास्त आधी</option>
    <option value="idle">सर्वात जास्त दिवस वापर नाही</option>
  </select>
  <div class="vh-view"><button type="button" data-v="grid" title="Cards"><i class="fa-solid fa-grip"></i></button><button type="button" data-v="list" title="यादी"><i class="fa-solid fa-list"></i></button></div>
</div>
<div class="vh-chips no-print">
  <?php foreach ($chipDefs as $k => [$lbl, $tone]): if ($k !== 'all' && !$cnt[$k]) continue; ?>
    <button type="button" class="vh-chip <?= $tone ?> <?= $k === 'all' ? 'on' : '' ?>" data-f="<?= $k ?>"><?= h($lbl) ?> <b><?= $cnt[$k] ?></b></button>
  <?php endforeach; ?>
</div>

<?php if (!$vehicles): ?>
  <div class="panel" style="padding:30px;text-align:center;color:var(--text-dim);">कोणतेही वाहन अजून जोडलेले नाही</div>
<?php endif; ?>
<div class="vh-grid" id="vhGrid">
<?php foreach ($cards as $c): $v = $c['v']; $ms = $c['ms']; $q9 = $c['q9']; $sv = $c['sv'];
  $searchText = mb_strtolower(implode(' ', [$v['vehicle_number'], str_replace(' ', '', $v['vehicle_number']), $v['vehicle_type'], $v['model'], $v['driver_name'], $v['campus_name']]));
  $attnScore = ($c['blocked'] ? 100 : 0) + $c['docExpired'] * 10 + ($sv && $sv['state'] === 'overdue' ? 20 : ($sv && $sv['state'] === 'soon' ? 8 : 0)) + $c['docSoon'] * 3;
?>
  <div class="vh-card <?= $c['blocked'] ? 'blocked' : '' ?> <?= !$v['is_active'] ? 'off' : '' ?>" data-tags="all <?= implode(' ', $c['tags']) ?>" data-q="<?= h($searchText) ?>"
       data-num="<?= h($v['vehicle_number']) ?>" data-attn="<?= $attnScore ?>" data-doc="<?= $c['nextDoc'] ?? 99999 ?>" data-km="<?= (float)$ms['km'] ?>" data-costkm="<?= (float)($q9['cost_km'] ?? 0) ?>" data-idle="<?= $c['idleDays'] ?? 99999 ?>"
       onclick="window.location='dashboard.php?id=<?= (int)$v['id'] ?>'">
    <div class="vh-head">
      <div>
        <div class="vh-num"><?= h($v['vehicle_number']) ?></div>
        <div class="vh-sub"><?= h(trim(($v['vehicle_type'] ?? '') . ($v['model'] ? ' · ' . $v['model'] : '')) ?: '—') ?> · <?= h($fuelTypeLabels[$v['fuel_type']] ?? $v['fuel_type']) ?><?= $v['campus_name'] ? ' · ' . h($v['campus_name']) : '' ?></div>
      </div>
      <div class="vh-state">
        <?php if (!$v['is_active']): ?><span class="status-chip overdue">निष्क्रिय</span>
        <?php elseif ($c['blocked']): ?><span class="status-chip overdue" title="विमा / PUC / फिटनेस / परमिट यापैकी मुदत संपली">🚫 चालवू नये</span>
        <?php elseif (in_array('today', $c['tags'], true)): ?><span class="status-chip done">🛣️ आज प्रवासात</span>
        <?php else: ?><span class="status-chip done">✅ तयार</span><?php endif; ?>
      </div>
    </div>
    <div class="vh-driver"><i class="fa-solid fa-id-card"></i> <?= $v['driver_name'] ? h($v['driver_name']) : '<span style="color:var(--amber);">चालक नेमलेला नाही</span>' ?></div>
    <div class="vh-docs">
      <?php if (!$c['docs']): ?><span class="vh-doc none">कागदपत्रांच्या तारखा भरलेल्या नाहीत</span><?php endif; ?>
      <?php foreach ($c['docs'] as $d): ?>
        <span class="vh-doc <?= $d['state'] ?>" title="<?= h($d['label'] . ' — ' . date('d M Y', strtotime($d['date']))) ?>"><?= h($d['label']) ?> <?= $d['state'] === 'expired' ? '✕' : ($d['state'] === 'soon' ? $d['days'] . 'दि' : '✓') ?></span>
      <?php endforeach; ?>
    </div>
    <div class="vh-stats">
      <div><span>या महिन्यात</span><b><?= number_format($ms['km']) ?> km</b><small><?= $ms['trips'] ?> ट्रिप · <?= $ms['days_used'] ?> दिवस</small></div>
      <div><span>मायलेज (90 दि.)</span><b><?= $q9['kmpl'] ? $q9['kmpl'] . ' km/l' : '—' ?></b><small>Meter <?= number_format((float)$v['current_odometer']) ?></small></div>
      <div><span>खर्च / km (90 दि.)</span><b><?= $q9['cost_km'] !== null ? '₹' . number_format($q9['cost_km'], 2) : '—' ?></b><small>₹<?= number_format($q9['cost']) ?> एकूण</small></div>
    </div>
    <div class="vh-foot">
      <span class="<?= $sv ? 'svc-' . $sv['state'] : '' ?>"><i class="fa-solid fa-wrench"></i> <?= $sv ? 'सर्व्हिस: ' . h($sv['text']) : 'पुढची सर्व्हिस ठरलेली नाही' ?></span>
      <span><i class="fa-solid fa-route"></i> <?= $c['idleDays'] === null ? 'अजून ट्रिप नाही' : ($c['idleDays'] === 0 ? 'आज ट्रिप' : 'शेवटची ट्रिप ' . $c['idleDays'] . ' दिवसांपूर्वी') ?></span>
      <?php if (hasRole('super_admin')): ?>
      <span class="vh-act" onclick="event.stopPropagation()">
        <a href="edit.php?id=<?= (int)$v['id'] ?>" title="Edit"><i class="fa-solid fa-pen"></i></a>
        <a href="#" data-bs-toggle="modal" data-bs-target="#deleteModal<?= (int)$v['id'] ?>" title="Delete" style="color:var(--rose);"><i class="fa-solid fa-trash"></i></a>
      </span>
      <?php endif; ?>
    </div>
  </div>
<?php endforeach; ?>
</div>
<div id="vhNone" class="panel" style="display:none;padding:24px;text-align:center;color:var(--text-dim);">या Filter / शोधात एकही वाहन नाही</div>

<?php if (hasRole('super_admin')): foreach ($vehicles as $v): ?>
        <div class="modal fade" id="deleteModal<?= $v['id'] ?>" tabindex="-1">
          <div class="modal-dialog">
            <div class="modal-content" style="border-radius:14px;">
              <form method="POST" action="delete.php">
                <input type="hidden" name="id" value="<?= $v['id'] ?>">
                <div class="modal-body" style="padding:22px;">
                  <div style="font-family:'Space Grotesk';font-weight:600;font-size:16px;color:var(--rose);margin-bottom:10px;">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i>'<?= h($v['vehicle_number']) ?>' कायमचे डिलीट करायचे?
                  </div>
                  <p style="font-size:13px;color:var(--text-dim);">
                    यामुळे या वाहनाच्या सर्व <strong>ट्रिप, इंधन आणि देखभाल नोंदी</strong> कायमच्या डिलीट होतील. ही क्रिया परत करता येणार नाही.
                  </p>
                  <label class="form-label-custom">पुष्टीसाठी वाहन क्रमांक टाइप करा: <span class="font-mono" style="color:var(--rose);"><?= h($v['vehicle_number']) ?></span></label>
                  <input type="text" name="confirm_number" class="form-control-custom" required>
                </div>
                <div class="modal-footer" style="border-top:1px solid var(--line);">
                  <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
                  <button type="submit" class="btn btn-sm" style="background:var(--rose);color:#fff;border-radius:7px;">कायमचे डिलीट करा</button>
                </div>
              </form>
            </div>
          </div>
        </div>
<?php endforeach; endif; ?>

<style>
  .vh-bar{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:10px;}
  .vh-search{flex:1;min-width:220px;position:relative;} .vh-search i{position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-dim);font-size:13px;}
  .vh-search input{width:100%;border:1px solid var(--line);border-radius:10px;padding:9px 12px 9px 34px;font-size:13.5px;background:var(--surface);color:var(--text);}
  .vh-view{display:flex;border:1px solid var(--line);border-radius:9px;overflow:hidden;} .vh-view button{border:0;background:var(--surface);padding:8px 12px;color:var(--text-dim);} .vh-view button.on{background:var(--rail);color:#fff;}
  .vh-chips{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;}
  .vh-chip{border:1px solid var(--line);background:var(--surface);border-radius:20px;padding:5px 12px;font-size:12.5px;font-weight:600;color:var(--text);}
  .vh-chip b{font-family:'JetBrains Mono',monospace;font-size:11.5px;margin-left:3px;color:var(--text-dim);} .vh-chip.on{background:var(--ink,#10162B);color:#fff;border-color:transparent;} .vh-chip.on b{color:#fff;}
  .vh-chip.bad b{color:var(--rose);} .vh-chip.warn b{color:var(--amber);} .vh-chip.on.bad b,.vh-chip.on.warn b{color:#fff;}
  .vh-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));gap:12px;}
  .vh-card{background:var(--surface);border:1px solid var(--line);border-radius:14px;padding:14px 16px;cursor:pointer;transition:box-shadow .15s,transform .15s;display:flex;flex-direction:column;gap:9px;}
  .vh-card:hover{box-shadow:0 6px 18px rgba(16,22,43,.08);transform:translateY(-1px);}
  .vh-card.blocked{border-color:var(--rose);box-shadow:inset 3px 0 0 var(--rose);} .vh-card.off{opacity:.62;}
  .vh-head{display:flex;justify-content:space-between;gap:8px;align-items:flex-start;}
  .vh-num{font-family:'JetBrains Mono',monospace;font-weight:700;font-size:15.5px;letter-spacing:.02em;} .vh-sub{font-size:11.5px;color:var(--text-dim);margin-top:1px;}
  .vh-driver{font-size:12.5px;} .vh-driver i{color:var(--text-dim);margin-right:3px;}
  .vh-docs{display:flex;flex-wrap:wrap;gap:4px;} .vh-doc{font-size:10.5px;font-weight:700;border-radius:6px;padding:2px 7px;background:#ECFDF5;color:#047857;}
  .vh-doc.soon{background:#FEF3C7;color:#B45309;} .vh-doc.expired{background:#FEE2E2;color:#B91C1C;} .vh-doc.none{background:var(--canvas);color:var(--text-dim);font-weight:600;}
  .vh-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:6px;background:var(--canvas);border-radius:10px;padding:8px 10px;}
  .vh-stats span{display:block;font-size:10.5px;color:var(--text-dim);} .vh-stats b{display:block;font-family:'JetBrains Mono',monospace;font-size:13.5px;} .vh-stats small{font-size:10.5px;color:var(--text-dim);}
  .vh-foot{display:flex;flex-wrap:wrap;gap:4px 14px;font-size:11.5px;color:var(--text-dim);align-items:center;} .vh-foot i{margin-right:3px;}
  .vh-foot .svc-overdue{color:var(--rose);font-weight:700;} .vh-foot .svc-soon{color:var(--amber);font-weight:700;}
  .vh-act{margin-left:auto;display:flex;gap:12px;} .vh-act a{color:var(--text-dim);}
  .vh-grid.list{grid-template-columns:1fr;gap:6px;} .vh-grid.list .vh-card{flex-direction:row;flex-wrap:wrap;align-items:center;padding:10px 14px;gap:6px 16px;}
  .vh-grid.list .vh-head{min-width:240px;flex:1;} .vh-grid.list .vh-stats{background:none;padding:0;min-width:330px;} .vh-grid.list .vh-docs{min-width:200px;}
  @media (max-width:600px){ .vh-grid{grid-template-columns:1fr;} .vh-grid.list .vh-stats{min-width:0;width:100%;} }
</style>
<script>
(function () {
  var grid = document.getElementById('vhGrid'); if (!grid) return;
  var cards = Array.prototype.slice.call(grid.children), q = document.getElementById('vhQ'), sort = document.getElementById('vhSort');
  var f = 'all', store = function (k, v) { try { if (v === undefined) return localStorage.getItem(k); localStorage.setItem(k, v); } catch (e) { return null; } };
  function apply() {
    var t = (q.value || '').toLowerCase().replace(/\s+/g, ''), shown = 0;
    cards.forEach(function (c) {
      var ok = (' ' + c.dataset.tags + ' ').indexOf(' ' + f + ' ') > -1 && (!t || c.dataset.q.replace(/\s+/g, '').indexOf(t) > -1);
      c.style.display = ok ? '' : 'none'; if (ok) shown++;
    });
    document.getElementById('vhNone').style.display = shown ? 'none' : '';
  }
  function doSort() {
    var k = sort.value, num = function (c, a) { return parseFloat(c.dataset[a]) || 0; };
    cards.sort(function (a, b) {
      if (k === 'attn') return num(b, 'attn') - num(a, 'attn') || a.dataset.num.localeCompare(b.dataset.num);
      if (k === 'doc') return num(a, 'doc') - num(b, 'doc');
      if (k === 'km') return num(b, 'km') - num(a, 'km');
      if (k === 'costkm') return num(b, 'costkm') - num(a, 'costkm');
      if (k === 'idle') return num(b, 'idle') - num(a, 'idle');
      return a.dataset.num.localeCompare(b.dataset.num, undefined, { numeric: true });
    });
    cards.forEach(function (c) { grid.appendChild(c); });
    store('vh_sort', k);
  }
  document.querySelectorAll('.vh-chip').forEach(function (b) {
    b.addEventListener('click', function () { document.querySelectorAll('.vh-chip').forEach(function (x) { x.classList.remove('on'); }); b.classList.add('on'); f = b.dataset.f; apply(); });
  });
  q.addEventListener('input', apply); sort.addEventListener('change', doSort);
  function view(v) { grid.classList.toggle('list', v === 'list'); document.querySelectorAll('.vh-view button').forEach(function (b) { b.classList.toggle('on', b.dataset.v === v); }); store('vh_view', v); }
  document.querySelectorAll('.vh-view button').forEach(function (b) { b.addEventListener('click', function () { view(b.dataset.v); }); });
  view(store('vh_view') || 'grid');
  var s = store('vh_sort'); if (s && sort.querySelector('option[value="' + s + '"]')) { sort.value = s; }
  doSort(); apply();
})();
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
