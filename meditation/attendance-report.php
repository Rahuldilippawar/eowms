<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();

$eventId = (int)($_GET['id'] ?? 0);
$orgId = activeOrgId();

$deptCodeCheck = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
$deptCodeCheck->execute([$user['department_id'] ?? 0]);
$userDeptCode = $deptCodeCheck->fetch()['code'] ?? null;
if (!hasRole('super_admin') && $userDeptCode !== 'MED') {
    http_response_code(403);
    die('तुम्हाला सत्संग हजेरी पाहण्याची परवानगी नाही.');
}

$stmt = $pdo->prepare("
    SELECT se.*, d.name_local AS organizer_dept
    FROM satsang_events se LEFT JOIN departments d ON d.id = se.organizing_department_id
    WHERE se.id = ? AND se.organization_id = ?
");
$stmt->execute([$eventId, $orgId]);
$event = $stmt->fetch();
if (!$event) { die('सत्संग कार्यक्रम सापडला नाही.'); }

$org = $pdo->query("SELECT * FROM organizations WHERE id = " . (int)$orgId)->fetch();

$stmt = $pdo->prepare("
    SELECT u.name, u.designation, d.name_local AS dept_name,
      COALESCE(sa.status, 'not_marked') AS status, sa.remark
    FROM users u
    LEFT JOIN departments d ON d.id = u.department_id
    LEFT JOIN satsang_attendance sa ON sa.user_id = u.id AND sa.satsang_event_id = ?
    WHERE u.organization_id = ? AND u.is_active = 1
    ORDER BY d.name_local, u.name
");
$stmt->execute([$eventId, $orgId]);
$rows = $stmt->fetchAll();

$totalPresent = count(array_filter($rows, fn($r) => $r['status'] === 'present'));
$totalAbsent = count(array_filter($rows, fn($r) => $r['status'] === 'absent'));
$totalStaff = count($rows);
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<title>सत्संग हजेरी अहवाल</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{ --ink:#10162B; --rail:#1B8A83; --line:#D7DAE0; --text:#1A1D29; --text-dim:#666B7D; }
  *{box-sizing:border-box;}
  body{font-family:'Noto Sans Devanagari','Segoe UI',sans-serif;color:var(--text);margin:0;background:#F0F1F4;font-size:13px;}
  .toolbar{position:sticky;top:0;background:var(--ink);padding:14px 24px;display:flex;justify-content:space-between;align-items:center;z-index:10;}
  .toolbar .btn{background:var(--rail);color:#fff;border:none;border-radius:8px;padding:9px 20px;font-size:13.5px;font-weight:600;cursor:pointer;font-family:inherit;}
  .doc{max-width:850px;margin:24px auto;background:#fff;padding:36px 44px;border-radius:6px;}
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
  .chip{padding:2px 10px;border-radius:20px;font-size:11px;font-weight:600;}
  .chip.present{background:#DCEFEC;color:#0F5E58;}
  .chip.absent{background:#F7E2E3;color:#8A1F2B;}
  .chip.not_marked{background:#F3F4F7;color:var(--text-dim);}
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
  <a href="attendance.php?id=<?= $eventId ?>" style="color:#fff;text-decoration:none;font-size:13px;">← मागे</a>
  <button class="btn" onclick="window.print()"><i class="fa-solid fa-download"></i> PDF डाउनलोड करा</button>
</div>

<div class="doc">
  <?php if (!empty($org['logo_path'])): ?><div style="text-align:center;"><img src="../<?= h($org['logo_path']) ?>" style="height:56px;"></div><?php endif; ?>
  <div class="org-name"><?= h($org['name'] ?? '') ?></div>
  <div class="report-title">सत्संग हजेरी अहवाल</div>
  <div class="meta">
    <?= date('d M Y — l', strtotime($event['event_date'])) ?>
    <?php if ($event['organizer_dept']): ?> · आयोजक विभाग: <?= h($event['organizer_dept']) ?><?php endif; ?>
  </div>

  <div class="summary-row">
    <div class="summary-box"><div class="lbl">एकूण कर्मचारी</div><div class="val"><?= $totalStaff ?></div></div>
    <div class="summary-box"><div class="lbl">उपस्थित</div><div class="val" style="color:#1B8A83;"><?= $totalPresent ?></div></div>
    <div class="summary-box"><div class="lbl">अनुपस्थित</div><div class="val" style="color:#B23A48;"><?= $totalAbsent ?></div></div>
  </div>

  <table>
    <thead><tr><th>नाव</th><th>विभाग</th><th>पदनाम</th><th>हजेरी</th><th>टिप्पणी</th></tr></thead>
    <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= h($r['name']) ?></td>
          <td><?= h($r['dept_name'] ?? '—') ?></td>
          <td><?= h($r['designation'] ?? '—') ?></td>
          <td><span class="chip <?= $r['status'] ?>"><?= $r['status'] === 'present' ? 'उपस्थित' : ($r['status'] === 'absent' ? 'अनुपस्थित' : 'नोंद नाही') ?></span></td>
          <td><?= h($r['remark'] ?? '—') ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div style="margin-top:24px;font-size:11px;color:var(--text-dim);text-align:right;">तयार केले: <?= date('d M Y, h:i A') ?></div>
</div>

</body>
</html>
