<?php
require_once __DIR__ . '/../includes/student-auth.php';
requireStudentLogin('');
$student = currentStudent();
function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES); }

// Password अजून बदलली नसेल तर — Dashboard सुद्धा दाखवायच्या आधी अडवतो
$currentFile = basename($_SERVER['PHP_SELF']);
if (!empty($student['must_change_password']) && $currentFile !== 'change-password.php' && $currentFile !== 'logout.php') {
    header('Location: change-password.php');
    exit;
}

$unreadStmt = $pdo->prepare("SELECT * FROM student_notifications WHERE student_id = ? ORDER BY created_at DESC LIMIT 10");
$unreadStmt->execute([$student['id']]);
$notifications = $unreadStmt->fetchAll();
$unreadCountStmt = $pdo->prepare("SELECT COUNT(*) c FROM student_notifications WHERE student_id = ? AND is_read = 0");
$unreadCountStmt->execute([$student['id']]);
$unreadCount = (int)$unreadCountStmt->fetch()['c'];
// आत्ता दाखवल्यावर — पुढच्या वेळी Badge रिकामा दिसावा म्हणून Read चिन्हांकित करतो
$pdo->prepare("UPDATE student_notifications SET is_read = 1 WHERE student_id = ? AND is_read = 0")->execute([$student['id']]);
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>विद्यार्थी Portal</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{ --ink:#10162B; --rail:#1B8A83; --amber:#C97F1D; --rose:#B23A48; --line:#D7DAE0; --text-dim:#666B7D; --canvas:#F7F8FA; --rail-soft:#DCEFEC; --rose-soft:#F7E2E3; }
  *{box-sizing:border-box;}
  body{font-family:'Noto Sans Devanagari','Segoe UI',sans-serif;margin:0;background:var(--canvas);color:#1A1D29;}
  .topbar{background:var(--ink);padding:14px 20px;display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;z-index:10;}
  .topbar .brand{color:#fff;font-family:'Space Grotesk';font-weight:700;font-size:15px;}
  .topbar a{color:#B7BBD6;text-decoration:none;font-size:12.5px;margin-left:16px;}
  .wrap{max-width:760px;margin:0 auto;padding:24px 18px 60px;}
  .page-title{font-family:'Space Grotesk';font-weight:700;font-size:20px;margin-bottom:2px;}
  .sub{color:var(--text-dim);font-size:13px;margin:0 0 20px;}
  .section-label{font-size:12px;font-weight:700;color:var(--text-dim);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;}
  .panel{background:#fff;border:1px solid var(--line);border-radius:14px;overflow:hidden;margin-bottom:16px;}
  .card-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;}
  .classroom-card{background:#fff;border:1px solid var(--line);border-radius:14px;padding:18px;text-decoration:none;color:inherit;display:block;transition:transform .15s ease;}
  .classroom-card:hover{transform:translateY(-2px);box-shadow:0 8px 20px rgba(16,22,43,.08);}
  .cc-name{font-weight:700;font-size:14px;}
  .cc-year{font-size:11.5px;color:var(--text-dim);margin-top:4px;}
  .list-row{display:flex;justify-content:space-between;align-items:center;padding:13px 18px;border-bottom:1px solid var(--line);text-decoration:none;color:inherit;}
  .list-row:last-child{border-bottom:none;}
  .lr-title{font-size:13.5px;font-weight:600;}
  .lr-sub{font-size:11.5px;color:var(--text-dim);margin-top:2px;}
  .lr-due{font-size:11px;color:var(--amber);font-weight:600;white-space:nowrap;}
  .alert-success{background:var(--rail-soft);color:var(--rail);padding:11px 16px;border-radius:10px;font-size:13px;margin-bottom:16px;}
  .btn-primary{background:var(--rail);color:#fff;border:none;border-radius:9px;padding:10px 20px;font-size:13.5px;font-weight:600;cursor:pointer;text-decoration:none;display:inline-block;font-family:inherit;}
  .btn-outline{border:1px solid var(--line);border-radius:9px;padding:9px 18px;font-size:13px;text-decoration:none;color:inherit;display:inline-block;background:#fff;}
  .notif-bell{position:relative;cursor:pointer;color:#B7BBD6;font-size:16px;margin-left:16px;}
  .notif-badge{position:absolute;top:-6px;right:-8px;background:var(--rose);color:#fff;font-size:9px;font-weight:700;border-radius:20px;padding:1px 5px;min-width:14px;text-align:center;}
  .notif-dropdown{display:none;position:absolute;top:38px;right:0;background:#fff;border-radius:12px;box-shadow:0 12px 30px rgba(0,0,0,.18);width:300px;max-height:360px;overflow-y:auto;z-index:20;}
  .notif-dropdown.show{display:block;}
  .notif-item{padding:11px 16px;border-bottom:1px solid var(--line);font-size:12px;color:#1A1D29;}
  .notif-item .nt{font-weight:600;}
  .notif-item .nm{color:var(--text-dim);margin-top:2px;}
</style>
</head>
<body>
  <div class="topbar">
    <span class="brand">🎓 विद्यार्थी Portal</span>
    <div style="display:flex;align-items:center;">
      <a href="dashboard.php"><i class="fa-solid fa-house"></i> Home</a>
      <a href="change-password.php"><i class="fa-solid fa-key"></i> Password</a>
      <span class="notif-bell" onclick="document.getElementById('notifDropdown').classList.toggle('show')">
        <i class="fa-solid fa-bell"></i>
        <?php if ($unreadCount > 0): ?><span class="notif-badge"><?= $unreadCount > 9 ? '9+' : $unreadCount ?></span><?php endif; ?>
        <div class="notif-dropdown" id="notifDropdown">
          <?php if (empty($notifications)): ?><div class="notif-item" style="text-align:center;color:var(--text-dim);">काहीही Notification नाही</div><?php endif; ?>
          <?php foreach ($notifications as $n): ?>
            <a href="<?= h($n['link'] ?: '#') ?>" style="text-decoration:none;color:inherit;display:block;">
              <div class="notif-item"><div class="nt"><?= h($n['title']) ?></div><?php if ($n['message']): ?><div class="nm"><?= h($n['message']) ?></div><?php endif; ?></div>
            </a>
          <?php endforeach; ?>
        </div>
      </span>
      <a href="logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </div>
  </div>
  <div class="wrap">
