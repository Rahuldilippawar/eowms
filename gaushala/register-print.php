<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireMigratedTable($pdo, 'gaushala_cattle', '../');
require_once __DIR__ . '/helpers.php';
$user = currentUser();
$orgId = activeOrgId();

$type = $_GET['type'] ?? 'all';
if (!in_array($type, ['all', 'tagged', 'untagged', 'calf', 'gorha', 'bull'], true)) { $type = 'all'; }
$categoryMap = ['tagged' => 'Cow', 'untagged' => 'Cow', 'calf' => 'Calf', 'gorha' => 'Young Bull', 'bull' => 'Bull/Ox'];

$sql = "
    SELECT gc.*, c.name AS campus_name, u.name AS caretaker_name
    FROM gaushala_cattle gc LEFT JOIN campuses c ON c.id = gc.campus_id LEFT JOIN users u ON u.id = gc.caretaker_id
    WHERE gc.organization_id = ? AND gc.status = 'active'
";
$params = [$orgId];
if (isset($categoryMap[$type])) {
    $sql .= " AND gc.animal_category = ?";
    $params[] = $categoryMap[$type];
    if ($type === 'tagged') { $sql .= " AND gc.tag_number IS NOT NULL AND gc.tag_number <> ''"; }
    if ($type === 'untagged') { $sql .= " AND (gc.tag_number IS NULL OR gc.tag_number = '')"; }
}
$sql .= " ORDER BY (gc.tag_number IS NULL), gc.tag_number, gc.id";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$cattleList = $stmt->fetchAll();

$categoryLabels = gsCategoryLabels();
$typeLabels = ['all' => 'सर्व जनावरं', 'tagged' => 'Batch वाली गाय', 'untagged' => 'बिना Batch गाय', 'calf' => 'वासरं', 'gorha' => 'गोऱ्हे', 'bull' => 'बैल'];

$org = $pdo->query("SELECT * FROM organizations WHERE id = " . (int)$orgId)->fetch();
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>गौशाळा नोंदवही — <?= h($org['name'] ?? '') ?></title>
<style>
  :root{ --ink:#10162B; --rail:#1B8A83; --line:#D7DAE0; --text:#1A1D29; --text-dim:#666B7D; }
  *{box-sizing:border-box;}
  body{font-family:'Noto Sans Devanagari','Segoe UI',sans-serif;color:var(--text);margin:0;background:#F0F1F4;font-size:12.5px;}
  .toolbar{position:sticky;top:0;background:var(--ink);padding:14px 24px;display:flex;justify-content:space-between;align-items:center;z-index:10;flex-wrap:wrap;gap:10px;}
  .toolbar a{color:#fff;text-decoration:none;font-size:13px;}
  .toolbar .btn{background:var(--rail);color:#fff;border:none;border-radius:8px;padding:9px 20px;font-size:13.5px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;display:inline-block;}
  .toolbar form{display:flex;gap:8px;align-items:center;}
  .toolbar select{border-radius:6px;border:none;padding:6px 10px;font-size:12.5px;}
  .doc{max-width:1100px;margin:24px auto;background:#fff;padding:36px 44px;border-radius:6px;}
  .org-name{font-family:'Space Grotesk';font-weight:700;font-size:19px;color:var(--ink);text-align:center;}
  .report-title{text-align:center;font-family:'Space Grotesk';font-weight:600;font-size:15px;margin-top:6px;color:var(--rail);}
  .meta{text-align:center;font-size:12px;color:var(--text-dim);margin-top:4px;}
  table{width:100%;border-collapse:collapse;margin-top:16px;}
  th{background:#F3F4F7;text-align:left;padding:7px 9px;font-size:10px;text-transform:uppercase;color:var(--text-dim);border-bottom:1px solid var(--line);}
  td{padding:7px 9px;border-bottom:1px solid #EEF0F4;font-size:11.5px;vertical-align:middle;}
  .cattle-photo{width:44px;height:44px;border-radius:6px;object-fit:cover;display:block;}
  .cattle-photo-placeholder{width:44px;height:44px;border-radius:6px;background:#F3F4F7;display:flex;align-items:center;justify-content:center;color:var(--text-dim);font-size:9px;text-align:center;}
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
      if (combo) { combo.value = lang; combo.dispatchEvent(new Event('change')); }
      else if (tries < 20) { tries++; setTimeout(trigger, 250); }
    };
    trigger();
  }
</script>
<script src="https://translate.google.com/translate_a/element.js?cb=googleTranslateElementInit" async></script>

<div class="toolbar">
  <a href="cattle.php"><i class="fa-solid fa-arrow-left"></i> गाय/जनावर Registry वर परत जा</a>
  <form method="GET">
    <select name="type" onchange="this.form.submit()">
      <?php foreach ($typeLabels as $val => $lbl): ?><option value="<?= $val ?>" <?= $type === $val ? 'selected' : '' ?>><?= h($lbl) ?></option><?php endforeach; ?>
    </select>
  </form>
  <button class="btn" onclick="window.print()"><i class="fa-solid fa-print"></i> PDF डाउनलोड करा</button>
</div>

<div class="doc">
  <?php if (!empty($org['logo_path'])): ?><div style="text-align:center;"><img src="../<?= h($org['logo_path']) ?>" style="height:52px;"></div><?php endif; ?>
  <div class="org-name"><?= h($org['name'] ?? '') ?></div>
  <div class="report-title">🐄 गौशाळा नोंदवही — <?= h($typeLabels[$type]) ?></div>
  <div class="meta">तयार केलं: <?= date('d M Y, h:i A') ?> · एकूण <?= count($cattleList) ?> जनावरं</div>

  <table>
    <thead>
      <tr><th>फोटो</th><th>Batch/Tag</th><th>नाव</th><th>प्रकार</th><th>जात</th><th>रंग</th><th>लिंग</th><th>वय</th><th>काळजीवाहक</th><th>College/School</th></tr>
    </thead>
    <tbody>
      <?php if (empty($cattleList)): ?><tr><td colspan="10" style="text-align:center;color:var(--text-dim);padding:20px;">या गटात कुठलंही जनावर नाही</td></tr><?php endif; ?>
      <?php foreach ($cattleList as $r):
        $age = gsAgeMonths($r['date_of_birth'], $r['approx_age_months'], $r['created_at']);
        $ageText = gsAgeText($age) . ((empty($r['date_of_birth']) && $age !== null) ? ' (अंदाजे)' : '');
      ?>
        <tr>
          <td>
            <?php if (!empty($r['photo_path'])): ?>
              <img src="../<?= h($r['photo_path']) ?>" class="cattle-photo">
            <?php else: ?>
              <div class="cattle-photo-placeholder">फोटो नाही</div>
            <?php endif; ?>
          </td>
          <td><?= gsHasTag($r['tag_number']) ? h($r['tag_number']) : '—' ?></td>
          <td><?= h($r['name'] ?: '—') ?></td>
          <td><?= h($categoryLabels[$r['animal_category'] ?? 'Cow'] ?? '') ?></td>
          <td><?= h($r['breed'] ?: '—') ?></td>
          <td><?= h($r['color'] ?: '—') ?></td>
          <td><?= $r['gender'] === 'Male' ? 'नर' : 'मादी' ?></td>
          <td><?= h($ageText) ?></td>
          <td><?= h($r['caretaker_name'] ?: '—') ?></td>
          <td><?= h($r['campus_name'] ?: '—') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
</body>
</html>
