<?php
// 🎓 E-Campus Dashboard — संपूर्ण Campus चं centralized चित्र (Management / Super Admin)
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireMigratedTable($pdo, 'ecampus_progress', '../');
require_once __DIR__ . '/../includes/ecampus-helpers.php';
require_once __DIR__ . '/../includes/daily-report.php'; // drDonut / drBars
$me = currentUser();
$orgId = activeOrgId();
if (!ecampusCanViewAll()) { header('Location: my.php'); exit; }

$period = ecampusPeriod($_GET['month'] ?? null);
$rows = ecampusRows($pdo, $orgId, $period);
$S = ecampusSummary($rows, $period);
$trend = ecampusDailyTrend($pdo, $orgId, $period, $rows);
$prevPeriod = date('Y-m', strtotime($period . '-01 -1 month'));
$prevS = ecampusSummary(ecampusRows($pdo, $orgId, $prevPeriod), $prevPeriod);
$isCurrent = $period === date('Y-m');
$months = [];
for ($i = 0; $i < 12; $i++) { $months[] = date('Y-m', strtotime(date('Y-m-01') . " -$i month")); }
$flash = $_SESSION['ec_flash'] ?? null; unset($_SESSION['ec_flash']);
$col = ['good' => '#16A34A', 'warn' => '#F59E0B', 'bad' => '#DC2626'];
$barCol = fn($p) => $p >= 100 ? '#16A34A' : ($p >= 75 ? '#65A30D' : ($p >= 50 ? '#F59E0B' : '#DC2626'));

$pageTitle = 'E-Campus Dashboard';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/smart-insights.php';
?>
<style>
  .ec-hero{display:grid;grid-template-columns:auto 1fr auto;gap:22px;align-items:center;padding:20px 24px;border-radius:18px;color:#fff;background:linear-gradient(135deg,#191C3A,#0E1024);position:relative;overflow:hidden;margin-bottom:16px;}
  .ec-hero::after{content:'';position:absolute;right:-70px;top:-90px;width:280px;height:280px;border-radius:50%;background:radial-gradient(circle,color-mix(in srgb,var(--rail) 45%,transparent),transparent 70%);}
  .ec-hero > *{position:relative;z-index:1;}
  .ec-big{--p:0;width:132px;height:132px;border-radius:50%;display:grid;place-items:center;background:conic-gradient(var(--c) calc(var(--p)*1%), rgba(255,255,255,.12) 0);animation:ecGrow 1s ease-out;}
  @keyframes ecGrow{from{transform:scale(.85);opacity:.4;}to{transform:none;opacity:1;}}
  .ec-big span{width:104px;height:104px;border-radius:50%;background:#14172F;display:grid;place-items:center;text-align:center;font-size:30px;font-weight:800;line-height:1;}
  .ec-big small{display:block;font-size:11px;font-weight:600;color:#C9CCE0;margin-top:4px;}
  .ec-hero h2{font-size:19px;margin:0 0 6px;font-weight:700;} .ec-hero p{margin:0;font-size:13px;color:#C9CCE0;line-height:1.6;}
  .ec-pace{display:inline-flex;align-items:center;gap:6px;margin-top:8px;font-size:12.5px;font-weight:700;padding:4px 12px;border-radius:99px;background:rgba(255,255,255,.1);}
  .ec-hbar{display:grid;grid-template-columns:minmax(120px,32%) 1fr 48px;gap:10px;align-items:center;font-size:13px;padding:6px 0;border-bottom:1px dashed var(--line);}
  .ec-hbar:last-child{border-bottom:0;}
  .ec-hbar .b{height:12px;border-radius:99px;background:var(--canvas);overflow:hidden;} .ec-hbar .b i{display:block;height:100%;border-radius:99px;transition:width 1s ease;}
  .ec-hbar small{color:var(--text-dim);}
  .ec-chart{display:flex;gap:18px;align-items:center;flex-wrap:wrap;padding:14px 18px;} .ec-chart svg{max-width:100%;height:auto;}
  .ec-leg div{font-size:12.5px;margin:4px 0;display:flex;align-items:center;gap:6px;} .ec-leg i{width:10px;height:10px;border-radius:3px;display:inline-block;}
  .ec-st{font-size:11px;font-weight:700;padding:2px 9px;border-radius:99px;color:#fff;white-space:nowrap;}
  .ec-mini{display:inline-block;width:70px;height:7px;border-radius:99px;background:var(--canvas);overflow:hidden;vertical-align:middle;margin-right:6px;} .ec-mini i{display:block;height:100%;}
  @media (max-width:767px){ .ec-hero{grid-template-columns:1fr;justify-items:center;text-align:center;} }
</style>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-2 flex-wrap gap-2">
  <div class="page-title mb-0">🎓 E-Campus Dashboard</div>
  <div class="d-flex gap-2 flex-wrap">
    <form method="GET"><select name="month" class="form-select-custom" onchange="this.form.submit()"><?php foreach ($months as $m): ?><option value="<?= $m ?>" <?= $m === $period ? 'selected' : '' ?>><?= h(ecampusPeriodLabel($m)) ?></option><?php endforeach; ?></select></form>
    <a href="report.php?month=<?= h($period) ?>" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:8px 14px;"><i class="fa-solid fa-table me-1"></i>मासिक अहवाल</a>
    <?php if (ecampusMyTaskCount($pdo, $me['id'])): ?><a href="my.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:8px 14px;"><i class="fa-solid fa-pen me-1"></i>माझी कामं</a><?php endif; ?>
    <?php if (ecampusCanManage()): ?><a href="manage.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:8px 14px;"><i class="fa-solid fa-user-gear me-1"></i>कामांचं वाटप</a><?php endif; ?>
  </div>
</div>
<?php if ($flash): ?><div class="alert alert-success"><?= h($flash) ?></div><?php endif; ?>

<?php if (!$rows): ?>
  <div class="panel" style="padding:40px;text-align:center;color:var(--text-dim);"><div style="font-size:38px;">🎓</div>या महिन्यासाठी E-Campus चं एकही काम नेमलेलं नाही.<?php if (ecampusCanManage()): ?><div class="mt-3"><a href="manage.php" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;">कामं नेमा / Excel आयात करा</a></div><?php endif; ?></div>
<?php else:
  $delta = ($S['overall'] !== null && $prevS['overall'] !== null) ? $S['overall'] - $prevS['overall'] : null;
  $gap = $S['overall'] !== null ? $S['overall'] - $S['expected'] : 0;
?>
  <div class="ec-hero">
    <div class="ec-big" style="--p:<?= (int)$S['overall'] ?>;--c:<?= $col[$S['level']] ?>;"><span><?= $S['overall'] === null ? '—' : $S['overall'] . '%' ?><small>पूर्ण</small></span></div>
    <div>
      <h2><?= h(ecampusPeriodLabel($period)) ?> — संपूर्ण Campus</h2>
      <p><?= (int)$S['work'] ?> कामं · <?= (int)$S['staff'] ?> कर्मचारी · <?= count($S['wings']) ?> Wings<?= $S['viewOnly'] ? ' · ' . (int)$S['viewOnly'] . ' फक्त-Access' : '' ?><br>
        <b style="color:#86EFAC;"><?= (int)$S['done'] ?> पूर्ण</b> · <b style="color:#FCD34D;"><?= (int)$S['progress'] ?> सुरू</b> · <b style="color:#FCA5A5;"><?= (int)$S['pending'] ?> सुरू नाही</b> — <?= max(0, 100 - (int)$S['overall']) ?>% काम बाकी</p>
      <?php if ($isCurrent && $S['overall'] !== null): ?>
        <div class="ec-pace"><?= $gap >= 0 ? '🚀 वेळापत्रकाच्या पुढे' : '⏳ वेळापत्रकाच्या मागे' ?> — महिना <?= (int)$S['expected'] ?>% गेला, काम <?= (int)$S['overall'] ?>%</div>
      <?php endif; ?>
    </div>
    <div style="text-align:center;">
      <?php if ($delta !== null): ?><div style="font-size:28px;font-weight:800;color:<?= $delta >= 0 ? '#86EFAC' : '#FCA5A5' ?>;"><?= $delta >= 0 ? '▲' : '▼' ?> <?= abs($delta) ?>%</div><div style="font-size:12px;color:#C9CCE0;">मागच्या महिन्यापेक्षा<br>(<?= h(ecampusPeriodLabel($prevPeriod)) ?>: <?= (int)$prevS['overall'] ?>%)</div><?php endif; ?>
    </div>
  </div>

  <?= smartTiles([
      ['label' => 'पूर्ण कामं', 'value' => (int)$S['done'] . '/' . (int)$S['work'], 'sub' => 'पूर्ण झालेली', 'tone' => 'ok'],
      ['label' => 'सुरू', 'value' => (int)$S['progress'], 'sub' => '1–99% झालेली', 'tone' => $S['progress'] ? 'warn' : 'ok'],
      ['label' => 'सुरू नाही', 'value' => (int)$S['pending'], 'sub' => '0% — लक्ष द्या', 'tone' => $S['pending'] ? 'bad' : 'ok'],
      ['label' => $isCurrent ? '7+ दिवस अद्ययावत नाही' : 'नोंद न भरलेली', 'value' => $isCurrent ? count($S['stale']) : (int)$S['notUpdated'], 'sub' => $isCurrent ? 'कर्मचाऱ्यांना आठवण करा' : 'त्या महिन्यात', 'tone' => ($isCurrent ? count($S['stale']) : $S['notUpdated']) ? 'warn' : 'ok'],
  ]) ?>

  <div class="row g-3 mb-3">
    <div class="col-lg-5">
      <div class="panel h-100">
        <div class="panel-head"><div class="title">📊 स्थिती</div><div class="sub">कामांची विभागणी</div></div>
        <div class="ec-chart">
          <?= drDonut([[(int)$S['done'], '#16A34A'], [(int)$S['progress'], '#F59E0B'], [(int)$S['pending'], '#DC2626']], ($S['overall'] ?? 0) . '%', 150) ?>
          <div class="ec-leg"><div><i style="background:#16A34A"></i>पूर्ण <b><?= (int)$S['done'] ?></b></div><div><i style="background:#F59E0B"></i>सुरू <b><?= (int)$S['progress'] ?></b></div><div><i style="background:#DC2626"></i>सुरू नाही <b><?= (int)$S['pending'] ?></b></div><?php if ($S['viewOnly']): ?><div><i style="background:#2563EB"></i>फक्त Access <b><?= (int)$S['viewOnly'] ?></b></div><?php endif; ?></div>
        </div>
      </div>
    </div>
    <div class="col-lg-7">
      <div class="panel h-100">
        <div class="panel-head"><div class="title">📈 महिनाभरातली प्रगती</div><div class="sub">दिवसागणिक एकूण % (कर्मचाऱ्यांनी भरलेल्या नोंदींवरून)</div></div>
        <div class="ec-chart" style="display:block;">
          <?php if ($trend): $keys = array_keys($trend); $vals = array_values($trend);
            // खूप दिवस असतील तर दर काही दिवसांनी लेबल
            $step = max(1, (int)ceil(count($vals) / 15)); $lbl = []; foreach ($keys as $i => $k) { $lbl[] = ($i % $step === 0 || $i === count($keys) - 1) ? date('j', strtotime($k)) : ''; } ?>
            <?= drBars($vals, $lbl, '#D9530F', 640, 170) ?>
          <?php else: ?><div style="color:var(--text-dim);font-size:13px;">अजून नोंदी नाहीत.</div><?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="row g-3 mb-3">
    <div class="col-lg-6">
      <div class="panel h-100">
        <div class="panel-head"><div class="title">🏛️ Wing नुसार</div><div class="sub">कमी % आधी</div></div>
        <div style="padding:8px 18px 14px;">
          <?php foreach ($S['wings'] as $w => $g): ?>
            <div class="ec-hbar"><div><b><?= h($w) ?></b><br><small><?= (int)$g['done'] ?>/<?= (int)$g['n'] ?> पूर्ण</small></div><div class="b"><i style="width:<?= (int)$g['avg'] ?>%;background:<?= $barCol($g['avg']) ?>;"></i></div><b style="text-align:right;"><?= (int)$g['avg'] ?>%</b></div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
    <div class="col-lg-6">
      <div class="panel h-100">
        <div class="panel-head"><div class="title">🗂️ Section नुसार</div><div class="sub">कमी % आधी</div></div>
        <div style="padding:8px 18px 14px;max-height:420px;overflow:auto;">
          <?php foreach ($S['sections'] as $sec => $g): ?>
            <div class="ec-hbar"><div><b><?= h($sec) ?></b><br><small><?= h($g['wing']) ?></small></div><div class="b"><i style="width:<?= (int)$g['avg'] ?>%;background:<?= $barCol($g['avg']) ?>;"></i></div><b style="text-align:right;"><?= (int)$g['avg'] ?>%</b></div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>

  <?php if ($S['lagging'] || ($isCurrent && $S['stale'])): ?>
  <div class="panel mb-3">
    <div class="panel-head"><div class="title">🎯 लक्ष द्या</div><div class="sub">अपूर्ण कामं — कमी % आधी</div></div>
    <div style="overflow-x:auto;">
      <table class="rtable">
        <thead><tr><th>कर्मचारी</th><th>Section</th><th>प्रगती</th><th>नोंद</th><th>शेवटचा बदल</th></tr></thead>
        <tbody>
        <?php foreach (array_slice($S['lagging'], 0, 12) as $r): ?>
          <tr>
            <td><b><?= h($r['staff_name']) ?></b><div style="font-size:12px;color:var(--text-dim);"><?= h($r['designation_show']) ?></div></td>
            <td style="font-size:12.5px;"><?= h($r['section']) ?></td>
            <td style="white-space:nowrap;"><span class="ec-mini"><i style="width:<?= $r['percent'] ?>%;background:<?= $r['status_color'] ?>;"></i></span><b><?= $r['percent'] ?>%</b></td>
            <td style="font-size:12.5px;max-width:320px;white-space:normal;"><?= h(mb_strimwidth((string)$r['notes'], 0, 120, '…')) ?: '<span style="color:var(--text-dim);">—</span>' ?></td>
            <td style="font-size:12px;<?= (!$r['updated_at'] || strtotime($r['updated_at']) < strtotime('-7 days')) && $isCurrent ? 'color:var(--rose);font-weight:700;' : '' ?>"><?= h(ecampusAgo($r['updated_at'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <div class="panel">
    <div class="panel-head"><div class="title">👥 सर्व कामं</div><div class="sub"><a href="report.php?month=<?= h($period) ?>">मासिक अहवाल (Print / PDF) →</a></div></div>
    <div style="overflow-x:auto;">
      <table class="rtable">
        <thead><tr><th>Wing / Section</th><th>कर्मचारी</th><th>काम</th><th>%</th><th>स्थिती</th><th>नोंद</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr>
            <td style="font-size:12.5px;"><b><?= h($r['wing']) ?></b><div style="color:var(--text-dim);"><?= h($r['section']) ?></div></td>
            <td style="font-size:12.5px;"><b><?= h($r['staff_name']) ?></b><div style="color:var(--text-dim);"><?= h($r['designation_show']) ?></div></td>
            <td style="font-size:12.5px;max-width:300px;white-space:normal;"><?= h(mb_strimwidth($r['task_description'], 0, 140, '…')) ?></td>
            <td style="white-space:nowrap;"><span class="ec-mini"><i style="width:<?= $r['percent'] ?>%;background:<?= $r['status_color'] ?>;"></i></span><b><?= $r['percent'] ?>%</b></td>
            <td><span class="ec-st" style="background:<?= $r['status_color'] ?>;"><?= h($r['status_label']) ?></span></td>
            <td style="font-size:12.5px;max-width:280px;white-space:normal;"><?= h(mb_strimwidth((string)$r['notes'], 0, 110, '…')) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
