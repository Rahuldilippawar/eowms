<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();

$orgId = activeOrgId();
$isMgmt = isManagementOrAbove() || hasPermission('manage_dead_stock_master'); // index.php प्रमाणेच
$deptId = (int)($_GET['department_id'] ?? 0);
// Staff/HOD — स्वतःचा + Extra दिलेले विभाग; विभाग नसेल तर काहीच नाही (संपूर्ण संस्था नाही)
$dsVisibleDeptIds = $isMgmt ? null : (getVisibleDepartmentIds() ?: [0]);
if (!$isMgmt && !in_array($deptId, $dsVisibleDeptIds, true)) { $deptId = 0; }

$org = $pdo->query("SELECT * FROM organizations WHERE id = " . (int)$orgId)->fetch();

$sql = "
    SELECT ds.*, d.name_local AS dept_name, c.name AS campus_name, u.name AS added_by_name
    FROM dead_stock_items ds
    JOIN departments d ON d.id = ds.department_id
    LEFT JOIN campuses c ON c.id = d.campus_id
    LEFT JOIN users u ON u.id = ds.added_by
    WHERE ds.organization_id = ?
";
$params = [$orgId];
if ($deptId) {
    $sql .= " AND ds.department_id = ?"; $params[] = $deptId;
} elseif (!$isMgmt) {
    $sql .= " AND ds.department_id IN (" . implode(',', array_fill(0, count($dsVisibleDeptIds), '?')) . ")";
    $params = array_merge($params, $dsVisibleDeptIds);
}
$sql .= " ORDER BY d.name_local, ds.category, ds.item_name";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

$deptNameForTitle = null;
if ($deptId) {
    $dStmt = $pdo->prepare("SELECT name_local FROM departments WHERE id = ?");
    $dStmt->execute([$deptId]);
    $deptNameForTitle = $dStmt->fetch()['name_local'] ?? null;
}

$totalQty = array_sum(array_column($items, 'quantity'));
$totalValue = array_sum(array_column($items, 'purchase_cost'));
$conditionCounts = ['चांगली' => 0, 'दुरुस्तीयोग्य' => 0, 'खराब' => 0, 'भंगार' => 0];
foreach ($items as $it) { $conditionCounts[$it['condition']] = ($conditionCounts[$it['condition']] ?? 0) + 1; }

$conditionColor = ['चांगली' => '#1B8A83', 'दुरुस्तीयोग्य' => '#C97F1D', 'खराब' => '#B23A48', 'भंगार' => '#8A1F2B'];
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<title>Dead Stock Register — अहवाल</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{ --ink:#10162B; --rail:#1B8A83; --line:#D7DAE0; --text:#1A1D29; --text-dim:#666B7D; }
  *{box-sizing:border-box;}
  body{font-family:'Noto Sans Devanagari','Segoe UI',sans-serif;color:var(--text);margin:0;background:#F0F1F4;font-size:13px;}
  .toolbar{position:sticky;top:0;background:var(--ink);padding:14px 24px;display:flex;justify-content:space-between;align-items:center;z-index:10;}
  .toolbar .btn{background:var(--rail);color:#fff;border:none;border-radius:8px;padding:9px 20px;font-size:13.5px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;display:inline-block;}
  .toolbar a.back{color:#fff;text-decoration:none;font-size:13px;}
  .doc{max-width:900px;margin:24px auto;background:#fff;padding:36px 44px;border-radius:6px;}
  .org-name{font-family:'Space Grotesk';font-weight:700;font-size:20px;color:var(--ink);text-align:center;}
  .report-title{text-align:center;font-family:'Space Grotesk';font-weight:600;font-size:16px;margin-top:6px;color:var(--rail);}
  .meta{text-align:center;font-size:12.5px;color:var(--text-dim);margin-top:6px;}
  .summary-row{display:flex;gap:14px;margin:22px 0;justify-content:center;flex-wrap:wrap;}
  .summary-box{border:1px solid var(--line);border-radius:10px;padding:12px 20px;text-align:center;min-width:110px;}
  .summary-box .lbl{font-size:10.5px;color:var(--text-dim);text-transform:uppercase;}
  .summary-box .val{font-family:'Space Grotesk';font-weight:700;font-size:19px;}
  table{width:100%;border-collapse:collapse;margin-top:12px;}
  th{background:#F3F4F7;text-align:left;padding:8px 10px;font-size:10.5px;text-transform:uppercase;color:var(--text-dim);border-bottom:1px solid var(--line);}
  td{padding:7px 10px;border-bottom:1px solid #EEF0F4;font-size:11.5px;}
  .chip{padding:2px 10px;border-radius:20px;font-size:10.5px;font-weight:600;color:#fff;white-space:nowrap;}
  @media print{ .toolbar{display:none;} #eowmsLangSelect, #google_translate_element{display:none !important;} body{background:#fff;} .doc{margin:0;box-shadow:none;} }
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
  <a class="back" href="index.php<?= $deptId ? '?department_id=' . $deptId : '' ?>">← मागे</a>
  <button class="btn" onclick="window.print()"><i class="fa-solid fa-download"></i> PDF डाउनलोड करा</button>
</div>

<div class="doc">
  <?php if (!empty($org['logo_path'])): ?><div style="text-align:center;"><img src="../<?= h($org['logo_path']) ?>" style="height:56px;"></div><?php endif; ?>
  <div class="org-name"><?= h($org['name'] ?? '') ?></div>
  <div class="report-title">Dead Stock Register — अहवाल</div>
  <div class="meta"><?= $deptNameForTitle ? h($deptNameForTitle) . ' विभाग' : 'सर्व विभाग (संस्था-व्यापी)' ?> · तयार केले: <?= date('d M Y, h:i A') ?></div>

  <div class="summary-row">
    <div class="summary-box"><div class="lbl">एकूण नोंदी</div><div class="val"><?= count($items) ?></div></div>
    <div class="summary-box"><div class="lbl">एकूण संख्या</div><div class="val"><?= number_format($totalQty) ?></div></div>
    <div class="summary-box"><div class="lbl">एकूण किंमत</div><div class="val" style="color:#1B8A83;">₹<?= number_format($totalValue, 0) ?></div></div>
    <div class="summary-box"><div class="lbl">दुरुस्ती/खराब</div><div class="val" style="color:#B23A48;"><?= $conditionCounts['दुरुस्तीयोग्य'] + $conditionCounts['खराब'] + $conditionCounts['भंगार'] ?></div></div>
  </div>

  <table>
    <thead>
      <tr>
        <th>Code</th><th>वस्तू</th><th>प्रकार</th>
        <?php if (!$deptId): ?><th>विभाग</th><th>College/School</th><?php endif; ?>
        <th>संख्या</th><th>किंमत</th><th>स्थिती</th><th>ठिकाण</th><th>खरेदी दिनांक</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td><?= h($it['item_code']) ?></td>
          <td><?= h($it['item_name']) ?></td>
          <td><?= h($it['category'] ?? '—') ?></td>
          <?php if (!$deptId): ?><td><?= h($it['dept_name']) ?></td><td style="font-size:11px;color:#666B7D;"><?= h($it['campus_name'] ?: '🏛️') ?></td><?php endif; ?>
          <td><?= $it['quantity'] ?> <?= h($it['unit'] ?? '') ?></td>
          <td><?= $it['purchase_cost'] ? '₹' . number_format($it['purchase_cost'], 0) : '—' ?></td>
          <td><span class="chip" style="background:<?= $conditionColor[$it['condition']] ?? '#666' ?>;"><?= h($it['condition']) ?></span></td>
          <td><?= h($it['location'] ?? '—') ?></td>
          <td><?= $it['purchase_date'] ? date('d M Y', strtotime($it['purchase_date'])) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($items)): ?>
        <tr><td colspan="9" style="text-align:center;color:var(--text-dim);">कोणतीही नोंद सापडली नाही</td></tr>
      <?php endif; ?>
    </tbody>
  </table>

  <div style="margin-top:24px;font-size:11px;color:var(--text-dim);text-align:right;">एकूण <?= count($items) ?> नोंदी</div>
</div>

</body>
</html>
