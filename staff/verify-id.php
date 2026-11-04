<?php
// 🪪 ID Card Verification — QR Scan केल्यावर उघडणारं सार्वजनिक पान (Login लागत नाही)
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/id-card.php';

$code = is_string($_GET['code'] ?? null) ? trim($_GET['code']) : '';
$verifyOrgId = (int)($_GET['org'] ?? 0);
$sig = is_string($_GET['s'] ?? null) ? $_GET['s'] : '';
$emp = null;

// सुरक्षा: फक्त कर्मचारी-कोडनेच शोध (id=1,2,3… ने यादी काढता येऊ नये), जास्त प्रयत्नांवर मर्यादा,
// आणि फोटो/विभाग फक्त खऱ्या (Signature जुळलेल्या) QR वरच — कोड अंदाजाने टाकून माहिती मिळू नये.
$limited = publicFormRateLimited('verify_id', 60, 600);
if ($code !== '' && !$limited) {
    publicFormRecordSubmission('verify_id');
    $sql = "SELECT u.name, u.designation, u.is_active, u.photo_path, u.organization_id, u.employee_code,
                   COALESCE(NULLIF(d.name_local, ''), d.name) AS dept_name, c.name AS campus_name, o.name AS org_name, o.logo_path
            FROM users u JOIN organizations o ON o.id = u.organization_id
            LEFT JOIN departments d ON d.id = u.department_id LEFT JOIN campuses c ON c.id = u.campus_id
            WHERE u.employee_code = ?";
    $params = [$code];
    if ($verifyOrgId) { $sql .= " AND u.organization_id = ?"; $params[] = $verifyOrgId; }
    $stmt = $pdo->prepare($sql . " LIMIT 1");
    $stmt->execute($params);
    $emp = $stmt->fetch();
}
$genuine = $emp && $sig !== '' && hash_equals(idCardSig($emp['organization_id'], $emp['employee_code']), $sig);
$state = !$emp ? 'notfound' : (!$genuine ? 'unsigned' : ($emp['is_active'] ? 'valid' : 'inactive'));
$checkedAt = date('d M Y, h:i A');
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>ID Card Verification</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@600;700;800&family=Noto+Sans+Devanagari:wght@400;600;700&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box;}
  body{font-family:'Noto Sans Devanagari','Poppins','Segoe UI',sans-serif;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px 16px;
    background:radial-gradient(circle at 20% 0%,rgba(123,63,158,.55),transparent 50%),radial-gradient(circle at 90% 100%,rgba(240,147,43,.35),transparent 50%),#14112A;}
  .card{background:#fff;max-width:380px;width:100%;border-radius:22px;overflow:hidden;text-align:center;box-shadow:0 30px 70px -20px rgba(0,0,0,.6);animation:up .5s cubic-bezier(.2,.8,.2,1) both;}
  @keyframes up{from{opacity:0;transform:translateY(16px);}to{opacity:1;transform:none;}}
  .top{padding:22px 22px 54px;color:#fff;position:relative;}
  .top.valid{background:linear-gradient(140deg,#0F7A4A,#16A34A);} .top.inactive{background:linear-gradient(140deg,#9F1239,#E11D48);}
  .top.unsigned{background:linear-gradient(140deg,#B45309,#F59E0B);} .top.notfound{background:linear-gradient(140deg,#7F1D1D,#DC2626);}
  .seal{width:64px;height:64px;border-radius:50%;margin:0 auto 10px;display:flex;align-items:center;justify-content:center;font-size:30px;background:rgba(255,255,255,.18);box-shadow:0 0 0 6px rgba(255,255,255,.08);}
  .top.valid .seal{animation:pulse 2s ease-out infinite;} @keyframes pulse{0%{box-shadow:0 0 0 0 rgba(255,255,255,.45);}100%{box-shadow:0 0 0 18px rgba(255,255,255,0);}}
  .status{font-family:'Poppins','Noto Sans Devanagari';font-size:19px;font-weight:800;}
  .status-sub{font-size:12.5px;opacity:.9;margin-top:3px;}
  .photo{width:104px;height:120px;border-radius:16px;margin:-46px auto 0;position:relative;border:4px solid #fff;box-shadow:0 10px 24px -10px rgba(0,0,0,.45);overflow:hidden;background:linear-gradient(150deg,#F4ECF8,#FCE9DE);display:flex;align-items:center;justify-content:center;font-size:40px;font-weight:800;color:#7B3F9E;}
  .photo img{width:100%;height:100%;object-fit:cover;}
  .body{padding:14px 22px 22px;}
  .name{font-family:'Poppins','Noto Sans Devanagari';font-size:19px;font-weight:800;color:#C4293D;}
  .desig{display:inline-block;margin-top:4px;padding:3px 12px;border-radius:99px;background:#FCE9DE;color:#B6420A;font-size:12.5px;font-weight:700;}
  .rows{margin-top:16px;text-align:left;border-top:1px dashed #E6E1EC;}
  .rows div{display:flex;justify-content:space-between;gap:10px;padding:9px 2px;border-bottom:1px dashed #E6E1EC;font-size:13px;}
  .rows span{color:#6B7186;} .rows b{color:#1A1D29;text-align:right;}
  .note{font-size:12px;color:#6B7186;margin-top:14px;line-height:1.6;}
  .warn{background:#FEF3C7;color:#92400E;border-radius:12px;padding:10px 12px;font-size:12.5px;margin-top:14px;text-align:left;line-height:1.6;}
  .foot{font-size:11px;color:#9A97A6;padding:0 22px 18px;}
</style>
</head>
<body>
<div class="card">
  <?php if ($limited): ?>
    <div class="top unsigned"><div class="seal">⏳</div><div class="status">खूप प्रयत्न झाले</div><div class="status-sub">काही मिनिटांनी पुन्हा Scan करा.</div></div>
    <div class="body" style="padding-top:60px;"></div>
  <?php elseif ($state === 'notfound'): ?>
    <div class="top notfound"><div class="seal">✕</div><div class="status">अवैध ID Card</div><div class="status-sub">हे Card आमच्या नोंदीत सापडलं नाही</div></div>
    <div class="body" style="padding-top:28px;"><div class="note">हे Card बनावट असू शकतं किंवा कर्मचारी-कोड बदललेला आहे. कृपया कार्यालयाशी संपर्क साधा.</div></div>
  <?php else: ?>
    <div class="top <?= $state ?>">
      <div class="seal"><?= ['valid' => '✓', 'inactive' => '!', 'unsigned' => '?'][$state] ?></div>
      <div class="status"><?= ['valid' => 'खरं ID Card ✓', 'inactive' => 'कर्मचारी सध्या निष्क्रिय', 'unsigned' => 'Card ची खात्री होत नाही'][$state] ?></div>
      <div class="status-sub"><?= h($emp['org_name']) ?></div>
    </div>
    <div class="photo"><?php if ($genuine && !empty($emp['photo_path'])): ?><img src="../<?= h($emp['photo_path']) ?>" alt=""><?php else: ?><?= h(mb_substr($emp['name'], 0, 1)) ?><?php endif; ?></div>
    <div class="body">
      <div class="name"><?= h($emp['name']) ?></div>
      <?php if ($emp['designation']): ?><div class="desig"><?= h($emp['designation']) ?></div><?php endif; ?>
      <?php if ($genuine): ?>
        <div class="rows">
          <div><span>Employee ID</span><b><?= h($emp['employee_code']) ?></b></div>
          <div><span>विभाग</span><b><?= h($emp['dept_name'] ?: '—') ?></b></div>
          <div><span>Campus</span><b><?= h($emp['campus_name'] ?: '—') ?></b></div>
          <div><span>स्थिती</span><b style="color:<?= $emp['is_active'] ? '#15803D' : '#BE123C' ?>;"><?= $emp['is_active'] ? 'Active — सध्या कार्यरत' : 'Inactive — सध्या कार्यरत नाही' ?></b></div>
        </div>
        <div class="note">फोटो Card वरच्या फोटोशी जुळतो का ते पाहा. ही माहिती थेट संस्थेच्या प्रणालीतून आत्ता घेतली आहे.</div>
      <?php else: ?>
        <div class="warn">⚠️ हा QR जुन्या Card चा आहे किंवा त्यात बदल केलेला आहे, त्यामुळे पूर्ण खात्री करता येत नाही. नवीन Card साठी कार्यालयाशी संपर्क साधा.</div>
      <?php endif; ?>
    </div>
  <?php endif; ?>
  <div class="foot">तपासणी: <?= h($checkedAt) ?> · E-Admin</div>
</div>
</body>
</html>
