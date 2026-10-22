<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$orgId = activeOrgId();

$eventId = (int)($_GET['event_id'] ?? 0);
$attendanceId = (int)($_GET['attendance_id'] ?? 0);

$stmt = $pdo->prepare("
    SELECT ea.*, u.name AS staff_name, e.name AS event_name, e.event_date, e.venue
    FROM event_attendance ea JOIN events e ON e.id = ea.event_id LEFT JOIN users u ON u.id = ea.user_id
    WHERE ea.id = ? AND ea.event_id = ? AND e.organization_id = ?
");
$stmt->execute([$attendanceId, $eventId, $orgId]);
$att = $stmt->fetch();
if (!$att) { die('सापडलं नाही.'); }

$participantName = $att['attendee_type'] === 'staff' ? $att['staff_name'] : $att['guest_name'];
$org = $pdo->query("SELECT * FROM organizations WHERE id = " . (int)$orgId)->fetch();
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<title>Certificate — <?= htmlspecialchars($participantName) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&family=Noto+Sans+Devanagari:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box;}
  body{font-family:'Noto Sans Devanagari','Segoe UI',sans-serif;background:#F0F1F4;margin:0;color:#1A1D29;}
  .toolbar{position:sticky;top:0;background:#10162B;padding:14px 24px;display:flex;justify-content:space-between;align-items:center;z-index:10;}
  .toolbar a{color:#fff;text-decoration:none;font-size:13px;}
  .toolbar button{background:#1B8A83;color:#fff;border:none;border-radius:8px;padding:9px 20px;font-size:13.5px;font-weight:600;cursor:pointer;font-family:inherit;}
  .cert-wrap{max-width:850px;margin:30px auto;background:#fff;padding:50px;border:2px solid #C97F1D;border-radius:8px;position:relative;box-shadow:0 20px 50px rgba(0,0,0,.1);}
  .cert-wrap::before{content:'';position:absolute;inset:14px;border:1px solid #C97F1D;border-radius:4px;pointer-events:none;}
  .cert-inner{text-align:center;padding:20px;}
  .org-name{font-family:'Space Grotesk';font-weight:700;font-size:18px;color:#10162B;letter-spacing:.5px;}
  .cert-title{font-family:'Playfair Display';font-weight:700;font-size:34px;color:#C97F1D;margin:18px 0 6px;}
  .cert-sub{font-size:13px;color:#666B7D;letter-spacing:2px;text-transform:uppercase;margin-bottom:30px;}
  .cert-body{font-size:15px;line-height:2;}
  .participant-name{font-family:'Playfair Display';font-weight:700;font-size:26px;color:#1B8A83;margin:14px 0;border-bottom:2px solid #DCEFEC;display:inline-block;padding-bottom:6px;}
  .event-name{font-weight:700;color:#10162B;}
  .cert-footer{display:flex;justify-content:space-between;margin-top:60px;padding:0 30px;}
  .sign-block{text-align:center;font-size:12px;}
  .sign-line{border-top:1px solid #1A1D29;width:160px;padding-top:6px;margin-top:40px;}
  @media print { .toolbar{display:none;} body{background:#fff;} .cert-wrap{margin:0;box-shadow:none;} }
</style>
</head>
<body>
  <div class="toolbar">
    <a href="view.php?id=<?= $eventId ?>">← मागे</a>
    <button onclick="window.print()">📄 Print / Save as PDF</button>
  </div>
  <div class="cert-wrap">
    <div class="cert-inner">
      <?php if (!empty($org['logo_path'])): ?><img src="../<?= htmlspecialchars($org['logo_path']) ?>" style="height:56px;margin-bottom:12px;"><?php endif; ?>
      <div class="org-name"><?= htmlspecialchars($org['name'] ?? '') ?></div>
      <div class="cert-title">प्रमाणपत्र</div>
      <div class="cert-sub">Certificate of Participation</div>
      <div class="cert-body">
        हे प्रमाणित करण्यात येते की<br>
        <div class="participant-name"><?= htmlspecialchars($participantName) ?></div><br>
        यांनी <span class="event-name"><?= htmlspecialchars($att['event_name']) ?></span> या कार्यक्रमात<br>
        दिनांक <?= date('d M Y', strtotime($att['event_date'])) ?><?= $att['venue'] ? ', ' . htmlspecialchars($att['venue']) . ' येथे' : '' ?> सहभाग घेतला.
      </div>
      <div class="cert-footer">
        <div class="sign-block"><div class="sign-line">कार्यक्रम प्रमुख</div></div>
        <div class="sign-block"><div class="sign-line">Super Admin</div></div>
      </div>
    </div>
  </div>
</body>
</html>
