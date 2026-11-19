<?php
// 🚌 बस पास तपासणी — QR Scan केल्यावर (Login लागत नाही). Signature जुळलं तरच माहिती; मोबाईल/पत्ता/जन्मतारीख कधीच नाही.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/photo-helpers.php';
require_once __DIR__ . '/../includes/students-helpers.php';
require_once __DIR__ . '/../includes/student-bus-pass.php';

$orgId = (int)($_GET['org'] ?? 0);
$id = (int)($_GET['id'] ?? 0);
$bsId = (int)($_GET['bs'] ?? 0); // पास न दिलेला बस विद्यार्थी — शैक्षणिक वर्षाचा पास
$ay = is_string($_GET['ay'] ?? null) ? substr($_GET['ay'], 0, 10) : '';
$sig = is_string($_GET['s'] ?? null) ? $_GET['s'] : '';
$p = null;
$limited = publicFormRateLimited('verify_bus_pass', 60, 600);
if ($id && $orgId && $sig !== '' && !$limited) {
    publicFormRecordSubmission('verify_bus_pass');
    try {
        $st = $pdo->prepare("SELECT x.*, o.name org_name FROM (" . busPassSelectSql() . " WHERE p.id = ? AND p.organization_id = ?) x JOIN organizations o ON o.id = x.organization_id");
        $st->execute([$id, $orgId]);
        $p = $st->fetch();
    } catch (PDOException $e) { $p = null; }
}
if (!$id && $bsId && $orgId && $sig !== '' && !$limited) {
    publicFormRecordSubmission('verify_bus_pass');
    try {
        $st = $pdo->prepare("SELECT s.*, s.is_active stu_active, c.name campus_name, o.name org_name FROM bus_students s JOIN organizations o ON o.id = s.organization_id LEFT JOIN campuses c ON c.id = s.campus_id WHERE s.id = ? AND s.organization_id = ?");
        $st->execute([$bsId, $orgId]);
        if ($p = $st->fetch()) {
            [$f0, $t0] = busAcademicRange($ay);
            $p += ['pass_no' => 'AY ' . $ay, 'valid_from' => $f0, 'valid_till' => $t0, 'status' => 'active'];
            if (!$p['from_place']) $p['from_place'] = busSource($pdo, $orgId);
            $p['ay'] = $ay;
        }
    } catch (PDOException $e) { $p = null; }
}
$genuine = $p && (!empty($p['ay']) ? hash_equals(busStudentSig($p['organization_id'], $p['id'], $p['ay']), $sig) : hash_equals(busPassSig($p['organization_id'], $p['id'], $p['valid_till']), $sig));
$state = $limited ? 'limited' : (!$genuine ? 'invalid' : busPassState($p));
if ($genuine && !$p['stu_active'] && in_array($state, ['active', 'expiring', 'upcoming'], true)) $state = 'cancelled';
$ok = in_array($state, ['active', 'expiring'], true);
$cls = $genuine ? trim(($p['class_name'] ?? '') . ($p['division'] ? ' - ' . $p['division'] : '') . ($p['course'] ? ' · ' . $p['course'] : '')) : '';
$title = ['limited' => 'खूप प्रयत्न झाले', 'invalid' => 'अवैध बस पास', 'active' => 'बस पास चालू ✓', 'expiring' => 'बस पास चालू ✓ (लवकरच संपतो)', 'upcoming' => 'पास अजून सुरू झाला नाही', 'expired' => 'बस पास संपला', 'cancelled' => 'बस पास रद्द'][$state];
$tone = $ok ? 'valid' : ($state === 'upcoming' || $state === 'expiring' ? 'warnx' : 'invalid');
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>Bus Pass Verification</title>
<style>
  *{box-sizing:border-box;}
  body{font-family:'Noto Sans Devanagari','Segoe UI',sans-serif;margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px 16px;background:radial-gradient(circle at 20% 0%,rgba(21,101,192,.55),transparent 50%),#0E1A2E;}
  .card{background:#fff;max-width:380px;width:100%;border-radius:22px;overflow:hidden;text-align:center;box-shadow:0 30px 70px -20px rgba(0,0,0,.6);}
  .top{padding:22px 22px 54px;color:#fff;}
  .top.valid{background:linear-gradient(140deg,#0F7A4A,#16A34A);} .top.inactive{background:linear-gradient(140deg,#9F1239,#E11D48);} .top.warnx{background:linear-gradient(140deg,#B45309,#F59E0B);} .top.invalid,.top.limited{background:linear-gradient(140deg,#7F1D1D,#DC2626);}
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
  <div class="top <?= $tone ?>"><div class="seal"><?= $ok ? '🚌' : ($state === 'limited' ? '⏳' : '✕') ?></div><div class="status"><?= h($title) ?></div>
    <div class="status-sub"><?= $genuine ? h($p['campus_name'] ?: $p['org_name']) : ($state === 'limited' ? 'काही मिनिटांनी पुन्हा Scan करा.' : 'हा पास आमच्या नोंदीत जुळत नाही') ?></div></div>
  <?php if (!$genuine): ?>
    <div class="body" style="padding-top:28px;"><div class="note">पास बनावट असू शकतो किंवा त्यात बदल केला आहे. कृपया शाळा/कॉलेज कार्यालयाशी संपर्क साधा.</div></div>
  <?php else: ?>
    <div class="photo"><?php if (!empty($p['photo_path']) && eowmsPhotoExists($p['photo_path'])): ?><img src="../<?= h($p['photo_path']) ?>" alt=""><?php else: ?><?= h(mb_substr($p['name'], 0, 1)) ?><?php endif; ?></div>
    <div class="body">
      <div class="name"><?= h($p['name']) ?></div>
      <div class="rows">
        <div><span>पास क्र.</span><b><?= h($p['pass_no']) ?></b></div>
        <div><span>मार्ग (जाणे-येणे)</span><b><?= h($p['from_place']) ?> ⇄ <?= h($p['to_place']) ?></b></div>
        <?php if ($p['route_name']): ?><div><span>मार्ग / बस</span><b><?= h($p['route_name']) ?></b></div><?php endif; ?>
        <div><span>Valid</span><b style="color:<?= $ok ? '#15803D' : '#BE123C' ?>;"><?= !empty($p['ay']) ? 'शैक्षणिक वर्ष ' . h($p['ay']) . ' · ' . h(busAyLabel($p['ay'])) : h(date('d/m/Y', strtotime($p['valid_from']))) . ' → ' . h(date('d/m/Y', strtotime($p['valid_till']))) ?></b></div>
        <?php if ($p['class_name']): ?><div><span>Class</span><b><?= h($p['class_name']) ?></b></div><?php endif; ?>
        <?php if ($p['division'] || $p['course']): ?><div><span>Division</span><b><?= h($p['division'] ?: $p['course']) ?></b></div><?php endif; ?>
        <div><span>Reg No</span><b><?= h($p['admission_no']) ?></b></div>
      </div>
      <div class="note">फोटो पासवरच्या फोटोशी जुळतो का ते पाहा. ही माहिती थेट संस्थेच्या प्रणालीतून आत्ता घेतली आहे.</div>
    </div>
  <?php endif; ?>
  <div class="foot">तपासणी: <?= h(date('d M Y, h:i A')) ?> · E-Admin</div>
</div>
</body>
</html>
