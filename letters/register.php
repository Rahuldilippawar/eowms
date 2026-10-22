<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/helpers.php';
$user = currentUser();

$orgId = activeOrgId();
$isMgmt = hasPermission('manage_letters_org_wide');
$type = $_GET['type'] ?? 'outward'; // 'outward' or 'inward'
if (!in_array($type, ['outward', 'inward'], true)) { $type = 'outward'; }
$fyLabel = $_GET['fy'] ?? getFinancialYearLabel();
[$fyStart, $fyEnd] = financialYearLabelToRange($fyLabel);
$fyStartYear = (int)date('Y', strtotime($fyStart));
$deptId = (int)($_GET['department_id'] ?? ($user['department_id'] ?? 0));

if (!$isMgmt && (int)($user['department_id'] ?? 0) !== $deptId) {
    http_response_code(403);
    die('तुम्हाला दुसऱ्या विभागाची नोंदवही पाहण्याची परवानगी नाही.');
}

$org = $pdo->query("SELECT * FROM organizations WHERE id = " . (int)$orgId)->fetch();
$deptStmt = $pdo->prepare("SELECT * FROM departments WHERE id = ? AND organization_id = ?");
$deptStmt->execute([$deptId, $orgId]);
$dept = $deptStmt->fetch();
if (!$dept) { die('विभाग सापडला नाही.'); }

if ($type === 'outward') {
    $stmt = $pdo->prepare("
        SELECT l.*, u.name AS creator_name, u.designation AS creator_designation,
          rec.name AS recipient_name, rec.designation AS recipient_designation, rd.name_local AS recipient_dept_name,
          (SELECT COUNT(*) FROM letter_attachments WHERE letter_id = l.id) AS attachment_count
        FROM letters l LEFT JOIN users u ON u.id = l.created_by
        LEFT JOIN letter_actions la ON la.id = (
            SELECT id FROM letter_actions WHERE letter_id = l.id AND action_type = 'submitted' ORDER BY id ASC LIMIT 1
        )
        LEFT JOIN users rec ON rec.id = la.to_user_id
        LEFT JOIN departments rd ON rd.id = rec.department_id
        WHERE l.organization_id = ? AND l.department_id = ? AND l.outward_no IS NOT NULL AND l.created_at BETWEEN ? AND ?
        ORDER BY l.outward_no
    ");
    $stmt->execute([$orgId, $deptId, $fyStart . ' 00:00:00', $fyEnd . ' 23:59:59']);
    $rows = $stmt->fetchAll();
} else {
    $stmt = $pdo->prepare("
        SELECT lin.inward_no, lin.created_at AS inward_date, l.*, u.name AS creator_name, u.designation AS creator_designation,
          d.name_local AS origin_dept_name,
          (SELECT COUNT(*) FROM letter_attachments WHERE letter_id = l.id) AS attachment_count
        FROM letter_inward_numbers lin
        JOIN letters l ON l.id = lin.letter_id
        LEFT JOIN users u ON u.id = l.created_by
        LEFT JOIN departments d ON d.id = l.department_id
        WHERE lin.department_id = ? AND lin.year = ?
        ORDER BY lin.inward_no
    ");
    $stmt->execute([$deptId, $fyStartYear]);
    $rows = $stmt->fetchAll();
}

// All attachments for every letter shown — listed at the end, grouped by file
$letterIds = array_column($rows, 'id');
$attachmentsByLetter = [];
if (!empty($letterIds)) {
    $in = implode(',', array_fill(0, count($letterIds), '?'));
    $stmt = $pdo->prepare("SELECT * FROM letter_attachments WHERE letter_id IN ($in) ORDER BY letter_id");
    $stmt->execute($letterIds);
    foreach ($stmt->fetchAll() as $att) {
        $attachmentsByLetter[$att['letter_id']][] = $att;
    }
}

$priorityLabels = ['low' => 'कमी', 'normal' => 'सामान्य', 'high' => 'जास्त', 'urgent' => 'तातडीचे'];
$statusLabels = ['draft' => 'Draft', 'in_progress' => 'प्रगतीत', 'completed' => 'पूर्ण/दाखल', 'returned' => 'परत आले'];
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<title><?= $type === 'outward' ? 'जावक' : 'आवक' ?> नोंदवही — <?= h($dept['name_local'] ?: $dept['name']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{ --ink:#10162B; --rail:#1B8A83; --line:#D7DAE0; --text:#1A1D29; --text-dim:#666B7D; }
  *{box-sizing:border-box;}
  body{font-family:'Noto Sans Devanagari','Segoe UI',sans-serif;color:var(--text);margin:0;background:#F0F1F4;font-size:12.5px;}
  .toolbar{position:sticky;top:0;background:var(--ink);padding:14px 24px;display:flex;justify-content:space-between;align-items:center;z-index:10;flex-wrap:wrap;gap:10px;}
  .toolbar a{color:#fff;text-decoration:none;font-size:13px;}
  .toolbar .btn{background:var(--rail);color:#fff;border:none;border-radius:8px;padding:9px 20px;font-size:13.5px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;display:inline-block;}
  .toolbar form{display:flex;gap:8px;align-items:center;}
  .toolbar select{border-radius:6px;border:none;padding:6px 10px;font-size:12.5px;}
  .doc{max-width:1000px;margin:24px auto;background:#fff;padding:36px 44px;border-radius:6px;}
  .org-name{font-family:'Space Grotesk';font-weight:700;font-size:19px;color:var(--ink);text-align:center;}
  .report-title{text-align:center;font-family:'Space Grotesk';font-weight:600;font-size:15px;margin-top:6px;color:var(--rail);}
  .meta{text-align:center;font-size:12px;color:var(--text-dim);margin-top:4px;}
  table{width:100%;border-collapse:collapse;margin-top:16px;}
  th{background:#F3F4F7;text-align:left;padding:7px 9px;font-size:10px;text-transform:uppercase;color:var(--text-dim);border-bottom:1px solid var(--line);}
  td{padding:7px 9px;border-bottom:1px solid #EEF0F4;font-size:11.5px;vertical-align:top;}
  .chip{padding:2px 8px;border-radius:14px;font-size:9.5px;font-weight:600;background:#F3F4F7;color:var(--text-dim);}
  h3{font-family:'Space Grotesk';font-size:13.5px;color:var(--ink);margin:26px 0 8px;}
  .att-list{font-size:11px;color:var(--text-dim);}
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
  <a href="index.php">← पत्र व्यवहार कडे परत</a>
  <form method="GET">
    <?php if ($isMgmt): ?>
      <?php
        $allDeptsStmt = $pdo->prepare("SELECT * FROM departments WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
        $allDeptsStmt->execute([$orgId]);
        $allDepts = $allDeptsStmt->fetchAll();
        $campusesForCascade = $pdo->prepare("SELECT id, name FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY name");
        $campusesForCascade->execute([$orgId]);
        $campusesForCascade = $campusesForCascade->fetchAll();
      ?>
      <?php if (count($campusesForCascade) > 1): ?>
        <select id="campusSelect" onchange="loadDeptsCascade_letReg(this.value)">
          <option value="">-- College/School --</option>
          <?php foreach ($campusesForCascade as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?>
        </select>
      <?php endif; ?>
      <select name="department_id" id="departmentSelect" onchange="this.form.submit()">
        <?php foreach ($allDepts as $d): ?>
          <option value="<?= $d['id'] ?>" <?= $deptId === (int)$d['id'] ? 'selected' : '' ?>><?= h($d['name_local'] ?: $d['name']) ?></option>
        <?php endforeach; ?>
      </select>
      <?php if (count($campusesForCascade) > 1): ?>
      <script>
      function loadDeptsCascade_letReg(campusId) {
        const sel = document.getElementById('departmentSelect');
        if (!campusId) return;
        sel.disabled = true;
        fetch('../ajax-cascade.php?action=departments_for_campus&campus_id=' + campusId)
          .then(r => r.json()).then(data => {
            sel.innerHTML = '';
            (data.departments || []).forEach(d => { const o = document.createElement('option'); o.value = d.id; o.textContent = d.name; sel.appendChild(o); });
            sel.disabled = false;
          });
      }
      </script>
      <?php endif; ?>
    <?php else: ?>
      <input type="hidden" name="department_id" value="<?= $deptId ?>">
    <?php endif; ?>
    <select name="type" onchange="this.form.submit()">
      <option value="outward" <?= $type === 'outward' ? 'selected' : '' ?>>जावक नोंदवही (Outward)</option>
      <option value="inward" <?= $type === 'inward' ? 'selected' : '' ?>>आवक नोंदवही (Inward)</option>
    </select>
    <select name="fy" onchange="this.form.submit()">
      <?php
        $currentFyStart = (int)date('Y', strtotime(getFinancialYearRange()[0]));
        for ($fy = $currentFyStart; $fy >= $currentFyStart - 5; $fy--):
          $optLabel = sprintf('%02d-%02d', $fy % 100, ($fy + 1) % 100);
      ?>
        <option value="<?= $optLabel ?>" <?= $fyLabel === $optLabel ? 'selected' : '' ?>>आर्थिक वर्ष <?= $optLabel ?></option>
      <?php endfor; ?>
    </select>
    <button class="btn" type="submit" style="padding:6px 14px;">पहा</button>
  </form>
  <button class="btn" onclick="window.print()"><i class="fa-solid fa-print"></i> PDF डाउनलोड करा</button>
  <a class="btn" href="register-export.php?type=<?= h($type) ?>&fy=<?= h($fyLabel) ?>&department_id=<?= $deptId ?>"><i class="fa-solid fa-file-csv"></i> Excel Export</a>
</div>

<div class="doc">
  <?php if (!empty($org['logo_path'])): ?><div style="text-align:center;"><img src="../<?= h($org['logo_path']) ?>" style="height:52px;"></div><?php endif; ?>
  <div class="org-name"><?= h($org['name'] ?? '') ?></div>
  <div class="report-title"><?= $type === 'outward' ? 'जावक नोंदवही (Outward Register)' : 'आवक नोंदवही (Inward Register)' ?></div>
  <div class="meta"><?= h($dept['name_local'] ?: $dept['name']) ?> विभाग · आर्थिक वर्ष <?= h($fyLabel) ?> · एकूण <?= count($rows) ?> नोंदी</div>

  <table>
    <thead>
      <tr>
        <th><?= $type === 'outward' ? 'जावक क्र.' : 'आवक क्र.' ?></th>
        <th>दिनांक</th>
        <th>File No.</th>
        <th>विषय</th>
        <th>संदर्भ क्र.</th>
        <?php if ($type === 'outward'): ?>
          <th>कोणाच्या नावाने पाठवले</th>
          <th>कोणत्या विभागाला पाठवले</th>
        <?php else: ?>
          <th>कोणाकडून आले</th>
          <th>पाठवणारा विभाग</th>
        <?php endif; ?>
        <th>प्राधान्य</th>
        <th>स्थिती</th>
        <th>जोडपत्रे</th>
      </tr>
    </thead>
    <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="10" style="text-align:center;color:var(--text-dim);">या वर्षासाठी कोणतीही नोंद सापडली नाही</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td style="font-weight:700;"><?= h($type === 'outward' ? $r['outward_no'] : $r['inward_no']) ?></td>
          <td><?= date('d-m-Y', strtotime($type === 'outward' ? $r['created_at'] : $r['inward_date'])) ?></td>
          <td><?= h($r['file_number']) ?></td>
          <td><?= h($r['subject']) ?></td>
          <td><?= h($r['reference_no'] ?? '—') ?></td>
          <?php if ($type === 'outward'): ?>
            <td><?= h($r['creator_name'] ?? '—') ?><?= $r['creator_designation'] ? '<div style="font-size:10px;color:#666;">' . h($r['creator_designation']) . '</div>' : '' ?></td>
            <td><?= h($r['recipient_dept_name'] ?? '—') ?><?= $r['recipient_name'] ? '<div style="font-size:10px;color:#666;">' . h($r['recipient_name']) . ($r['recipient_designation'] ? ' — ' . h($r['recipient_designation']) : '') . '</div>' : '' ?></td>
          <?php else: ?>
            <td><?= h($r['creator_name'] ?? '—') ?><?= $r['creator_designation'] ? '<div style="font-size:10px;color:#666;">' . h($r['creator_designation']) . '</div>' : '' ?></td>
            <td><?= h($r['origin_dept_name'] ?? '—') ?></td>
          <?php endif; ?>
          <td><?= $priorityLabels[$r['priority']] ?? $r['priority'] ?></td>
          <td><span class="chip"><?= $statusLabels[$r['status']] ?? $r['status'] ?></span></td>
          <td><?= $r['attachment_count'] > 0 ? $r['attachment_count'] . ' फाईल' : '—' ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if (!empty($attachmentsByLetter)): ?>
    <h3>जोडपत्रे तपशील (Attachments)</h3>
    <?php foreach ($rows as $r): if (empty($attachmentsByLetter[$r['id']])) continue; ?>
      <div class="att-list" style="margin-bottom:6px;">
        <strong><?= h($r['file_number']) ?></strong> — <?= h($r['subject']) ?>:
        <?php
          $attNames = array_map(fn($a) => '📎 ' . h($a['original_name']), $attachmentsByLetter[$r['id']]);
          echo implode(', ', $attNames);
        ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <div style="margin-top:24px;font-size:10.5px;color:var(--text-dim);text-align:right;">तयार केले: <?= date('d M Y, h:i A') ?></div>
</div>

</body>
</html>
