<?php
require_once __DIR__ . '/../includes/auth.php';

$code = is_string($_GET['code'] ?? null) ? trim($_GET['code']) : '';
$verifyOrgId = (int)($_GET['org'] ?? 0);
$emp = null;

// सुरक्षा: फक्त कर्मचारी-कोडनेच शोध (क्रमांकाने — id=1,2,3... — सगळ्यांची यादी
// काढता येऊ नये), Card वर संस्था दिलेली असेल तर त्याच संस्थेत, आणि अगदी कमी माहिती.
if ($code !== '') {
    $sql = "
        SELECT u.name, u.designation, u.is_active, o.name AS org_name
        FROM users u
        JOIN organizations o ON o.id = u.organization_id
        WHERE u.employee_code = ?";
    $params = [$code];
    if ($verifyOrgId) { $sql .= " AND u.organization_id = ?"; $params[] = $verifyOrgId; }
    $stmt = $pdo->prepare($sql . " LIMIT 1");
    $stmt->execute($params);
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
    <div class="sub"><?= htmlspecialchars($emp['designation'] ?? '') ?></div>
    <?php if ($emp['is_active']): ?>
      <div class="badge valid">✓ वैध कर्मचारी (Active)</div>
    <?php else: ?>
      <div class="badge inactive">⚠ हे खाते सध्या निष्क्रिय आहे</div>
    <?php endif; ?>
  <?php endif; ?>
</div>
</body>
</html>
