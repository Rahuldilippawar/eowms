<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();

$locationId = (int)($_GET['location_id'] ?? 0);
$orgId = activeOrgId();

$deptCodeCheck = $pdo->prepare("SELECT has_issue_register FROM departments WHERE id = ?");
$deptCodeCheck->execute([$user['department_id'] ?? 0]);
$userDeptHasIssueRegister = !empty($deptCodeCheck->fetch()['has_issue_register']);
$isMgmt = hasPermission('manage_maintenance_settings');
if (!$isMgmt && !$userDeptHasIssueRegister) {
    http_response_code(403);
    die('तुम्हाला देखभाल नोंदवही पाहण्याची परवानगी नाही.');
}

$stmt = $pdo->prepare("SELECT ml.*, c.name AS campus_name FROM maintenance_locations ml LEFT JOIN campuses c ON c.id = ml.campus_id WHERE ml.id = ? AND ml.organization_id = ?");
$stmt->execute([$locationId, $orgId]);
$location = $stmt->fetch();
if (!$location) { die('जागा सापडली नाही.'); }

$org = $pdo->query("SELECT * FROM organizations WHERE id = " . (int)$orgId)->fetch();

$stmt = $pdo->prepare("
    SELECT mgl.*, d.name_local AS dept_name, u.name AS performed_by_name
    FROM maintenance_logs mgl
    LEFT JOIN departments d ON d.id = mgl.department_id
    LEFT JOIN users u ON u.id = mgl.performed_by
    WHERE mgl.location_id = ? ORDER BY mgl.work_date DESC
");
$stmt->execute([$locationId]);
$logs = $stmt->fetchAll();

$totalCost = array_sum(array_column($logs, 'cost'));
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<title>देखभाल अहवाल — <?= h($location['name']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{ --ink:#10162B; --rail:#1B8A83; --line:#D7DAE0; --text:#1A1D29; --text-dim:#666B7D; }
  *{box-sizing:border-box;}
  body{font-family:'Noto Sans Devanagari','Segoe UI',sans-serif;color:var(--text);margin:0;background:#F0F1F4;font-size:13px;}
  .toolbar{position:sticky;top:0;background:var(--ink);padding:14px 24px;display:flex;justify-content:space-between;align-items:center;z-index:10;}
  .toolbar .btn{background:var(--rail);color:#fff;border:none;border-radius:8px;padding:9px 20px;font-size:13.5px;font-weight:600;cursor:pointer;font-family:inherit;}
  .toolbar a{color:#fff;text-decoration:none;font-size:13px;}
  .doc{max-width:820px;margin:24px auto;background:#fff;padding:36px 44px;border-radius:6px;}
  .org-name{font-family:'Space Grotesk';font-weight:700;font-size:20px;color:var(--ink);text-align:center;}
  .report-title{text-align:center;font-family:'Space Grotesk';font-weight:600;font-size:16px;margin-top:6px;color:var(--rail);}
  .meta{text-align:center;font-size:12.5px;color:var(--text-dim);margin-top:6px;}
  .summary-row{display:flex;gap:14px;margin:22px 0;justify-content:center;}
  .summary-box{border:1px solid var(--line);border-radius:10px;padding:12px 22px;text-align:center;}
  .summary-box .lbl{font-size:11px;color:var(--text-dim);text-transform:uppercase;}
  .summary-box .val{font-family:'Space Grotesk';font-weight:700;font-size:20px;}
  table{width:100%;border-collapse:collapse;margin-top:10px;}
  th{background:#F3F4F7;text-align:left;padding:8px 10px;font-size:11px;text-transform:uppercase;color:var(--text-dim);border-bottom:1px solid var(--line);}
  td{padding:8px 10px;border-bottom:1px solid #EEF0F4;font-size:12.5px;}
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
  <a href="location-history.php?id=<?= $locationId ?>">← मागे</a>
  <button class="btn" onclick="window.print()"><i class="fa-solid fa-download"></i> PDF डाउनलोड करा</button>
</div>

<div class="doc">
  <?php if (!empty($org['logo_path'])): ?><div style="text-align:center;"><img src="../<?= h($org['logo_path']) ?>" style="height:56px;"></div><?php endif; ?>
  <div class="org-name"><?= h($org['name'] ?? '') ?></div>
  <div class="report-title">देखभाल इतिहास अहवाल</div>
  <div class="meta"><?= h($location['name']) ?> (<?= h($location['location_code']) ?>)<?= $location['campus_name'] ? ' · ' . h($location['campus_name']) : '' ?></div>

  <div class="summary-row">
    <div class="summary-box"><div class="lbl">एकूण नोंदी</div><div class="val"><?= count($logs) ?></div></div>
    <div class="summary-box"><div class="lbl">एकूण खर्च</div><div class="val" style="color:#1B8A83;">₹<?= number_format($totalCost, 0) ?></div></div>
  </div>

  <table>
    <thead><tr><th>दिनांक</th><th>तपशील</th><th>प्रकार</th><th>विभाग</th><th>केले</th><th>खर्च</th></tr></thead>
    <tbody>
      <?php foreach ($logs as $l): ?>
        <tr>
          <td><?= date('d M Y', strtotime($l['work_date'])) ?></td>
          <td><?= h($l['item_description']) ?></td>
          <td><?= h($l['work_type']) ?></td>
          <td><?= h($l['dept_name'] ?? '—') ?></td>
          <td><?= h($l['performed_by_name'] ?? '—') ?></td>
          <td><?= $l['cost'] ? '₹' . number_format($l['cost'], 0) : '—' ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (empty($logs)): ?><tr><td colspan="6" style="text-align:center;color:var(--text-dim);">कोणतीही नोंद नाही</td></tr><?php endif; ?>
    </tbody>
  </table>

  <div style="margin-top:24px;font-size:11px;color:var(--text-dim);text-align:right;">तयार केले: <?= date('d M Y, h:i A') ?></div>
</div>

</body>
</html>
