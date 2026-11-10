<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();

if (!hasPermission('manage_sop')) {
    http_response_code(403);
    die('तुम्हाला Compliance अहवाल पाहण्याची परवानगी नाही.');
}

require_once __DIR__ . '/../includes/sop-helpers.php';
$orgId = activeOrgId();

$all = sopAllScores($pdo, $orgId, 30);
// 📊 Excel
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sop-compliance-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['SOP', 'विभाग', 'Compliance % (30 दिवस)', 'स्थिती']);
    foreach ($all as $r) fputcsv($out, [$r['title'], $r['dept'], $r['score'] === null ? '' : $r['score'], $r['score'] === null ? 'डेटा नाही' : ($r['score'] >= 80 ? 'चांगलं' : ($r['score'] >= 50 ? 'सुधारणा हवी' : 'कमी'))]);
    fclose($out);
    exit;
}

$pageTitle = 'SOP Compliance अहवाल';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/smart-insights.php';

// SOP × विभाग — वर काढलेल्या $all मधूनच (Score पुन्हा मोजत नाही)
$rows = array_map(fn($p) => ['sop' => ['id' => $p['sop_id'], 'title' => $p['title']], 'dept' => ['id' => $p['dept_id'], 'name_local' => $p['dept'], 'name' => $p['dept']], 'score' => $p['score']], $all);
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-1">📊 SOP Compliance अहवाल</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>SOP व्यवस्थापन</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:12px;">गेल्या 30 दिवसांतील सरासरी पालन (Compliance) — प्रत्येक विभाग, प्रत्येक SOP साठी
  · <a href="?export=csv" style="font-weight:600;">📊 Excel</a></p>
<?php
$scored = array_values(array_filter($all, fn($r) => $r['score'] !== null));
$avg = $scored ? (int)round(array_sum(array_column($scored, 'score')) / count($scored)) : null;
$good = count(array_filter($scored, fn($r) => $r['score'] >= 80));
$bad = count(array_filter($scored, fn($r) => $r['score'] < 50));
smartTiles([
    ['label' => 'एकूण सरासरी पालन', 'value' => $avg === null ? '—' : $avg . '%', 'tone' => $avg === null ? '' : ($avg >= 80 ? 'ok' : ($avg >= 50 ? 'warn' : 'bad'))],
    ['label' => 'SOP × विभाग', 'value' => count($all), 'sub' => (count($all) - count($scored)) . ' मध्ये डेटा नाही'],
    ['label' => '80%+ (चांगलं)', 'value' => $good, 'tone' => 'ok'],
    ['label' => '50% पेक्षा कमी', 'value' => $bad, 'tone' => $bad ? 'bad' : ''],
]);
$byDept = [];
foreach ($scored as $r) { $byDept[$r['dept']][] = $r['score']; }
$deptRows = [];
foreach ($byDept as $dn => $arr) { $v = (int)round(array_sum($arr) / count($arr)); $deptRows[] = ['name' => $dn, 'value' => $v, 'label' => $v . '%', 'color' => $v >= 80 ? 'var(--rail)' : ($v >= 50 ? 'var(--amber)' : 'var(--rose)')]; }
usort($deptRows, fn($a, $b) => $a['value'] <=> $b['value']);
if ($deptRows) { echo '<div class="row g-3 mb-3"><div class="col-lg-7">'; smartBars('🏢 विभागनिहाय सरासरी पालन', 'सर्वात कमी आधी', array_slice($deptRows, 0, 10)); echo '</div></div>'; }
?>
<div class="sc-chips mb-2">
  <button type="button" class="on" data-f="">सर्व</button><button type="button" data-f="bad">🔴 50% पेक्षा कमी</button><button type="button" data-f="mid">🟠 50–79%</button><button type="button" data-f="good">🟢 80%+</button><button type="button" data-f="none">⚪ डेटा नाही</button>
</div>
<style>.sc-chips{display:flex;flex-wrap:wrap;gap:6px;}.sc-chips button{border:1px solid var(--line);background:var(--panel,#fff);border-radius:20px;padding:5px 12px;font-size:12.5px;font-weight:600;}.sc-chips button.on{background:var(--rail);border-color:var(--rail);color:#fff;}</style>
<script>document.addEventListener('DOMContentLoaded',function(){document.querySelectorAll('.sc-chips button').forEach(function(b){b.onclick=function(){document.querySelectorAll('.sc-chips button').forEach(function(x){x.classList.toggle('on',x===b);});var f=b.dataset.f;document.querySelectorAll('tr[data-band]').forEach(function(tr){tr.style.display=!f||tr.dataset.band===f?'':'none';});};});});</script>

<div class="panel">
  <table class="rtable">
    <thead><tr><th>SOP</th><th>विभाग</th><th>Compliance %</th><th></th></tr></thead>
    <tbody>
      <?php if (empty($rows)): ?><tr><td colspan="4" style="text-align:center;color:var(--text-dim);padding:24px;">अजून कोणताही डेटा नाही</td></tr><?php endif; ?>
      <?php usort($rows, fn($a, $b) => ($a['score'] ?? 101) <=> ($b['score'] ?? 101)); foreach ($rows as $r): $score = $r['score']; ?>
        <tr data-band="<?= $score === null ? 'none' : ($score >= 80 ? 'good' : ($score >= 50 ? 'mid' : 'bad')) ?>">
          <td style="font-weight:500;"><?= h($r['sop']['title']) ?></td>
          <td><?= h($r['dept']['name_local'] ?: $r['dept']['name']) ?></td>
          <td>
            <?php if ($score === null): ?>
              <span style="color:var(--text-dim);font-size:12px;">डेटा नाही</span>
            <?php else: ?>
              <div style="display:flex;align-items:center;gap:10px;">
                <div style="background:var(--canvas);border-radius:10px;height:8px;width:100px;overflow:hidden;">
                  <div style="height:100%;width:<?= $score ?>%;background:<?= $score >= 80 ? 'var(--rail)' : ($score >= 50 ? 'var(--amber)' : 'var(--rose)') ?>;"></div>
                </div>
                <strong style="font-size:13px;"><?= $score ?>%</strong>
              </div>
            <?php endif; ?>
          </td>
          <td><a href="checklist.php?department_id=<?= $r['dept']['id'] ?>" style="font-size:11.5px;color:var(--rail);">Checklist बघा</a></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
