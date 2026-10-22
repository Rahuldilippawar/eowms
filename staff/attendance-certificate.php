<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();
$orgId = activeOrgId();
$isMgmt = hasPermission('view_org_wide_dashboards') || hasRole('super_admin');
$canManage = $isMgmt || hasPermission('manage_attendance');

$targetUserId = (int)($_GET['user_id'] ?? $user['id']);
$month = $_GET['month'] ?? date('Y-m');

if ($targetUserId !== (int)$user['id'] && !$canManage) {
    http_response_code(403);
    die('तुम्ही फक्त स्वतःचं प्रमाणपत्र बघू शकता.');
}

$org = $pdo->query("SELECT * FROM organizations WHERE id = " . (int)$orgId)->fetch();
$targetStmt = $pdo->prepare("SELECT u.*, d.name_local AS dept_name FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.id = ? AND u.organization_id = ?");
$targetStmt->execute([$targetUserId, $orgId]);
$targetUser = $targetStmt->fetch();
if (!$targetUser) { die('व्यक्ती सापडली नाही.'); }

$summaryStmt = $pdo->prepare("
    SELECT
      SUM(CASE WHEN status = 'present' THEN 1 ELSE 0 END) AS present_count,
      SUM(CASE WHEN status = 'absent' THEN 1 ELSE 0 END) AS absent_count,
      SUM(CASE WHEN status = 'half_day' THEN 1 ELSE 0 END) AS half_day_count,
      SUM(CASE WHEN status = 'holiday' THEN 1 ELSE 0 END) AS holiday_count,
      SUM(CASE WHEN status = 'on_leave' THEN 1 ELSE 0 END) AS leave_count,
      SUM(CASE WHEN is_late = 1 THEN 1 ELSE 0 END) AS late_count,
      COUNT(*) AS total_marked
    FROM staff_attendance WHERE user_id = ? AND DATE_FORMAT(attendance_date, '%Y-%m') = ?
");
$summaryStmt->execute([$targetUserId, $month]);
$summary = $summaryStmt->fetch();

$monthLabel = date('F Y', strtotime($month . '-01'));
$totalDaysInMonth = (int)date('t', strtotime($month . '-01'));
$effectivePresent = (float)($summary['present_count'] ?? 0) + (0.5 * (float)($summary['half_day_count'] ?? 0));
$attendancePct = $totalDaysInMonth > 0 ? round(($effectivePresent / $totalDaysInMonth) * 100, 1) : 0;
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<title>Attendance Certificate — <?= h($targetUser['name']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{ --ink:#10162B; --rail:#1B8A83; --line:#D7DAE0; --text:#1A1D29; --text-dim:#666B7D; }
  *{box-sizing:border-box;}
  body{font-family:'Noto Sans Devanagari','Segoe UI',sans-serif;color:var(--text);margin:0;background:#F0F1F4;font-size:13px;}
  .toolbar{position:sticky;top:0;background:var(--ink);padding:14px 24px;display:flex;justify-content:space-between;align-items:center;z-index:10;flex-wrap:wrap;gap:10px;}
  .toolbar a{color:#fff;text-decoration:none;font-size:13px;}
  .toolbar .btn{background:var(--rail);color:#fff;border:none;border-radius:8px;padding:9px 20px;font-size:13.5px;font-weight:600;cursor:pointer;font-family:inherit;text-decoration:none;display:inline-block;}
  .doc{max-width:760px;margin:24px auto;background:#fff;padding:44px 52px;border-radius:6px;border:1px solid var(--line);}
  .org-name{font-family:'Space Grotesk';font-weight:700;font-size:21px;color:var(--ink);text-align:center;}
  .report-title{text-align:center;font-family:'Space Grotesk';font-weight:600;font-size:16px;margin-top:8px;color:var(--rail);letter-spacing:.5px;text-transform:uppercase;}
  .divider{border:none;border-top:2px solid var(--rail);margin:20px 0 28px;}
  .cert-body{font-size:14px;line-height:2;text-align:center;padding:0 10px;}
  .cert-body strong{color:var(--ink);}
  .stats-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin:30px 0;}
  .stat-box{border:1px solid var(--line);border-radius:10px;padding:16px;text-align:center;}
  .stat-box .val{font-family:'Space Grotesk';font-size:24px;font-weight:700;color:var(--ink);}
  .stat-box .lbl{font-size:11px;color:var(--text-dim);margin-top:4px;}
  .footer-sign{display:flex;justify-content:space-between;margin-top:70px;font-size:12px;}
  .footer-sign div{text-align:center;border-top:1px solid var(--text-dim);padding-top:6px;width:180px;}
  .gen-date{text-align:center;font-size:10.5px;color:var(--text-dim);margin-top:40px;}
  @media print{ .toolbar{display:none;} body{background:#fff;} .doc{margin:0;border:none;} }
</style>
</head>
<body>

<div class="toolbar">
  <a href="attendance-summary.php">← मागे</a>
  <button class="btn" onclick="window.print()">📄 PDF डाउनलोड करा (Print → Save as PDF)</button>
</div>

<div class="doc">
  <?php if (!empty($org['logo_path'])): ?><div style="text-align:center;margin-bottom:10px;"><img src="../<?= h($org['logo_path']) ?>" style="height:56px;"></div><?php endif; ?>
  <div class="org-name"><?= h($org['name'] ?? '') ?></div>
  <div class="report-title">Attendance Certificate</div>
  <hr class="divider">

  <div class="cert-body">
    हे प्रमाणित करण्यात येते की <strong><?= h($targetUser['name']) ?></strong>
    (<?= h($targetUser['designation'] ?: '') ?><?= $targetUser['dept_name'] ? ', ' . h($targetUser['dept_name']) : '' ?>)
    यांनी <strong><?= h($monthLabel) ?></strong> या महिन्यात
    <strong><?= $effectivePresent ?> पैकी <?= $totalDaysInMonth ?> दिवस (<?= $attendancePct ?>%)</strong> हजेरी लावली आहे.
  </div>

  <div class="stats-grid">
    <div class="stat-box"><div class="val" style="color:var(--rail);"><?= $summary['present_count'] ?? 0 ?></div><div class="lbl">✅ हजर</div></div>
    <div class="stat-box"><div class="val" style="color:#B23A48;"><?= $summary['absent_count'] ?? 0 ?></div><div class="lbl">❌ गैरहजर</div></div>
    <div class="stat-box"><div class="val" style="color:#C97F1D;"><?= $summary['half_day_count'] ?? 0 ?></div><div class="lbl">🟡 अर्धा दिवस</div></div>
    <div class="stat-box"><div class="val" style="color:#8A5CD6;"><?= $summary['leave_count'] ?? 0 ?></div><div class="lbl">🏖️ रजा</div></div>
    <div class="stat-box"><div class="val" style="color:#5D5FA6;"><?= $summary['holiday_count'] ?? 0 ?></div><div class="lbl">🎉 सुट्टी</div></div>
    <div class="stat-box"><div class="val" style="color:var(--text-dim);"><?= $summary['late_count'] ?? 0 ?></div><div class="lbl">⏰ उशीर</div></div>
  </div>

  <div class="footer-sign">
    <div>विभाग-प्रमुख (HOD)</div>
    <div>Super Admin / HR</div>
  </div>

  <div class="gen-date">हे प्रमाणपत्र System मधून आपोआप तयार केलं आहे — <?= date('d M Y, h:i A') ?></div>
</div>

</body>
</html>
