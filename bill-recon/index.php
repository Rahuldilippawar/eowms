<?php
// 🧾 मासिक बिल जमा / ताळमेळ — आर्थिक वर्षाचा आढावा: दरमहा भांडार / खरेदी / लेखा बिल संख्या, फरक, डिजिटल मान्यता, फरकाची कारणं
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/bill-recon.php';
require_once __DIR__ . '/../includes/smart-insights.php';
if (!billReconCanView()) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">बिल ताळमेळ पाहण्याची परवानगी नाही.</div>'); }
$orgId = activeOrgId();
$user = currentUser();
$ready = billReconReady($pdo);

if ($ready && billReconCanAny() && $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'import_seed') {
    $n = billReconSeedImport($pdo, $orgId, (int)$user['id']);
    if ($n) logActivity('बिल ताळमेळ — कागदी तक्त्यावरून आयात', 'organization', $orgId, $n . ' महिने');
    $_SESSION['flash'] = $n ? '✓ ' . $n . ' महिन्यांचा ताळमेळ (एप्रिल ते ऑक्टोबर 2026) आयात झाला.' : '⚠️ आधीच आयात केलेलं आहे.';
    header('Location: index.php?fy=2026-27'); exit;
}

$curFy = billReconFy(date('Y-m'));
$fy = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['fy'] ?? '')) ? $_GET['fy'] : $curFy;
$fys = [$curFy];
$rows = []; $issues = [];
if ($ready) {
    $st = $pdo->prepare("SELECT DISTINCT fy FROM bill_recon_months WHERE organization_id = ?"); $st->execute([$orgId]);
    $fys = array_values(array_unique(array_merge($fys, $st->fetchAll(PDO::FETCH_COLUMN)))); rsort($fys);
    $st = $pdo->prepare("SELECT * FROM bill_recon_months WHERE organization_id = ? AND fy = ?"); $st->execute([$orgId, $fy]);
    foreach ($st->fetchAll() as $r) $rows[$r['period']] = $r;
    $st = $pdo->prepare("SELECT i.*, m.period FROM bill_recon_issues i JOIN bill_recon_months m ON m.id = i.month_id WHERE i.organization_id = ? AND m.fy = ? ORDER BY i.status = 'open' DESC, m.period DESC, i.id");
    $st->execute([$orgId, $fy]); $issues = $st->fetchAll();
}
// दाखवायचे महिने: चालू आर्थिक वर्ष असेल तर चालू महिन्यापर्यंत, नाहीतर 12 — आणि ज्यांची नोंद आहे ते
$periods = array_values(array_filter(billReconFyPeriods($fy), fn($p) => $p <= date('Y-m') || isset($rows[$p])));
$statPeriods = array_values(array_filter($periods, fn($p) => $p < date('Y-m') || isset($rows[$p])));
$depts = billReconDepts();
$openIss = array_filter($issues, fn($i) => $i['status'] === 'open');
$issByMonth = [];
foreach ($openIss as $i) $issByMonth[$i['period']][] = $i;

// सारांश
$tot = ['store' => 0, 'purchase' => 0, 'accounts' => 0]; $cnt = ['match' => 0, 'match_wait' => 0, 'diff' => 0, 'entry' => 0]; $myPending = [];
foreach ($statPeriods as $p) {
    $r = $rows[$p] ?? ['id' => 0, 'period' => $p, 'store_count' => null, 'purchase_count' => null, 'accounts_count' => null, 'store_at' => null, 'purchase_at' => null, 'accounts_at' => null];
    foreach ($tot as $k => $_) $tot[$k] += (int)$r[$k . '_count'];
    $cnt[billReconStatus($r)[0]]++;
    foreach ($depts as $k => $_) if (billReconCan($k) && !$r[$k . '_at'] && $p < date('Y-m')) $myPending[$p] = true;
}
$seedPending = $ready && billReconCanAny() && (!function_exists('orgSetting') || orgSetting($pdo, $orgId, 'bill_recon_seed_done', '') === '');
$maxC = max(1, ...array_map(fn($p) => max((int)($rows[$p]['store_count'] ?? 0), (int)($rows[$p]['purchase_count'] ?? 0), (int)($rows[$p]['accounts_count'] ?? 0)), $periods ?: ['x']));

$pageTitle = 'बिल ताळमेळ';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
  .br-act{display:flex;gap:6px;flex-wrap:wrap;align-items:center;}
  .br-act a,.br-act select{border:1px solid var(--line);background:var(--surface);color:var(--text);border-radius:10px;font-size:12.5px;font-weight:600;padding:8px 12px;text-decoration:none;}
  .br-act .pri{background:linear-gradient(90deg,#0E7490,#0891B2);color:#fff;border:0;}
  .br-seed{display:flex;gap:12px;align-items:center;flex-wrap:wrap;border:1px dashed #0891B2;background:color-mix(in srgb,#0891B2 7%,var(--surface));border-radius:14px;padding:12px 14px;margin-bottom:12px;}
  .br-grid{display:grid;gap:8px;}
  .br-row{display:grid;grid-template-columns:150px repeat(3,minmax(0,1fr)) 150px 92px;gap:10px;align-items:center;border:1px solid var(--line);border-radius:14px;background:var(--surface);padding:10px 14px;text-decoration:none;color:inherit;}
  .br-row:hover{border-color:#0891B2;}
  .br-row.hd{background:transparent;border:0;padding:0 14px;font-size:11.5px;font-weight:700;color:var(--text-dim);}
  .br-m b{font-size:14.5px;display:block;} .br-m small{font-size:11px;color:var(--text-dim);}
  .br-n{font-size:18px;font-weight:800;line-height:1.1;} .br-n small{display:block;font-size:10.5px;font-weight:600;color:var(--text-dim);}
  .br-n .ok{color:#16A34A;font-size:12px;} .br-n .no{color:#B45309;font-size:12px;}
  .br-bar{height:5px;border-radius:3px;background:var(--canvas);margin-top:4px;overflow:hidden;} .br-bar i{display:block;height:100%;}
  .br-st{font-size:11.5px;font-weight:700;padding:4px 10px;border-radius:99px;display:inline-block;}
  .t-ok{background:#DCFCE7;color:#166534;} .t-warn{background:#FEF3C7;color:#92400E;} .t-bad{background:#FEE2E2;color:#991B1B;}
  .br-diff{font-size:11.5px;color:#991B1B;font-weight:700;margin-top:3px;}
  .br-open{font-size:12.5px;font-weight:700;color:#0E7490;text-align:right;}
  .br-iss{border:1px solid var(--line);border-left:4px solid #DC2626;border-radius:12px;background:var(--surface);padding:9px 12px;margin-bottom:8px;font-size:13px;}
  .br-iss.res{border-left-color:#16A34A;opacity:.75;} .br-iss.res .rs{text-decoration:line-through;}
  @media (max-width:860px){
    .br-row{grid-template-columns:1fr 1fr 1fr;row-gap:8px;} .br-row.hd{display:none;}
    .br-m{grid-column:1 / span 2;} .br-stc{grid-column:3;grid-row:1;text-align:right;} .br-open{grid-column:1 / -1;text-align:left;}
  }
</style>

<div class="page-eyebrow">🧾 भांडार · खरेदी · लेखा</div>
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-2">
  <div class="page-title mb-0">🧾 मासिक बिल जमा / ताळमेळ</div>
  <form class="br-act" method="get">
    <select name="fy" onchange="this.form.submit()"><?php foreach ($fys as $y): ?><option value="<?= h($y) ?>" <?= $y === $fy ? 'selected' : '' ?>>आ.व. <?= h(billReconFyLabel($y)) ?></option><?php endforeach; ?></select>
    <?php if ($ready && billReconCanAny()): ?><a class="pri" href="month.php?p=<?= h(date('Y-m')) ?>">＋ <?= h(billReconMonthName(date('Y-m'))) ?></a><?php endif; ?>
    <a href="report.php?fy=<?= h($fy) ?>" target="_blank">📄 अहवाल (Print / PDF)</a>
  </form>
</div>
<?php if (!$ready): ?><div class="alert alert-warning" style="border-radius:10px;font-size:13px;">⚠️ बिल ताळमेळासाठी Database Update बाकी आहे — Super Admin ने System Update → Database Update चालवावं.</div><?php endif; ?>

<?php if ($seedPending): ?>
<form method="POST" class="br-seed" onsubmit="return confirm('कागदी तक्त्यातील एप्रिल ते ऑक्टोबर 2026 चा ताळमेळ आयात करायचा?');">
  <input type="hidden" name="action" value="import_seed">
  <span style="font-size:26px;">📥</span>
  <div style="flex:1;min-width:220px;"><b>मासिक बिल जमा / ताळमेळ तक्ता — एप्रिल ते ऑक्टोबर 2026</b><div style="font-size:12.5px;color:var(--text-dim);">कागदी तक्त्यातील 7 महिन्यांच्या संख्या, ऑक्टोबरची कारणं व सह्या (कागदी सही म्हणून). पुढचे महिने इथेच डिजिटल.</div></div>
  <button type="submit" class="btn btn-sm" style="background:#0891B2;color:#fff;border-radius:9px;font-weight:700;">आयात करा</button>
</form>
<?php endif; ?>

<?php if ($ready):
  $months = count($statPeriods);
  smartTiles([
    ['label' => '✅ 100% ताळमेळ', 'value' => $cnt['match'] . ' / ' . $months . ' महिने', 'sub' => $months ? round($cnt['match'] * 100 / $months) . '% महिने पूर्ण' : '', 'tone' => $cnt['match'] === $months && $months ? 'ok' : ''],
    ['label' => '⚠️ फरक असलेले महिने', 'value' => $cnt['diff'], 'sub' => count($openIss) . ' कारणं उघडी · ' . array_sum(array_column($openIss, 'bill_count')) . ' बिलं', 'tone' => $cnt['diff'] ? 'bad' : 'ok'],
    ['label' => '✍️ मान्यता / नोंद बाकी', 'value' => $cnt['match_wait'] + $cnt['entry'], 'sub' => $myPending ? 'तुमची: ' . implode(', ', array_map(fn($p) => billReconMonthName($p, false), array_keys($myPending))) : 'तुमचं काही बाकी नाही', 'tone' => $myPending ? 'warn' : ''],
    ['label' => '📦 भांडार बिलं', 'value' => number_format($tot['store']), 'sub' => 'आ.व. ' . billReconFyLabel($fy)],
    ['label' => '🛒 खरेदी / 📒 लेखा', 'value' => number_format($tot['purchase']) . ' / ' . number_format($tot['accounts']), 'sub' => ($tot['purchase'] - $tot['accounts']) ? 'फरक ' . abs($tot['purchase'] - $tot['accounts']) : 'खरेदी = लेखा'],
  ]);
?>
<div class="br-grid mb-3">
  <div class="br-row hd"><div>महिना</div><?php foreach ($depts as $d): ?><div><?= $d[1] ?> <?= h($d[0]) ?></div><?php endforeach; ?><div>स्थिती</div><div></div></div>
  <?php foreach (array_reverse($periods) as $p):
    $r = $rows[$p] ?? ['id' => 0, 'period' => $p, 'store_count' => null, 'purchase_count' => null, 'accounts_count' => null, 'store_at' => null, 'purchase_at' => null, 'accounts_at' => null, 'head_at' => null];
    [$sk, $sl, $tone] = billReconStatus($r); $df = billReconDiff($r); $ni = count($issByMonth[$p] ?? []); ?>
  <a class="br-row" href="month.php?p=<?= h($p) ?>">
    <div class="br-m"><b><?= h(billReconMonthName($p)) ?></b><small><?= $r['head_at'] ? '🏛️ कार्याध्यक्ष शेरा दिला' : ($ni ? '⚠️ ' . $ni . ' कारणं उघडी' : '') ?></small></div>
    <?php foreach ($depts as $k => $d): $c = $r[$k . '_count']; ?>
      <div class="br-n"><small class="d-md-none"><?= $d[1] ?> <?= h($d[0]) ?></small><?= $c === null ? '<span style="color:var(--text-dim);">—</span>' : h(number_format((int)$c)) ?> <?= $c === null ? '' : ($r[$k . '_at'] ? '<span class="ok" title="डिजिटल मान्यता">✍️</span>' : '<span class="no" title="मान्यता बाकी">○</span>') ?>
        <div class="br-bar"><i style="width:<?= $c === null ? 0 : round((int)$c * 100 / $maxC) ?>%;background:<?= ['store' => '#0891B2', 'purchase' => '#D97706', 'accounts' => '#7C3AED'][$k] ?>;"></i></div></div>
    <?php endforeach; ?>
    <div class="br-stc"><span class="br-st t-<?= $tone ?>"><?= h($sl) ?></span><?php if ($df): ?><div class="br-diff">फरक <?= h($df) ?> बिलं</div><?php endif; ?></div>
    <div class="br-open"><?= $r['id'] ? 'उघडा →' : (billReconCanAny() ? '＋ नोंद' : '') ?></div>
  </a>
  <?php endforeach; ?>
</div>

<?php if ($issues): ?>
  <h6 style="font-weight:800;margin:16px 0 8px;">⚠️ फरकाची कारणं (कारणे मिमांसा)</h6>
  <?php foreach ($issues as $i): ?>
    <div class="br-iss<?= $i['status'] === 'resolved' ? ' res' : '' ?>">
      <div class="d-flex justify-content-between flex-wrap gap-2"><b class="rs"><?= h($i['party'] ?: 'बिलं') ?> — <?= (int)$i['bill_count'] ?> बिलं</b>
        <a href="month.php?p=<?= h($i['period']) ?>" style="font-size:12px;"><?= h(billReconMonthName($i['period'])) ?> →</a></div>
      <div class="rs" style="color:var(--text-dim);"><?= h($i['reason']) ?></div>
      <div style="font-size:12px;margin-top:3px;"><?= $i['status'] === 'open' ? '📍 ' . h($depts[$i['held_at']][3] ?? '') : '✅ मिटले' . ($i['resolved_note'] ? ' — ' . h($i['resolved_note']) : '') ?></div>
    </div>
  <?php endforeach; ?>
<?php endif; ?>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
