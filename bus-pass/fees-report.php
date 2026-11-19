<?php
// 📄 बस शुल्क — Smart अहवाल (A4 Print / PDF): सारांश, वसुली %, निरीक्षणं, वर्ग-तुकडीनिहाय, बाकीदार यादी
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/students-helpers.php';
require_once __DIR__ . '/../includes/photo-helpers.php';
require_once __DIR__ . '/../includes/student-bus-pass.php';
if (!canViewBusPass()) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">बस शुल्क अहवाल पाहण्याची परवानगी नाही.</div>'); }
if (!busStudentsReady($pdo)) { header('Location: index.php'); exit; }
$orgId = activeOrgId();
$payReady = busFeeReady($pdo);
$me = currentUser();

$org = $pdo->prepare("SELECT name, logo_path FROM organizations WHERE id = ?");
$org->execute([$orgId]);
$org = $org->fetch() ?: ['name' => '', 'logo_path' => ''];
$campusRows = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$campusRows->execute([$orgId]);
$campusRows = $campusRows->fetchAll(PDO::FETCH_KEY_PAIR);
$years = $pdo->prepare("SELECT DISTINCT academic_year FROM bus_students WHERE organization_id = ? AND academic_year IS NOT NULL AND academic_year <> '' ORDER BY academic_year DESC");
$years->execute([$orgId]);
$years = $years->fetchAll(PDO::FETCH_COLUMN);

$rf = [
    'campus' => (int)($_GET['campus'] ?? 0), 'year' => trim((string)($_GET['year'] ?? ($years[0] ?? ''))),
    'class' => trim((string)($_GET['class'] ?? '')), 'div' => trim((string)($_GET['div'] ?? '')), 'stop' => trim((string)($_GET['stop'] ?? '')),
];

$base = " FROM bus_students s WHERE s.organization_id = ? AND s.is_active = 1";
$bp = [$orgId];
if ($rf['campus']) { $base .= " AND s.campus_id = ?"; $bp[] = $rf['campus']; }
if ($rf['year'] !== '') { $base .= " AND s.academic_year = ?"; $bp[] = $rf['year']; }
if ($rf['class'] !== '' || $rf['div'] !== '') { $base .= " AND COALESCE(s.class_name,'') = ? AND COALESCE(s.division,'') = ?"; $bp[] = $rf['class']; $bp[] = $rf['div']; }
if ($rf['stop'] !== '') { $base .= " AND COALESCE(s.to_place,'') = ?"; $bp[] = $rf['stop']; }
$bal = "(COALESCE(s.total_fee,0) - COALESCE(s.paid_fee,0))";

$q = $pdo->prepare("SELECT COUNT(*) n, SUM(COALESCE(s.total_fee,0)) tot, SUM(COALESCE(s.paid_fee,0)) paid, SUM($bal > 0) pend, SUM(s.total_fee > 0 AND COALESCE(s.paid_fee,0) = 0) unpaid,
    SUM(COALESCE(s.paid_fee,0) > 0 AND $bal > 0) partial, SUM(s.total_fee IS NOT NULL AND $bal <= 0) full, SUM(s.total_fee IS NULL) nofee $base");
$q->execute($bp);
$K = $q->fetch();
$tot = (float)$K['tot']; $paid = (float)$K['paid']; $pend = max(0, $tot - $paid); $pct = $tot > 0 ? round($paid * 100 / $tot, 1) : 0;

$q = $pdo->prepare("SELECT COALESCE(s.class_name,'') cl, COALESCE(s.division,'') dv, COUNT(*) n, SUM(COALESCE(s.total_fee,0)) tot, SUM(COALESCE(s.paid_fee,0)) paid, SUM($bal > 0) pend,
    SUM(s.total_fee > 0 AND COALESCE(s.paid_fee,0) = 0) unpaid $base GROUP BY cl, dv ORDER BY cl, dv");
$q->execute($bp);
$byClass = $q->fetchAll();
$lastPay = $payReady ? "(SELECT MAX(r.paid_on) FROM bus_fee_payments r WHERE r.bus_student_id = s.id)" : "NULL";
$q = $pdo->prepare("SELECT s.name, s.admission_no, s.class_name, s.division, s.to_place, s.parent_mobile, s.mobile, s.total_fee, s.paid_fee, $bal bal, $lastPay last_pay $base AND $bal > 0 ORDER BY $bal DESC, s.class_name, s.name");
$q->execute($bp);
$pendRows = $q->fetchAll();


// 💡 निरीक्षणं
$ins = [];
if ($K['n']) {
    $ins[] = ['🎯', 'एकूण शुल्कापैकी <b>' . $pct . '%</b> वसूल — अजून <b>' . busInr($pend) . '</b> बाकी (' . (int)$K['pend'] . ' विद्यार्थी).'];
    $worst = null; foreach ($byClass as $c) { $cb = (float)$c['tot'] - (float)$c['paid']; if ($cb > 0 && (!$worst || $cb > $worst[1])) $worst = [$c, $cb]; }
    if ($worst) $ins[] = ['🏫', 'सर्वात जास्त बाकी: <b>' . htmlspecialchars(trim($worst[0]['cl'] . ' - ' . $worst[0]['dv'], ' -')) . '</b> — ' . busInr($worst[1]) . ' (' . (int)$worst[0]['pend'] . ' विद्यार्थी).'];
    $bestPct = null; foreach ($byClass as $c) { if ((float)$c['tot'] > 0) { $p = (float)$c['paid'] * 100 / (float)$c['tot']; if (!$bestPct || $p > $bestPct[1]) $bestPct = [$c, $p]; } }
    if ($bestPct) $ins[] = ['🏆', 'सर्वात चांगली वसुली: <b>' . htmlspecialchars(trim($bestPct[0]['cl'] . ' - ' . $bestPct[0]['dv'], ' -')) . '</b> — ' . round($bestPct[1]) . '%.'];
    if ((int)$K['unpaid']) $ins[] = ['⛔', '<b>' . (int)$K['unpaid'] . '</b> विद्यार्थ्यांनी अजून एकही रुपया भरलेला नाही — आधी त्यांच्याशी संपर्क करा.'];
    $big = array_filter($pendRows, fn($r) => (float)$r['bal'] >= 10000);
    if ($big) $ins[] = ['💰', '<b>' . count($big) . '</b> विद्यार्थ्यांचं ₹10,000 पेक्षा जास्त बाकी — एकूण ' . busInr(array_sum(array_column($big, 'bal'))) . '.'];
    if ((int)$K['nofee']) $ins[] = ['⚠️', (int)$K['nofee'] . ' विद्यार्थ्यांचं एकूण शुल्क नोंदलेलं नाही — ते आकड्यांत धरलेले नाहीत.'];
}
$filterTxt = implode(' · ', array_filter([
    $rf['campus'] ? ($campusRows[$rf['campus']] ?? '') : 'सर्व College', $rf['year'] !== '' ? 'वर्ष ' . $rf['year'] : '',
    $rf['class'] !== '' ? 'वर्ग ' . trim($rf['class'] . ' - ' . $rf['div'], ' -') : '', $rf['stop'] !== '' ? 'थांबा ' . $rf['stop'] : '',
]));
$daysSince = fn($dt) => $dt ? (int)floor((time() - strtotime($dt)) / 86400) : null;
$pctBar = fn($p, $t) => $t > 0 ? min(100, round($p * 100 / $t)) : 0;
$h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>बस शुल्क अहवाल — <?= $h(date('d-m-Y')) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Devanagari:wght@400;600;700;800&family=Inter:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box;}
  body{margin:0;background:#E9E7F0;font-family:'Inter','Noto Sans Devanagari',sans-serif;color:#1A1D29;font-size:12px;}
  .bar{position:sticky;top:0;z-index:5;background:#191C3A;color:#fff;padding:10px 14px;display:flex;gap:8px;flex-wrap:wrap;align-items:center;font-size:12.5px;}
  .bar a,.bar button{color:#fff;text-decoration:none;border:0;border-radius:8px;padding:7px 12px;font:inherit;font-weight:600;cursor:pointer;background:rgba(255,255,255,.1);}
  .bar .print{background:linear-gradient(90deg,#16A34A,#0E8C7A);margin-left:auto;}
  .bar select,.bar input{border:0;border-radius:8px;padding:6px 8px;font:inherit;font-size:12px;}
  .bar label{display:inline-flex;align-items:center;gap:4px;color:#C9CCE0;}
  .sheet{width:210mm;min-height:297mm;margin:16px auto;background:#fff;padding:12mm 12mm 14mm;box-shadow:0 10px 40px -12px rgba(0,0,0,.35);}
  .hd{display:flex;align-items:center;gap:12px;border-bottom:3px solid #3769AC;padding-bottom:10px;}
  .hd img{width:58px;height:58px;object-fit:contain;}
  .hd .t{flex:1;} .hd .org{font-size:16px;font-weight:800;} .hd .ti{font-size:20px;font-weight:800;color:#3769AC;margin-top:2px;}
  .hd .meta{text-align:right;font-size:11px;color:#555;line-height:1.6;}
  .flt{margin:8px 0 12px;font-size:11.5px;color:#444;background:#F4F6FB;border-radius:8px;padding:6px 10px;}
  .kpi{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;}
  .kpi div{border:1px solid #E3E7F0;border-radius:10px;padding:8px 10px;} .kpi span{display:block;font-size:10.5px;color:#666;font-weight:600;} .kpi b{font-size:17px;}
  .kpi .g b{color:#15803D;} .kpi .r b{color:#B91C1C;}
  .row2{display:grid;grid-template-columns:150px 1fr;gap:14px;margin-top:12px;align-items:center;}
  .seg{display:flex;height:12px;border-radius:99px;overflow:hidden;background:#EEF0F4;margin:6px 0 4px;} .seg i{display:block;height:100%;}
  .lg{display:flex;gap:12px;flex-wrap:wrap;font-size:11px;} .lg em{display:inline-block;width:9px;height:9px;border-radius:2px;margin-right:4px;vertical-align:-1px;}
  h2{font-size:14px;margin:18px 0 6px;padding-bottom:4px;border-bottom:1px solid #E3E7F0;display:flex;justify-content:space-between;align-items:baseline;} h2 small{font-size:11px;color:#666;font-weight:600;}
  table{width:100%;border-collapse:collapse;font-size:11.2px;} th{background:#F1F4FA;text-align:left;font-size:10.5px;color:#444;padding:5px 6px;border-bottom:1px solid #D9DFEA;}
  td{padding:4.5px 6px;border-bottom:1px solid #EEF0F4;vertical-align:middle;} td.n,th.n{text-align:right;white-space:nowrap;} tr.tot td{font-weight:800;background:#F8FAFD;border-top:2px solid #D9DFEA;}
  .pb{display:inline-block;width:70px;height:7px;border-radius:99px;background:#FEE2E2;overflow:hidden;vertical-align:middle;margin-right:5px;} .pb i{display:block;height:100%;background:#16A34A;}
  .red{color:#B91C1C;font-weight:700;} .grn{color:#15803D;font-weight:700;} .dim{color:#777;}
  .ins{display:grid;grid-template-columns:1fr 1fr;gap:6px 14px;} .ins div{display:flex;gap:7px;font-size:11.5px;line-height:1.5;background:#FAFBFE;border:1px solid #EEF1F7;border-radius:8px;padding:6px 8px;}
  .tag{font-size:10px;font-weight:700;padding:1px 6px;border-radius:99px;white-space:nowrap;} .tag.u{background:#FEE2E2;color:#991B1B;} .tag.p{background:#FEF3C7;color:#92400E;}
  .sig{display:flex;justify-content:space-between;margin-top:34px;font-size:11.5px;} .sig div{width:30%;border-top:1px solid #333;text-align:center;padding-top:4px;}
  .foot{margin-top:14px;font-size:10px;color:#888;text-align:center;}
  .pgb{break-before:page;page-break-before:always;}
  @media print{ body{background:#fff;} .bar{display:none;} .sheet{margin:0;box-shadow:none;width:auto;min-height:0;padding:0;} @page{size:A4;margin:10mm;} tr{break-inside:avoid;} .off{display:none !important;} }
  @media screen and (max-width:840px){ .sheet{width:auto;margin:8px;padding:12px;} .kpi{grid-template-columns:1fr 1fr;} .ins{grid-template-columns:1fr;} .row2{grid-template-columns:1fr;} }
  .off{opacity:.35;}
</style>
</head>
<body>
<form class="bar" method="get">
  <a href="fees.php">← मागे</a>
  <select name="campus"><option value="">सर्व College</option><?php foreach ($campusRows as $cid => $cn): ?><option value="<?= (int)$cid ?>" <?= $rf['campus'] === (int)$cid ? 'selected' : '' ?>><?= $h($cn) ?></option><?php endforeach; ?></select>
  <select name="year"><option value="">सर्व वर्षे</option><?php foreach ($years as $y): ?><option <?= $rf['year'] === $y ? 'selected' : '' ?>><?= $h($y) ?></option><?php endforeach; ?></select>
  <?php if ($rf['class'] !== '' || $rf['div'] !== ''): ?><input type="hidden" name="class" value="<?= $h($rf['class']) ?>"><input type="hidden" name="div" value="<?= $h($rf['div']) ?>"><?php endif; ?>
  <?php if ($rf['stop'] !== ''): ?><input type="hidden" name="stop" value="<?= $h($rf['stop']) ?>"><?php endif; ?>
  <button type="submit">लागू करा</button>
  <span style="color:#C9CCE0;">भाग:</span>
  <?php foreach (['ins' => 'निरीक्षणं', 'cls' => 'वर्गनिहाय', 'pnd' => 'बाकीदार'] as $sk => $sl): ?><label><input type="checkbox" data-sec="<?= $sk ?>" checked> <?= $sl ?></label><?php endforeach; ?>
  <button type="button" class="print" onclick="window.print()">🖨️ Print / PDF</button>
</form>

<div class="sheet">
  <div class="hd">
    <?php if (!empty($org['logo_path']) && eowmsPhotoExists($org['logo_path'])): ?><img src="../<?= $h($org['logo_path']) ?>" alt=""><?php endif; ?>
    <div class="t"><div class="org"><?= $h($org['name']) ?></div><div class="ti">🚌 बस शुल्क अहवाल</div></div>
    <div class="meta">दिनांक: <b><?= date('d/m/Y h:i A') ?></b><br>तयार केला: <?= $h($me['name'] ?? '') ?></div>
  </div>
  <div class="flt">🔎 <?= $h($filterTxt) ?></div>

  <div class="kpi">
    <div><span>विद्यार्थी</span><b><?= (int)$K['n'] ?></b></div>
    <div><span>एकूण शुल्क</span><b><?= $h(busInr($tot)) ?></b></div>
    <div class="g"><span>जमा (<?= $pct ?>%)</span><b><?= $h(busInr($paid)) ?></b></div>
    <div class="r"><span>बाकी</span><b><?= $h(busInr($pend)) ?></b></div>
  </div>
  <div class="row2">
    <svg viewBox="0 0 120 120" width="140" height="140" aria-hidden="true">
      <circle cx="60" cy="60" r="48" fill="none" stroke="#FEE2E2" stroke-width="16"/>
      <circle cx="60" cy="60" r="48" fill="none" stroke="#16A34A" stroke-width="16" stroke-dasharray="<?= round(301.6 * $pct / 100, 1) ?> 301.6" transform="rotate(-90 60 60)"/>
      <text x="60" y="58" text-anchor="middle" font-size="20" font-weight="800" fill="#1A1D29"><?= $pct ?>%</text><text x="60" y="76" text-anchor="middle" font-size="9" fill="#666">वसुली</text>
    </svg>
    <div>
      <?php $nAll = max(1, (int)$K['full'] + (int)$K['partial'] + (int)$K['unpaid']); ?>
      <b>विद्यार्थ्यांची स्थिती</b>
      <div class="seg"><i style="width:<?= round($K['full'] * 100 / $nAll, 2) ?>%;background:#16A34A;"></i><i style="width:<?= round($K['partial'] * 100 / $nAll, 2) ?>%;background:#F59E0B;"></i><i style="width:<?= round($K['unpaid'] * 100 / $nAll, 2) ?>%;background:#EF4444;"></i></div>
      <div class="lg"><span><em style="background:#16A34A;"></em>पूर्ण भरलं <b><?= (int)$K['full'] ?></b></span><span><em style="background:#F59E0B;"></em>अर्धवट <b><?= (int)$K['partial'] ?></b></span><span><em style="background:#EF4444;"></em>काहीच नाही <b><?= (int)$K['unpaid'] ?></b></span><?php if ((int)$K['nofee']): ?><span class="dim">शुल्क नोंद नाही <?= (int)$K['nofee'] ?></span><?php endif; ?></div>
    </div>
  </div>

  <section data-s="ins"><?php if ($ins): ?><h2>💡 निरीक्षणं</h2><div class="ins"><?php foreach ($ins as [$ic, $tx]): ?><div><span><?= $ic ?></span><span><?= $tx ?></span></div><?php endforeach; ?></div><?php endif; ?></section>

  <section data-s="cls">
    <h2>🏫 वर्ग-तुकडीनिहाय <small><?= count($byClass) ?> वर्ग</small></h2>
    <table><thead><tr><th>वर्ग - तुकडी</th><th class="n">विद्यार्थी</th><th class="n">एकूण</th><th class="n">जमा</th><th class="n">बाकी</th><th class="n">बाकीदार</th><th>वसुली</th></tr></thead><tbody>
    <?php foreach ($byClass as $c): $cb = (float)$c['tot'] - (float)$c['paid']; $cp = $pctBar($c['paid'], $c['tot']); ?>
      <tr><td><b><?= $h(trim($c['cl'] . ' - ' . $c['dv'], ' -') ?: '—') ?></b></td><td class="n"><?= (int)$c['n'] ?></td><td class="n"><?= $h(busInr($c['tot'])) ?></td><td class="n grn"><?= $h(busInr($c['paid'])) ?></td><td class="n <?= $cb > 0 ? 'red' : '' ?>"><?= $h(busInr(max(0, $cb))) ?></td><td class="n"><?= (int)$c['pend'] ?><?= (int)$c['unpaid'] ? ' <span class="dim">(' . (int)$c['unpaid'] . ' शून्य)</span>' : '' ?></td><td><span class="pb"><i style="width:<?= $cp ?>%;"></i></span><?= $cp ?>%</td></tr>
    <?php endforeach; ?>
      <tr class="tot"><td>एकूण</td><td class="n"><?= (int)$K['n'] ?></td><td class="n"><?= $h(busInr($tot)) ?></td><td class="n"><?= $h(busInr($paid)) ?></td><td class="n"><?= $h(busInr($pend)) ?></td><td class="n"><?= (int)$K['pend'] ?></td><td><?= $pct ?>%</td></tr>
    </tbody></table>
  </section>


  <section data-s="pnd" class="pgb">
    <h2>⏳ बाकीदार यादी <small><?= count($pendRows) ?> विद्यार्थी · <?= $h(busInr($pend)) ?> · सर्वात जास्त बाकी वर</small></h2>
    <table><thead><tr><th>#</th><th>विद्यार्थी</th><th>Reg No</th><th>वर्ग</th><th>थांबा</th><th>पालक मोबाईल</th><th class="n">एकूण</th><th class="n">जमा</th><th class="n">बाकी</th><th>शेवटची जमा</th></tr></thead><tbody>
    <?php foreach ($pendRows as $i => $r): $ds = $daysSince($r['last_pay']); ?>
      <tr><td class="dim"><?= $i + 1 ?></td><td><b><?= $h($r['name']) ?></b> <?= (float)$r['paid_fee'] <= 0 ? '<span class="tag u">काहीच नाही</span>' : '<span class="tag p">अर्धवट</span>' ?></td><td><?= $h($r['admission_no']) ?></td>
        <td><?= $h(trim(($r['class_name'] ?? '') . ' - ' . ($r['division'] ?? ''), ' -')) ?></td><td><?= $h($r['to_place']) ?></td><td><?= $h($r['parent_mobile'] ?: $r['mobile']) ?></td>
        <td class="n"><?= $h(busInr($r['total_fee'])) ?></td><td class="n grn"><?= $h(busInr($r['paid_fee'])) ?></td><td class="n red"><?= $h(busInr($r['bal'])) ?></td>
        <td class="dim"><?= $r['last_pay'] ? date('d/m/Y', strtotime($r['last_pay'])) . ' (' . $ds . ' दि.)' : '—' ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$pendRows): ?><tr><td colspan="10" class="grn">🎉 कोणाचंही बाकी नाही.</td></tr><?php endif; ?>
    </tbody></table>
  </section>

  <div class="sig"><div>लेखापाल</div><div>वाहतूक प्रमुख</div><div>प्राचार्य</div></div>
  <div class="foot">हा अहवाल E-Admin मधून <?= date('d/m/Y h:i A') ?> ला आपोआप तयार झाला · आकडे त्या वेळचे.</div>
</div>
<script>
  document.querySelectorAll('[data-sec]').forEach(function (c) {
    var k = 'busRep_' + c.dataset.sec; try { if (localStorage.getItem(k) === '0') c.checked = false; } catch (e) {}
    var ap = function () { var s = document.querySelector('[data-s="' + c.dataset.sec + '"]'); if (s) s.classList.toggle('off', !c.checked); try { localStorage.setItem(k, c.checked ? '1' : '0'); } catch (e) {} };
    c.addEventListener('change', ap); ap();
  });
</script>
</body>
</html>
