<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();

$filterDeptId = isDeptScoped() ? $user['department_id'] : ($_GET['department_id'] ?? '');

$sql = "
    SELECT r.*, d.name_local AS dept_name, c.name AS campus_name
    FROM renewal_items r JOIN departments d ON d.id = r.department_id LEFT JOIN campuses c ON c.id = d.campus_id
    WHERE r.organization_id = ?
";
$params = [$orgId];
if ($filterDeptId) { $sql .= " AND r.department_id = ?"; $params[] = $filterDeptId; }
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

function renewalTypeIcon($type) {
    $type = mb_strtolower($type);
    if (str_contains($type, 'insurance') || str_contains($type, 'विमा')) return '🛡️';
    if (str_contains($type, 'puc')) return '🌫️';
    if (str_contains($type, 'license') || str_contains($type, 'परवाना')) return '📜';
    if (str_contains($type, 'permit')) return '📋';
    if (str_contains($type, 'fitness')) return '✅';
    if (str_contains($type, 'tax') || str_contains($type, 'कर')) return '💰';
    if (str_contains($type, 'amc') || str_contains($type, 'contract') || str_contains($type, 'करार')) return '🔧';
    if (str_contains($type, 'warranty')) return '🛠️';
    if (str_contains($type, 'domain') || str_contains($type, 'hosting') || str_contains($type, 'software')) return '💻';
    return '📄';
}

// प्रकार-निहाय Summary (किती नोंदी, एकूण खर्च, किती Expired/Due Soon)
$byType = [];
foreach ($items as $it) {
    $t = $it['item_type'];
    if (!isset($byType[$t])) { $byType[$t] = ['count' => 0, 'cost' => 0, 'expired' => 0, 'due_soon' => 0]; }
    $byType[$t]['count']++;
    $byType[$t]['cost'] += (float)($it['renewal_cost'] ?? 0);
    $daysLeft = (strtotime($it['expiry_date']) - time()) / 86400;
    if ($daysLeft < 0) { $byType[$t]['expired']++; }
    elseif ($daysLeft <= 30) { $byType[$t]['due_soon']++; }
}
uasort($byType, fn($a, $b) => $b['count'] <=> $a['count']);

// विभाग-निहाय Summary
$byDept = [];
foreach ($items as $it) {
    $d = $it['dept_name'];
    if (!isset($byDept[$d])) { $byDept[$d] = ['count' => 0, 'cost' => 0, 'campus_name' => $it['campus_name']]; }
    $byDept[$d]['count']++;
    $byDept[$d]['cost'] += (float)($it['renewal_cost'] ?? 0);
}

$totalCost = array_sum(array_column($items, 'renewal_cost'));
$totalExpired = count(array_filter($items, fn($i) => strtotime($i['expiry_date']) < time()));
$totalDueSoon = count(array_filter($items, fn($i) => (strtotime($i['expiry_date']) - time()) / 86400 <= 30 && strtotime($i['expiry_date']) >= time()));

$org = $pdo->query("SELECT * FROM organizations WHERE id = " . (int)$orgId)->fetch();
$deptName = null;
if ($filterDeptId) {
    $dn = $pdo->prepare("SELECT name_local FROM departments WHERE id = ?");
    $dn->execute([$filterDeptId]);
    $deptName = $dn->fetch()['name_local'] ?? null;
}
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<title>नूतनीकरण अहवाल</title>
<style>
  :root{ --ink:#10162B; --rail:#1B8A83; --line:#D7DAE0; --text-dim:#666B7D; --amber:#C97F1D; --rose:#B23A48; }
  *{box-sizing:border-box;}
  body{font-family:'Segoe UI',sans-serif;color:#1A1D29;margin:0;background:#F0F1F4;font-size:13px;}
  .toolbar{position:sticky;top:0;background:var(--ink);padding:14px 24px;display:flex;justify-content:space-between;z-index:10;}
  .toolbar a, .toolbar button{color:#fff;text-decoration:none;font-size:13px;background:var(--rail);border:none;border-radius:8px;padding:9px 20px;font-weight:600;cursor:pointer;}
  .doc{max-width:900px;margin:24px auto;background:#fff;padding:36px 44px;border-radius:6px;}
  .org-name{font-weight:700;font-size:19px;text-align:center;}
  .report-title{text-align:center;font-weight:600;font-size:15px;margin-top:6px;color:var(--rail);}
  .meta{text-align:center;font-size:12px;color:var(--text-dim);margin-top:4px;}
  .stat-row{display:flex;gap:14px;margin:24px 0;justify-content:center;flex-wrap:wrap;}
  .stat-box{background:#F7F8FA;border-radius:10px;padding:14px 22px;text-align:center;min-width:120px;}
  .stat-box .num{font-size:22px;font-weight:700;}
  .stat-box .lbl{font-size:10.5px;color:var(--text-dim);text-transform:uppercase;margin-top:2px;}
  table{width:100%;border-collapse:collapse;margin-top:14px;}
  th{background:#F3F4F7;text-align:left;padding:8px 10px;font-size:11px;text-transform:uppercase;color:var(--text-dim);}
  td{padding:9px 10px;border-bottom:1px solid #EEF0F4;font-size:12.5px;}
  h3{font-size:14px;margin:26px 0 4px;}
  @media print{ .toolbar{display:none;} #eowmsLangSelect, #google_translate_element{display:none !important;} body{background:#fff;} .doc{margin:0;} }
</style>
</head>
<body>
<select id="eowmsLangSelect" onchange="eowmsChangeLanguage(this.value)" style="position:fixed;top:16px;right:16px;z-index:50;border:1px solid var(--line);border-radius:9px;padding:8px 12px;font-size:12.5px;color:var(--text-dim);background:#fff;cursor:pointer;">
  <option value="">🌐 भाषा (Language)</option>
  <option value="en">English</option>
  <option value="hi">हिंदी (Hindi)</option>
  <option value="gu">ગુજરાતી (Gujarati)</option>
  <option value="mr">मराठी (मूळ)</option>
</select>
<div id="google_translate_element" style="display:none;"></div>
<style>body > .skiptranslate{display:none !important;} body{top:0 !important;}</style>
<script>
  function googleTranslateElementInit() {
    new google.translate.TranslateElement({ pageLanguage: 'mr', autoDisplay: false }, 'google_translate_element');
  }
  function eowmsChangeLanguage(lang) {
    var tries = 0;
    var trigger = function () {
      var combo = document.querySelector('.goog-te-combo');
      if (combo) {
        combo.value = lang;
        combo.dispatchEvent(new Event('change'));
      } else if (tries < 20) {
        tries++;
        setTimeout(trigger, 250);
      }
    };
    trigger();
  }
</script>
<script src="https://translate.google.com/translate_a/element.js?cb=googleTranslateElementInit" async></script>

<div class="toolbar">
  <a href="index.php">← मागे</a>
  <button onclick="window.print()">PDF डाउनलोड करा</button>
</div>
<div class="doc">
  <div class="org-name"><?= h($org['name'] ?? '') ?></div>
  <div class="report-title">नूतनीकरण व अनुपालन — अहवाल<?= $deptName ? ' — ' . h($deptName) : ' (सर्व विभाग)' ?></div>
  <div class="meta">तयार केले: <?= date('d M Y, h:i A') ?></div>

  <div class="stat-row">
    <div class="stat-box"><div class="num"><?= count($items) ?></div><div class="lbl">एकूण नोंदी</div></div>
    <div class="stat-box" style="background:#F7E2E3;"><div class="num" style="color:var(--rose);"><?= $totalExpired ?></div><div class="lbl">मुदत संपलेल्या</div></div>
    <div class="stat-box" style="background:#FBEBD6;"><div class="num" style="color:var(--amber);"><?= $totalDueSoon ?></div><div class="lbl">30 दिवसांत संपणाऱ्या</div></div>
    <div class="stat-box" style="background:#DCEFEC;"><div class="num" style="color:var(--rail);">₹<?= number_format($totalCost, 0) ?></div><div class="lbl">एकूण नूतनीकरण खर्च</div></div>
  </div>

  <h3>📊 प्रकार-निहाय विभाजन (कशाचे किती नूतनीकरण)</h3>
  <table>
    <thead><tr><th>प्रकार</th><th>एकूण</th><th>मुदत संपलेल्या</th><th>लवकरच</th><th>एकूण खर्च (₹)</th></tr></thead>
    <tbody>
      <?php foreach ($byType as $type => $st): ?>
        <tr>
          <td style="font-weight:600;"><?= renewalTypeIcon($type) ?> <?= h($type) ?></td>
          <td><?= $st['count'] ?></td>
          <td style="color:<?= $st['expired'] > 0 ? 'var(--rose)' : 'inherit' ?>;"><?= $st['expired'] ?></td>
          <td style="color:<?= $st['due_soon'] > 0 ? 'var(--amber)' : 'inherit' ?>;"><?= $st['due_soon'] ?></td>
          <td><?= number_format($st['cost'], 0) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if (!$filterDeptId && count($byDept) > 1): ?>
  <h3>🏢 विभाग-निहाय विभाजन</h3>
  <table>
    <thead><tr><th>विभाग</th><th>College/School</th><th>एकूण नोंदी</th><th>एकूण खर्च (₹)</th></tr></thead>
    <tbody>
      <?php foreach ($byDept as $dn => $st): ?>
        <tr><td style="font-weight:600;"><?= h($dn) ?></td><td style="font-size:11px;color:#666B7D;"><?= h($st['campus_name'] ?: '🏛️ Trust-स्तरीय') ?></td><td><?= $st['count'] ?></td><td><?= number_format($st['cost'], 0) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>

  <h3>📋 संपूर्ण तपशीलवार यादी</h3>
  <table>
    <thead><tr><th>वस्तू</th><th>प्रकार</th><?php if (!$filterDeptId): ?><th>विभाग</th><th>College/School</th><?php endif; ?><th>मुदत दिनांक</th><th>खर्च (₹)</th></tr></thead>
    <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td><?= h($it['item_name']) ?></td>
          <td><?= renewalTypeIcon($it['item_type']) ?> <?= h($it['item_type']) ?></td>
          <?php if (!$filterDeptId): ?><td><?= h($it['dept_name']) ?></td><td style="font-size:11px;color:#666B7D;"><?= h($it['campus_name'] ?: '🏛️') ?></td><?php endif; ?>
          <td><?= date('d M Y', strtotime($it['expiry_date'])) ?></td>
          <td><?= $it['renewal_cost'] ? number_format($it['renewal_cost'], 0) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
</body>
</html>
