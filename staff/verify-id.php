<?php
require_once __DIR__ . '/../includes/auth.php';

$code = trim($_GET['code'] ?? '');
$emp = null;

if ($code) {
    $stmt = $pdo->prepare("
        SELECT u.name, u.designation, u.is_active, d.name_local AS dept_name, o.name AS org_name, o.logo_path
        FROM users u
        LEFT JOIN departments d ON d.id = u.department_id
        LEFT JOIN organizations o ON o.id = u.organization_id
        WHERE u.employee_code = ? OR u.id = ?
    ");
    $stmt->execute([$code, is_numeric($code) ? (int)$code : 0]);
    $emp = $stmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ID Card Verification</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@700&family=Noto+Sans+Devanagari:wght@400;600;700&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box;}
  body{font-family:'Noto Sans Devanagari','Segoe UI',sans-serif;background:#10162B;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;}
  .card{background:#fff;max-width:380px;width:100%;border-radius:16px;padding:32px 28px;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.3);}
  .icon{font-size:44px;margin-bottom:12px;}
  h1{font-family:'Space Grotesk';font-size:18px;margin:0 0 6px;color:#10162B;}
  .name{font-size:16px;font-weight:700;color:#1B8A83;margin:14px 0 2px;}
  .sub{font-size:13px;color:#666B7D;}
  .badge{display:inline-block;margin-top:14px;padding:6px 16px;border-radius:20px;font-size:12px;font-weight:700;}
  .badge.valid{background:#DCEFEC;color:#0F5E58;}
  .badge.inactive{background:#F7E2E3;color:#8A1F2B;}
  .err{color:#8A1F2B;font-size:14px;}
</style>
</head>
<body>
<div class="card">
  <?php if (!$emp): ?>
    <div class="icon">❌</div>
    <h1>अवैध / सापडले नाही</h1>
    <p class="err">हे ID Card आमच्या नोंदीत सापडले नाही.</p>
  <?php else: ?>
    <div class="icon">✅</div>
    <h1><?= htmlspecialchars($emp['org_name'] ?? '') ?></h1>
    <div class="name"><?= htmlspecialchars($emp['name']) ?></div>
    <div class="sub"><?= htmlspecialchars($emp['designation'] ?? '') ?><?= $emp['dept_name'] ? ' — ' . htmlspecialchars($emp['dept_name']) : '' ?></div>
    <?php if ($emp['is_active']): ?>
      <div class="badge valid">✓ वैध कर्मचारी (Active)</div>
    <?php else: ?>
      <div class="badge inactive">⚠ हे खाते सध्या निष्क्रिय आहे</div>
    <?php endif; ?>
  <?php endif; ?>
</div>
</body>
</html>
