<?php
require_once __DIR__ . '/../includes/auth.php';

$code = trim($_GET['code'] ?? '');
$stmt = $pdo->prepare("
    SELECT ls.*, l.subject, l.file_number FROM letter_signatures ls
    JOIN letters l ON l.id = ls.letter_id
    WHERE ls.verification_code = ?
");
$stmt->execute([$code]);
$sig = $stmt->fetch();
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Digital Signature पडताळणी</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&family=Noto+Sans+Devanagari:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box;}
  body{font-family:'Noto Sans Devanagari',sans-serif;background:#10162B;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;}
  .card{background:#fff;border-radius:20px;padding:36px 30px;max-width:420px;width:100%;text-align:center;box-shadow:0 20px 50px rgba(0,0,0,.3);}
  .icon{width:70px;height:70px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 18px;font-size:34px;}
  .icon.valid{background:#DCEFEC;color:#0F5E58;}
  .icon.invalid{background:#F7E2E3;color:#B23A48;}
  h1{font-family:'Space Grotesk';font-size:19px;margin:0 0 6px;color:#10162B;}
  .sub{font-size:13px;color:#666B7D;margin-bottom:22px;}
  .detail-row{display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid #EEF0F4;font-size:13px;text-align:left;}
  .detail-row .lbl{color:#666B7D;}
  .detail-row .val{font-weight:600;color:#1A1D29;}
</style>
</head>
<body>
<div class="card">
  <?php if ($sig): ?>
    <div class="icon valid">✓</div>
    <h1>Digital Signature खरी आहे</h1>
    <div class="sub">हे Approval प्रमाणित आणि विश्वासार्ह आहे</div>
    <div class="detail-row"><span class="lbl">Signed By</span><span class="val"><?= h($sig['signer_name']) ?></span></div>
    <?php if ($sig['signer_designation']): ?><div class="detail-row"><span class="lbl">पदनाम</span><span class="val"><?= h($sig['signer_designation']) ?></span></div><?php endif; ?>
    <div class="detail-row"><span class="lbl">पत्र</span><span class="val"><?= h($sig['file_number']) ?></span></div>
    <div class="detail-row"><span class="lbl">दिनांक/वेळ</span><span class="val"><?= date('d M Y, h:i A', strtotime($sig['signed_at'])) ?></span></div>
    <div class="detail-row"><span class="lbl">पडताळणी पद्धत</span><span class="val"><?= $sig['verification_method'] === 'fingerprint' ? '👆 Fingerprint/Face ID' : '🔑 Password' ?></span></div>
    <div class="detail-row" style="border-bottom:none;"><span class="lbl">Verification ID</span><span class="val font-mono">#<?= h($sig['verification_code']) ?></span></div>
  <?php else: ?>
    <div class="icon invalid">✕</div>
    <h1>अवैध किंवा सापडली नाही</h1>
    <div class="sub">हा Verification Code बरोबर नाही, किंवा असं कुठलंही Digital Signature अस्तित्वात नाही.</div>
  <?php endif; ?>
</div>
</body>
</html>
