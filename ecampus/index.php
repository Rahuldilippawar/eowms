<?php
// 🎓 E-Campus Dashboard — Excel Sheet सारखा तक्ता: Progress Notes आणि % Complete थेट भरता येतात (आपोआप जतन),
// Status आपोआप; वर एकूण सारांश, खाली Charts. Management/Super Admin ला सगळ्या ओळी, कर्मचाऱ्याला फक्त स्वतःच्या.
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireMigratedTable($pdo, 'ecampus_progress', '../');
require_once __DIR__ . '/../includes/ecampus-helpers.php';
require_once __DIR__ . '/../includes/daily-report.php'; // drDonut / drBars
$me = currentUser();
$orgId = activeOrgId();
$viewAll = ecampusCanViewAll();
$isSA = ecampusCanManage();

$period = ecampusPeriod($_GET['month'] ?? null);
$isCurrent = $period === date('Y-m');
$rows = ecampusRows($pdo, $orgId, $period, $viewAll ? null : $me['id']);
if (!$viewAll && !$rows && !ecampusMyTaskCount($pdo, $me['id'])) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">E-Campus चं कोणतंही काम तुम्हाला नेमलेलं नाही. <a href="../index.php">' . h(t('s16')) . '</a></div>'); }
$S = ecampusSummary($rows, $period);
$trend = $viewAll ? ecampusDailyTrend($pdo, $orgId, $period, $rows) : [];
$prevPeriod = date('Y-m', strtotime($period . '-01 -1 month'));
$prevS = $viewAll ? ecampusSummary(ecampusRows($pdo, $orgId, $prevPeriod), $prevPeriod) : ['overall' => null];
$months = [];
for ($i = 0; $i < 12; $i++) { $months[] = date('Y-m', strtotime(date('Y-m-01') . " -$i month")); }
$wings = array_keys($S['wings']);
sort($wings);
$col = ['good' => '#16A34A', 'warn' => '#F59E0B', 'bad' => '#DC2626'];
$barCol = fn($p) => $p >= 100 ? '#16A34A' : ($p >= 75 ? '#65A30D' : ($p >= 50 ? '#F59E0B' : '#DC2626'));
$canEdit = fn($r) => $isCurrent && $r['access_type'] === 'work' && ((int)$r['user_id'] === (int)$me['id'] || $isSA);

$pageTitle = 'E-Campus Dashboard';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
  .es-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap;margin-bottom:12px;}
  .es-title{font-size:19px;font-weight:800;letter-spacing:.01em;}
  .es-title small{display:block;font-size:12.5px;font-weight:500;color:var(--text-dim);margin-top:2px;}
  .es-strip{display:grid;grid-template-columns:auto repeat(4,minmax(110px,1fr)) 2fr;gap:10px;align-items:stretch;margin-bottom:12px;}
  .es-box{background:var(--surface,#fff);border:1px solid var(--line);border-radius:14px;padding:10px 14px;display:flex;flex-direction:column;justify-content:center;}
  .es-box .l{font-size:11.5px;color:var(--text-dim);font-weight:600;} .es-box .v{font-size:22px;font-weight:800;line-height:1.2;}
  .es-ring{--p:0;width:86px;height:86px;border-radius:50%;display:grid;place-items:center;background:conic-gradient(var(--c) calc(var(--p)*1%), var(--canvas) 0);transition:background .4s;}
  .es-ring span{width:68px;height:68px;border-radius:50%;background:var(--surface,#fff);display:grid;place-items:center;font-weight:800;font-size:18px;line-height:1;text-align:center;}
  .es-ring small{display:block;font-size:9.5px;font-weight:600;color:var(--text-dim);}
  .es-wings{display:flex;flex-wrap:wrap;gap:6px;align-content:center;}
  .es-wchip{font-size:11.5px;font-weight:700;padding:4px 9px;border-radius:99px;background:var(--canvas);border:1px solid var(--line);cursor:pointer;display:inline-flex;gap:6px;align-items:center;}
  .es-wchip i{display:inline-block;width:8px;height:8px;border-radius:50%;}
  .es-wchip.on{border-color:var(--rail);background:color-mix(in srgb,var(--rail) 10%,transparent);}
  .es-tools{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px;}
  .es-tools .form-control-custom,.es-tools .form-select-custom{max-width:220px;}
  .es-tools .sbtn{border:1px solid var(--line);background:var(--surface,#fff);border-radius:99px;font-size:12.5px;font-weight:700;padding:6px 12px;cursor:pointer;}
  .es-tools .sbtn.on{background:var(--rail);border-color:var(--rail);color:#fff;}

  /* ---------- Sheet ---------- */
  .es-wrap{overflow:auto;max-height:76vh;border:1px solid var(--line);border-radius:14px;background:var(--surface,#fff);}
  table.es{border-collapse:separate;border-spacing:0;width:100%;min-width:1000px;font-size:12.5px;}
  table.es th{position:sticky;top:0;z-index:2;background:#191C3A;color:#fff;font-weight:700;text-align:left;padding:9px 8px;font-size:12px;border-right:1px solid rgba(255,255,255,.08);white-space:nowrap;}
  table.es th small{display:block;font-weight:500;font-size:10px;color:#FDBA8C;}
  table.es td{padding:7px 8px;border-bottom:1px solid var(--line);border-right:1px solid var(--line);vertical-align:top;background:var(--surface,#fff);}
  table.es tr:hover td{background:color-mix(in srgb,var(--rail) 3%,var(--surface,#fff));}
  table.es td.sr{color:var(--text-dim);text-align:center;width:36px;}
  table.es td.wing{font-weight:700;border-left:4px solid var(--wc,#ccc);}
  table.es td.wing.rep{color:var(--text-dim);font-weight:500;}
  table.es td.task{white-space:pre-line;min-width:190px;max-width:260px;}
  table.es td.edit{background:color-mix(in srgb,#FEF3C7 45%,var(--surface,#fff));}
  table.es tr.mine td.staff b::after{content:' • मी';color:var(--rail);font-size:11px;}
  .es-note{width:100%;min-width:170px;min-height:38px;border:1px solid transparent;background:transparent;border-radius:8px;padding:5px 7px;font:inherit;resize:vertical;line-height:1.45;color:inherit;}
  .es-note:hover{border-color:var(--line);} .es-note:focus{outline:none;border-color:var(--rail);background:var(--surface,#fff);box-shadow:0 0 0 3px color-mix(in srgb,var(--rail) 15%,transparent);}
  .es-pct{display:flex;align-items:center;gap:4px;}
  .es-pct input{width:58px;text-align:center;font-weight:800;font-size:14px;border:1px solid var(--line);border-radius:8px;padding:5px 4px;background:var(--surface,#fff);color:inherit;}
  .es-pct input:focus{outline:none;border-color:var(--rail);box-shadow:0 0 0 3px color-mix(in srgb,var(--rail) 15%,transparent);}
  .es-pct button{width:26px;height:28px;border-radius:7px;border:1px solid var(--line);background:var(--canvas);font-weight:800;cursor:pointer;line-height:1;}
  .es-bar{height:6px;border-radius:99px;background:var(--canvas);overflow:hidden;margin-top:6px;} .es-bar i{display:block;height:100%;border-radius:99px;transition:width .4s,background .4s;}
  .es-ro{font-size:15px;font-weight:800;}
  .es-st{display:inline-block;font-size:11.5px;font-weight:700;padding:3px 10px;border-radius:99px;color:#fff;white-space:nowrap;transition:background .3s;}
  .es-saved{font-size:10.5px;color:var(--text-dim);margin-top:4px;min-height:14px;white-space:nowrap;}
  .es-saved.ok{color:#16A34A;} .es-saved.err{color:#DC2626;} .es-saved.busy{color:#B45309;}
  table.es tfoot td{position:sticky;bottom:0;background:#F3F1EE;font-weight:800;border-top:2px solid #191C3A;}
  html[data-theme="dark"] table.es tfoot td{background:#1B2236;}
  html[data-theme="dark"] table.es td.edit{background:color-mix(in srgb,#F59E0B 8%,var(--surface,#141B2D));}
  .es-hidden{display:none;}
  details.es-charts > summary{cursor:pointer;font-weight:700;font-size:15px;padding:10px 0;list-style:none;} details.es-charts > summary::before{content:'▸ ';color:var(--rail);} details.es-charts[open] > summary::before{content:'▾ ';}
  .ec-chart{display:flex;gap:18px;align-items:center;flex-wrap:wrap;padding:14px 18px;} .ec-chart svg{max-width:100%;height:auto;}
  .ec-hbar{display:grid;grid-template-columns:minmax(120px,32%) 1fr 48px;gap:10px;align-items:center;font-size:13px;padding:6px 0;border-bottom:1px dashed var(--line);}
  .ec-hbar .b{height:12px;border-radius:99px;background:var(--canvas);overflow:hidden;} .ec-hbar .b i{display:block;height:100%;border-radius:99px;}
  @media (max-width:1100px){ .es-strip{grid-template-columns:auto repeat(2,1fr);} .es-strip .es-wings{grid-column:1/-1;} }
  @media (max-width:600px){ .es-strip{grid-template-columns:1fr 1fr;} .es-strip .ring-box{grid-column:1/-1;align-items:center;} }
  @media print{ .es-tools,.no-print,details.es-charts{display:none !important;} .es-wrap{max-height:none;overflow:visible;border:0;} table.es th{position:static;} .es-note{border:0;resize:none;} }
</style>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="es-head">
  <div class="es-title">🎓 MONTHLY E-CAMPUS REPORT — <?= h(mb_strtoupper(activeOrgName())) ?> — <?= date('m/Y', strtotime($period . '-01')) ?>
    <small><?= $isCurrent ? ($viewAll ? 'Progress Notes आणि % Complete थेट तक्त्यात भरा — आपोआप जतन होतं, Status आपोआप बदलतो.' : 'तुमच्या कामांची Progress Notes आणि % Complete इथे भरा — आपोआप जतन होतं.') : ecampusPeriodLabel($period) . ' — मागचा महिना (फक्त पाहण्यासाठी)' ?></small></div>
  <div class="d-flex gap-2 flex-wrap no-print">
    <form method="GET"><select name="month" class="form-select-custom" onchange="this.form.submit()"><?php foreach ($months as $m): ?><option value="<?= $m ?>" <?= $m === $period ? 'selected' : '' ?>><?= h(ecampusPeriodLabel($m)) ?></option><?php endforeach; ?></select></form>
    <?php if ($viewAll): ?>
      <a href="report.php?month=<?= h($period) ?>&pdf=1&v=<?= time() ?>" target="_blank" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:8px 14px;"><i class="fa-solid fa-file-pdf me-1"></i>PDF</a>
    <?php endif; ?>
    <?php if ($isSA): ?><a href="manage.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:8px 14px;"><i class="fa-solid fa-user-gear me-1"></i>कामांचं वाटप</a><?php endif; ?>
  </div>
</div>

<?php if (!$rows): ?>
  <div class="panel" style="padding:40px;text-align:center;color:var(--text-dim);"><div style="font-size:38px;">🎓</div>या महिन्यासाठी E-Campus चं एकही काम नेमलेलं नाही.<?php if ($isSA): ?><div class="mt-3"><a href="manage.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;">कामं नेमा / Excel आयात करा</a></div><?php endif; ?></div>
<?php else: $delta = ($S['overall'] !== null && ($prevS['overall'] ?? null) !== null) ? $S['overall'] - $prevS['overall'] : null; ?>

  <!-- ---------- वरचा सारांश (तक्त्यातील बदलांबरोबर लगेच बदलतो) ---------- -->
  <div class="es-strip" id="esStrip">
    <div class="es-box ring-box" style="flex-direction:row;gap:12px;align-items:center;">
      <div class="es-ring" id="esRing" style="--p:<?= (int)$S['overall'] ?>;--c:<?= $col[$S['level']] ?>;"><span><b id="esOverall"><?= (int)$S['overall'] ?>%</b><small>पूर्ण</small></span></div>
      <div style="font-size:12px;color:var(--text-dim);line-height:1.6;"><b style="color:inherit;"><span id="esLeft"><?= max(0, 100 - (int)$S['overall']) ?></span>% बाकी</b><br>
        <?php if ($isCurrent): ?>महिना <?= (int)$S['expected'] ?>% गेला<br><span id="esPace"><?= ($S['overall'] ?? 0) >= $S['expected'] ? '🚀 वेळेच्या पुढे' : '⏳ वेळेच्या मागे' ?></span><?php endif; ?>
        <?php if ($delta !== null): ?><br><?= $delta >= 0 ? '▲' : '▼' ?> <?= abs($delta) ?>% मागच्या महिन्यापेक्षा<?php endif; ?></div>
    </div>
    <div class="es-box"><div class="l">एकूण कामं</div><div class="v" id="esTotal"><?= (int)$S['work'] ?></div><div class="l"><?= (int)$S['staff'] ?> कर्मचारी</div></div>
    <div class="es-box"><div class="l">✅ पूर्ण</div><div class="v" style="color:#16A34A;" id="esDone"><?= (int)$S['done'] ?></div></div>
    <div class="es-box"><div class="l">🔄 सुरू</div><div class="v" style="color:#B45309;" id="esProg"><?= (int)$S['progress'] ?></div></div>
    <div class="es-box"><div class="l">⛔ सुरू नाही</div><div class="v" style="color:#DC2626;" id="esPend"><?= (int)$S['pending'] ?></div></div>
    <div class="es-box"><div class="l" style="margin-bottom:6px;">Wing नुसार — Click करून फक्त तेच पाहा</div>
      <div class="es-wings"><?php foreach ($wings as $w): $g = $S['wings'][$w]; ?><span class="es-wchip" data-wing="<?= h($w) ?>"><i style="background:<?= $barCol($g['avg']) ?>;"></i><?= h($w) ?> <b data-wavg="<?= h($w) ?>"><?= (int)$g['avg'] ?>%</b></span><?php endforeach; ?></div>
    </div>
  </div>

  <div class="es-tools no-print">
    <input type="search" id="esSearch" class="form-control-custom" placeholder="🔍 नाव / Section / काम शोधा…">
    <button type="button" class="sbtn on" data-st="">सर्व</button>
    <button type="button" class="sbtn" data-st="pending">⛔ सुरू नाही</button>
    <button type="button" class="sbtn" data-st="progress">🔄 सुरू</button>
    <button type="button" class="sbtn" data-st="done">✅ पूर्ण</button>
    <?php if ($viewAll && ecampusMyTaskCount($pdo, $me['id'])): ?><button type="button" class="sbtn" data-mine="1">👤 माझी कामं</button><?php endif; ?>
    <span id="esCount" style="font-size:12.5px;color:var(--text-dim);margin-left:auto;"></span>
  </div>

  <!-- ---------- Excel सारखा तक्ता ---------- -->
  <div class="es-wrap">
    <table class="es" id="esTable" data-period="<?= h($period) ?>">
      <thead><tr>
        <th>Sr. No</th><th>Wing / Category</th><th>Section / Department</th><th>Staff Name</th><th>Designation</th><th>Assigned Task</th>
        <th>Progress Notes<small>(editable)</small></th><th>% Complete<small>(editable)</small></th><th>Status<small>(auto)</small></th>
      </tr></thead>
      <tbody>
      <?php $prevWing = null; $wingColors = ['#D9530F', '#7B3F9E', '#2563EB', '#16A34A', '#0891B2', '#B45309', '#BE185D', '#4F46E5']; $wc = [];
      foreach ($rows as $i => $r): $wc[$r['wing']] ??= $wingColors[count($wc) % count($wingColors)]; $ed = $canEdit($r); $rep = $prevWing === $r['wing']; $prevWing = $r['wing']; ?>
        <tr data-id="<?= (int)$r['id'] ?>" data-wing="<?= h($r['wing']) ?>" data-status="<?= h($r['status']) ?>" data-access="<?= h($r['access_type']) ?>" data-pct="<?= $r['percent'] ?>" data-mine="<?= (int)$r['user_id'] === (int)$me['id'] ? 1 : 0 ?>" class="<?= (int)$r['user_id'] === (int)$me['id'] ? 'mine' : '' ?>">
          <td class="sr"><?= $i + 1 ?></td>
          <td class="wing <?= $rep ? 'rep' : '' ?>" style="--wc:<?= $wc[$r['wing']] ?>;"><?= h($r['wing']) ?></td>
          <td><?= h($r['section']) ?></td>
          <td class="staff"><b><?= h($r['staff_name']) ?></b></td>
          <td><?= h($r['designation_show']) ?></td>
          <td class="task"><?= h($r['task_description']) ?></td>
          <td class="<?= $ed ? 'edit' : '' ?>">
            <?php if ($ed): ?><textarea class="es-note" rows="2" maxlength="2000" placeholder="नोंद लिहा…"><?= h($r['carried'] ? '' : (string)$r['notes']) ?></textarea>
            <?php else: ?><?= nl2br(h((string)$r['notes'])) ?: '<span style="color:var(--text-dim);">—</span>' ?><?php endif; ?>
          </td>
          <td class="<?= $ed ? 'edit' : '' ?>" style="min-width:118px;width:118px;">
            <?php if ($ed): ?>
              <div class="es-pct"><button type="button" data-step="-5" title="-5">−</button><input type="number" class="es-pval" min="0" max="100" step="1" value="<?= $r['percent'] ?>" inputmode="numeric"><button type="button" data-step="5" title="+5">+</button></div>
            <?php else: ?><div class="es-ro"><?= $r['percent'] ?>%</div><?php endif; ?>
            <div class="es-bar"><i style="width:<?= $r['percent'] ?>%;background:<?= $r['status_color'] ?>;"></i></div>
            <div class="es-saved"><?= $r['updated_at'] && !$r['carried'] ? h(ecampusAgo($r['updated_at'])) : '' ?></div>
          </td>
          <td><span class="es-st" style="background:<?= $r['status_color'] ?>;"><?= h($r['status_label']) ?></span></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td colspan="7" style="text-align:right;">एकूण प्रगती (फक्त-Access वगळून)</td><td id="esFootPct"><?= (int)$S['overall'] ?>%</td><td id="esFootSt"><?= (int)$S['done'] ?>/<?= (int)$S['work'] ?> पूर्ण</td></tr></tfoot>
    </table>
  </div>

  <?php if ($viewAll): ?>
  <details class="es-charts mt-3" open>
    <summary>📊 Charts व विश्लेषण</summary>
    <div class="row g-3 mb-3">
      <div class="col-lg-5"><div class="panel h-100"><div class="panel-head"><div class="title">स्थिती</div></div>
        <div class="ec-chart"><?= drDonut([[(int)$S['done'], '#16A34A'], [(int)$S['progress'], '#F59E0B'], [(int)$S['pending'], '#DC2626']], ($S['overall'] ?? 0) . '%', 150) ?>
          <div style="font-size:13px;line-height:1.9;"><div>🟩 पूर्ण <b><?= (int)$S['done'] ?></b></div><div>🟧 सुरू <b><?= (int)$S['progress'] ?></b></div><div>🟥 सुरू नाही <b><?= (int)$S['pending'] ?></b></div><?php if ($S['viewOnly']): ?><div>🟦 फक्त Access <b><?= (int)$S['viewOnly'] ?></b></div><?php endif; ?></div></div></div></div>
      <div class="col-lg-7"><div class="panel h-100"><div class="panel-head"><div class="title">महिनाभरातली प्रगती</div><div class="sub">दिवसागणिक एकूण %</div></div>
        <div class="ec-chart" style="display:block;"><?php if ($trend): $keys = array_keys($trend); $vals = array_values($trend); $step = max(1, (int)ceil(count($vals) / 15)); $lbl = [];
          foreach ($keys as $i => $k) { $lbl[] = ($i % $step === 0 || $i === count($keys) - 1) ? date('j', strtotime($k)) : ''; } ?><?= drBars($vals, $lbl, '#D9530F', 640, 170) ?><?php else: ?><div style="color:var(--text-dim);font-size:13px;">अजून नोंदी नाहीत.</div><?php endif; ?></div></div></div>
    </div>
    <div class="row g-3">
      <div class="col-lg-6"><div class="panel h-100"><div class="panel-head"><div class="title">🏛️ Wing नुसार</div><div class="sub">कमी % आधी</div></div><div style="padding:8px 18px 14px;">
        <?php foreach ($S['wings'] as $w => $g): ?><div class="ec-hbar"><div><b><?= h($w) ?></b><br><small style="color:var(--text-dim);"><?= (int)$g['done'] ?>/<?= (int)$g['n'] ?> पूर्ण</small></div><div class="b"><i style="width:<?= (int)$g['avg'] ?>%;background:<?= $barCol($g['avg']) ?>;"></i></div><b style="text-align:right;"><?= (int)$g['avg'] ?>%</b></div><?php endforeach; ?></div></div></div>
      <div class="col-lg-6"><div class="panel h-100"><div class="panel-head"><div class="title">🗂️ Section नुसार</div><div class="sub">कमी % आधी</div></div><div style="padding:8px 18px 14px;max-height:420px;overflow:auto;">
        <?php foreach ($S['sections'] as $sec => $g): ?><div class="ec-hbar"><div><b><?= h($sec) ?></b><br><small style="color:var(--text-dim);"><?= h($g['wing']) ?></small></div><div class="b"><i style="width:<?= (int)$g['avg'] ?>%;background:<?= $barCol($g['avg']) ?>;"></i></div><b style="text-align:right;"><?= (int)$g['avg'] ?>%</b></div><?php endforeach; ?></div></div></div>
    </div>
  </details>
  <?php endif; ?>
<?php endif; ?>

<script>
(function () {
  var table = document.getElementById('esTable'); if (!table) return;
  var period = table.dataset.period;
  var T = function (s) { return window.eowmsT ? eowmsT(s) : s; };
  var stOf = function (p) { return p >= 100 ? ['done', T('पूर्ण'), '#16A34A'] : (p > 0 ? ['progress', T('सुरू'), '#F59E0B'] : ['pending', T('सुरू नाही'), '#DC2626']); };
  var barColor = function (p) { return p >= 100 ? '#16A34A' : (p >= 75 ? '#65A30D' : (p >= 50 ? '#F59E0B' : '#DC2626')); };

  // ---- वरचा सारांश पुन्हा मोजा (तक्त्यातल्या मूल्यांवरून) ----
  function recalc() {
    var rows = [].slice.call(table.tBodies[0].rows).filter(function (r) { return r.dataset.access === 'work'; });
    var sum = 0, c = { done: 0, progress: 0, pending: 0 }, wing = {};
    rows.forEach(function (r) { var p = +r.dataset.pct; sum += p; c[r.dataset.status]++; var w = r.dataset.wing; wing[w] = wing[w] || [0, 0]; wing[w][0] += p; wing[w][1]++; });
    var o = rows.length ? Math.round(sum / rows.length) : 0;
    var ring = document.getElementById('esRing');
    ring.style.setProperty('--p', o); ring.style.setProperty('--c', o >= 85 ? '#16A34A' : (o >= 60 ? '#F59E0B' : '#DC2626'));
    document.getElementById('esOverall').textContent = o + '%';
    document.getElementById('esLeft').textContent = Math.max(0, 100 - o);
    document.getElementById('esDone').textContent = c.done; document.getElementById('esProg').textContent = c.progress; document.getElementById('esPend').textContent = c.pending;
    document.getElementById('esFootPct').textContent = o + '%';
    document.getElementById('esFootSt').textContent = c.done + '/' + rows.length + ' ' + T('पूर्ण');
    Object.keys(wing).forEach(function (w) { var el = document.querySelector('[data-wavg="' + CSS.escape(w) + '"]'); if (el) { var a = Math.round(wing[w][0] / wing[w][1]); el.textContent = a + '%'; el.previousElementSibling.style.background = barColor(a); } });
  }

  // ---- एका ओळीचं जतन (थोडं थांबून — टाइप करत असताना सतत नाही) ----
  function save(tr) {
    var inp = tr.querySelector('.es-pval'), note = tr.querySelector('.es-note'), msg = tr.querySelector('.es-saved');
    var p = Math.max(0, Math.min(100, parseInt(inp.value || '0', 10) || 0)); inp.value = p;
    msg.className = 'es-saved busy'; msg.textContent = T('जतन होत आहे…');
    fetch('save.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ task_id: +tr.dataset.id, percent: p, notes: note ? note.value : '', period: period }) })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        if (!j.ok) { throw new Error(j.msg || 'error'); }
        msg.className = 'es-saved ok'; msg.textContent = '✓ ' + T('जतन') + ' ' + j.time;
      })
      .catch(function (e) { msg.className = 'es-saved err'; msg.textContent = '✗ ' + T('जतन झालं नाही') + ' — ' + e.message; });
  }
  function apply(tr) { // % बदलताच रंग/Status/Bar लगेच
    var inp = tr.querySelector('.es-pval'); var p = Math.max(0, Math.min(100, parseInt(inp.value || '0', 10) || 0));
    var s = stOf(p); tr.dataset.pct = p; tr.dataset.status = s[0];
    var b = tr.querySelector('.es-bar i'); b.style.width = p + '%'; b.style.background = s[2];
    var st = tr.querySelector('.es-st'); st.textContent = s[1]; st.style.background = s[2];
    recalc();
  }
  var timers = new WeakMap();
  function later(tr, ms) { clearTimeout(timers.get(tr)); timers.set(tr, setTimeout(function () { save(tr); }, ms)); }
  table.addEventListener('input', function (e) {
    var tr = e.target.closest('tr'); if (!tr) return;
    if (e.target.classList.contains('es-pval')) { apply(tr); later(tr, 900); }
    if (e.target.classList.contains('es-note')) { e.target.style.height = 'auto'; e.target.style.height = e.target.scrollHeight + 'px'; later(tr, 1500); }
  });
  table.addEventListener('change', function (e) { var tr = e.target.closest('tr'); if (tr && (e.target.classList.contains('es-pval') || e.target.classList.contains('es-note'))) { clearTimeout(timers.get(tr)); save(tr); } });
  table.addEventListener('click', function (e) {
    var b = e.target.closest('[data-step]'); if (!b) return;
    var tr = b.closest('tr'), inp = tr.querySelector('.es-pval');
    inp.value = Math.max(0, Math.min(100, (parseInt(inp.value || '0', 10) || 0) + parseInt(b.dataset.step, 10))); apply(tr); later(tr, 700);
  });
  table.querySelectorAll('.es-note').forEach(function (n) { n.style.height = 'auto'; n.style.height = Math.max(38, n.scrollHeight) + 'px'; });

  // ---- शोध / स्थिती / Wing / माझी कामं — Filter ----
  var fSt = '', fWing = '', fMine = false, q = document.getElementById('esSearch');
  function filter() {
    var text = (q.value || '').toLowerCase(), shown = 0, all = table.tBodies[0].rows.length;
    [].forEach.call(table.tBodies[0].rows, function (r) {
      var ok = (!fSt || r.dataset.status === fSt) && (!fWing || r.dataset.wing === fWing) && (!fMine || r.dataset.mine === '1') && (!text || r.textContent.toLowerCase().indexOf(text) >= 0);
      r.classList.toggle('es-hidden', !ok); if (ok) shown++;
    });
    document.getElementById('esCount').textContent = shown + ' / ' + all + ' ' + T('ओळी');
  }
  q.addEventListener('input', filter);
  document.querySelectorAll('.es-tools .sbtn[data-st]').forEach(function (b) { b.addEventListener('click', function () { document.querySelectorAll('.es-tools .sbtn[data-st]').forEach(function (x) { x.classList.toggle('on', x === b); }); fSt = b.dataset.st; filter(); }); });
  var mb = document.querySelector('.es-tools .sbtn[data-mine]'); if (mb) mb.addEventListener('click', function () { fMine = !fMine; mb.classList.toggle('on', fMine); filter(); });
  document.querySelectorAll('.es-wchip').forEach(function (c) { c.addEventListener('click', function () { fWing = fWing === c.dataset.wing ? '' : c.dataset.wing; document.querySelectorAll('.es-wchip').forEach(function (x) { x.classList.toggle('on', x.dataset.wing === fWing); }); filter(); }); });
  filter();
  window.addEventListener('beforeunload', function (e) { if (document.querySelector('.es-saved.busy')) { e.preventDefault(); e.returnValue = ''; } });
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
