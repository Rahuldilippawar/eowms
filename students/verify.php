<?php
// 🎓🪪 विद्यार्थी ID Card तपासणी — QR Scan केल्यावर उघडणारं सार्वजनिक पान (Login लागत नाही).
// अल्पवयीन मुलांची माहिती: Signature जुळलं तरच नाव/फोटो/वर्ग; मोबाईल, पत्ता, जन्मतारीख कधीच दाखवत नाही.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/photo-helpers.php';
require_once __DIR__ . '/../includes/student-id-card.php';

$orgId = (int)($_GET['org'] ?? 0);
$id = (int)($_GET['id'] ?? 0);
$sig = is_string($_GET['s'] ?? null) ? $_GET['s'] : '';
$stu = null;
$limited = publicFormRateLimited('verify_student_id', 60, 600);
if ($id && $orgId && $sig !== '' && !$limited) {
    publicFormRecordSubmission('verify_student_id');
    try {
        $st = $pdo->prepare("SELECT s.id, s.name, s.admission_no, s.class_name, s.division, s.course, s.academic_year, s.is_active, s.photo_path, s.organization_id, c.name campus_name, o.name org_name
            FROM students s JOIN organizations o ON o.id = s.organization_id LEFT JOIN campuses c ON c.id = s.campus_id WHERE s.id = ? AND s.organization_id = ?");
        $st->execute([$id, $orgId]);
        $stu = $st->fetch();
    } catch (PDOException $e) { $stu = null; }
}
$genuine = $stu && hash_equals(studentCardSig($stu['organization_id'], $stu['id'], $stu['admission_no']), $sig);
$state = $limited ? 'limited' : (!$genuine ? 'invalid' : ($stu['is_active'] ? 'valid' : 'inactive'));
$cls = $genuine ? trim(($stu['class_name'] ?? '') . ($stu['division'] ? ' - ' . $stu['division'] : '') . ($stu['course'] ? ' · ' . $stu['course'] : '')) : '';
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>Student ID Verification</title>
<style>
  *{box-sizing:border-box;}
  body{font-family:'Noto Sans Devanagari','Segoe UI',sans-serif;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px 16px;background:radial-gradient(circle at 20% 0%,rgba(21,101,192,.55),transparent 50%),#0E1A2E;}
  .card{background:#fff;max-width:380px;width:100%;border-radius:22px;overflow:hidden;text-align:center;box-shadow:0 30px 70px -20px rgba(0,0,0,.6);}
  .top{padding:22px 22px 54px;color:#fff;}
  .top.valid{background:linear-gradient(140deg,#0F7A4A,#16A34A);} .top.inactive{background:linear-gradient(140deg,#9F1239,#E11D48);} .top.invalid,.top.limited{background:linear-gradient(140deg,#7F1D1D,#DC2626);}
  .seal{width:64px;height:64px;border-radius:50%;margin:0 auto 10px;display:flex;align-items:center;justify-content:center;font-size:30px;background:rgba(255,255,255,.18);}
  .status{font-size:19px;font-weight:800;} .status-sub{font-size:12.5px;opacity:.9;margin-top:3px;}
  .photo{width:104px;height:120px;border-radius:16px;margin:-46px auto 0;border:4px solid #fff;box-shadow:0 10px 24px -10px rgba(0,0,0,.45);overflow:hidden;background:#E6F0FB;display:flex;align-items:center;justify-content:center;font-size:40px;font-weight:800;color:#1565C0;}
  .photo img{width:100%;height:100%;object-fit:cover;}
  .body{padding:14px 22px 22px;} .name{font-size:19px;font-weight:800;color:#0B3C78;}
  .rows{margin-top:16px;text-align:left;border-top:1px dashed #E1E6EE;}
  .rows div{display:flex;justify-content:space-between;gap:10px;padding:9px 2px;border-bottom:1px dashed #E1E6EE;font-size:13px;} .rows span{color:#6B7186;} .rows b{text-align:right;}
  .note{font-size:12px;color:#6B7186;margin-top:14px;line-height:1.6;} .foot{font-size:11px;color:#9A97A6;padding:0 22px 18px;}
</style>
</head>
<body>
<div class="card">
  <?php if (!$genuine): ?>
    <div class="top <?= $state ?>"><div class="seal"><?= $state === 'limited' ? '⏳' : '✕' ?></div><div class="status"><?= $state === 'limited' ? 'खूप प्रयत्न झाले' : 'अवैध ID Card' ?></div><div class="status-sub"><?= $state === 'limited' ? 'काही मिनिटांनी पुन्हा Scan करा.' : 'हे Card आमच्या नोंदीत जुळत नाही' ?></div></div>
    <div class="body" style="padding-top:28px;"><div class="note">Card बनावट असू शकतं, किंवा प्रवेश क्रमांक बदलल्यामुळे जुनं झालं आहे. कृपया शाळा/कॉलेज कार्यालयाशी संपर्क साधा.</div></div>
  <?php else: ?>
    <div class="top <?= $state ?>"><div class="seal"><?= $state === 'valid' ? '✓' : '!' ?></div><div class="status"><?= $state === 'valid' ? 'खरं विद्यार्थी ID Card ✓' : 'विद्यार्थी सध्या शिकत नाही' ?></div><div class="status-sub"><?= h($stu['campus_name'] ?: $stu['org_name']) ?></div></div>
    <div class="photo"><?php if (!empty($stu['photo_path']) && eowmsPhotoExists($stu['photo_path'])): ?><img src="../<?= h($stu['photo_path']) ?>" alt=""><?php else: ?><?= h(mb_substr($stu['name'], 0, 1)) ?><?php endif; ?></div>
    <div class="body">
      <div class="name"><?= h($stu['name']) ?></div>
      <div class="rows">
        <div><span>प्रवेश क्र. (Gr.No.)</span><b><?= h($stu['admission_no']) ?></b></div>
        <?php if ($cls !== ''): ?><div><span>वर्ग</span><b><?= h($cls) ?></b></div><?php endif; ?>
        <div><span>शैक्षणिक वर्ष</span><b><?= h($stu['academic_year']) ?></b></div>
        <div><span>स्थिती</span><b style="color:<?= $stu['is_active'] ? '#15803D' : '#BE123C' ?>;"><?= $stu['is_active'] ? 'सध्या शिकत आहे' : 'शाळा/कॉलेज सोडले' ?></b></div>
      </div>
      <div class="note">फोटो Card वरच्या फोटोशी जुळतो का ते पाहा. ही माहिती थेट संस्थेच्या प्रणालीतून आत्ता घेतली आहे.</div>
    </div>
  <?php endif; ?>
  <div class="foot">तपासणी: <?= h(date('d M Y, h:i A')) ?> · E-Admin</div>
</div>
</body>
</html>
