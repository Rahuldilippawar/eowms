<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();

$id = (int)($_GET['id'] ?? 0);
$orgId = activeOrgId();

// Anyone can view/print their own card; Management/Super Admin can print anyone's
$stmt = $pdo->prepare("
    SELECT u.*, d.name_local AS dept_name, c.name AS campus_name, c.address AS campus_address, c.city AS campus_city
    FROM users u LEFT JOIN departments d ON d.id = u.department_id LEFT JOIN campuses c ON c.id = u.campus_id
    WHERE u.id = ? AND u.organization_id = ?
");
$stmt->execute([$id, $orgId]);
$emp = $stmt->fetch();
if (!$emp) { die('कर्मचारी सापडला नाही.'); }

if ((int)$emp['id'] !== (int)$user['id'] && !isManagementOrAbove() && !hasRole('super_admin')) {
    http_response_code(403);
    die('तुम्हाला हे ID Card बघण्याची परवानगी नाही.');
}

$org = $pdo->query("SELECT * FROM organizations WHERE id = " . (int)$orgId)->fetch();

$verifyUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/') . '/staff/verify-id.php?org=' . (int)activeOrgId() . '&code=' . urlencode($emp['employee_code'] ?? '');
$qrSrc = 'https://api.qrserver.com/v1/create-qr-code/?size=160x160&margin=1&data=' . urlencode($verifyUrl);
$joiningDateFmt = $emp['joining_date'] ? date('d.m.Y', strtotime($emp['joining_date'])) : '—';

$branchLine = trim(implode(', ', array_filter([$emp['campus_address'] ?? null, $emp['campus_city'] ?? null])));
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<title>ID Card — <?= htmlspecialchars($emp['name']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@500;600;700;800&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box;}
  body{font-family:'Poppins','Segoe UI',sans-serif;background:#F0F1F4;margin:0;padding:30px;}
  .toolbar{position:fixed;top:0;left:0;right:0;background:#10162B;padding:14px 24px;display:flex;justify-content:space-between;align-items:center;z-index:10;}
  .toolbar a{color:#fff;text-decoration:none;font-size:13px;}
  .toolbar button{background:#1B8A83;color:#fff;border:none;border-radius:8px;padding:9px 20px;font-size:13.5px;font-weight:600;cursor:pointer;font-family:inherit;}
  .stage{margin-top:70px;display:flex;justify-content:center;}

  /* Portrait ID card, matching the physical Trust card sample */
  .card{
    width:3.4in;height:5.5in;border-radius:0.16in;overflow:hidden;position:relative;
    background:#fff;box-shadow:0 10px 30px rgba(0,0,0,.2);
    border:1px solid #ddd;
  }
  .hole-slot{position:absolute;top:0.1in;left:50%;transform:translateX(-50%);width:0.7in;height:0.15in;border:2px solid #ccc;border-radius:0.08in;background:#F5F5F5;z-index:4;}

  /* Diagonal stripe decorations, left edge */
  .stripe-left{position:absolute;top:0;bottom:0;left:0;width:0.2in;z-index:1;}
  .stripe-left .s1{position:absolute;top:0;left:0;width:100%;height:38%;background:#F0932B;clip-path:polygon(0 0,100% 0,60% 100%,0 100%);}
  .stripe-left .s2{position:absolute;top:36%;left:0;width:100%;height:34%;background:#7B3F9E;clip-path:polygon(0 0,60% 0,100% 100%,0 100%);}
  .stripe-left .s3{position:absolute;bottom:0;left:0;width:100%;height:30%;background:#F0932B;clip-path:polygon(0 0,100% 0,60% 100%,0 100%);}

  /* Bottom-right diagonal accent */
  .corner-br{position:absolute;bottom:0;right:0;width:1.5in;height:1.5in;overflow:hidden;z-index:1;}
  .corner-br .p1{position:absolute;bottom:-0.35in;right:-0.35in;width:1.5in;height:1.5in;background:#7B3F9E;transform:rotate(45deg);}
  .corner-br .p2{position:absolute;bottom:-0.62in;right:0.18in;width:1.25in;height:1.25in;background:#F0932B;transform:rotate(45deg);}

  .content{position:relative;z-index:2;padding:0.3in 0.3in 0.18in 0.38in;text-align:center;}
  .atma{font-size:8pt;color:#333;font-weight:600;margin-bottom:1px;letter-spacing:.3px;}
  .trust-sub{font-size:6.8pt;color:#333;margin-bottom:6px;}
  .inst-name{font-family:'Poppins';font-size:15pt;font-weight:800;color:#C4293D;line-height:1.18;margin-bottom:5px;text-transform:uppercase;letter-spacing:.2px;}
  .branch{font-size:8.3pt;color:#2255AA;font-weight:600;margin-bottom:11px;}

  .emblem{position:absolute;top:0.3in;left:0.3in;width:0.68in;height:0.68in;border-radius:50%;border:2px solid #333;background:#fff;display:flex;align-items:center;justify-content:center;overflow:hidden;z-index:3;}
  .emblem img{width:100%;height:100%;object-fit:cover;}
  .emblem .fallback{font-size:7.5pt;font-weight:700;color:#C4293D;}

  .photo-box{width:1.95in;height:2.2in;margin:0 auto 10px;border:2.5px solid #2A2A2A;border-radius:4px;overflow:hidden;background:#EFEFEF;}
  .photo-box img{width:100%;height:100%;object-fit:cover;}
  .photo-box .fallback{width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-family:'Poppins';font-size:38pt;font-weight:700;color:#C4293D;background:#F3E9EC;}

  .emp-name{font-size:15.5pt;font-weight:700;color:#C4293D;margin-bottom:10px;}

  .fields{text-align:left;font-size:9.3pt;color:#1A1D29;padding:0 0.08in;}
  .fields .frow{display:flex;margin-bottom:7px;align-items:baseline;}
  .fields .flabel{width:1.35in;flex-shrink:0;color:#333;font-weight:500;}
  .fields .fcolon{width:0.15in;flex-shrink:0;color:#333;}
  .fields .fval{font-weight:700;color:#111;}

  /* Special QR treatment — bordered "scan" tag, clearly set apart */
  .qr-block{
    position:absolute;bottom:0.85in;right:0.28in;z-index:3;
    background:#fff;border:2px solid #1B8A83;border-radius:8px;
    padding:5px;box-shadow:0 3px 10px rgba(0,0,0,.15);
  }
  .qr-block img{width:0.72in;height:0.72in;display:block;}
  .qr-caption{font-size:5.6pt;color:#1B8A83;font-weight:700;text-align:center;margin-top:2px;letter-spacing:.3px;}

  .sig-area{margin-top:6px;text-align:center;}
  .sig-line{font-size:7.8pt;color:#C4293D;font-weight:700;letter-spacing:.3px;border-top:1px solid #999;padding-top:5px;display:inline-block;margin-top:8px;min-width:1.6in;}

  .empcode-tag{position:absolute;top:0.14in;right:0.32in;font-family:'JetBrains Mono',monospace;font-size:6.3pt;color:#8A90AC;z-index:3;}

  @media print{
    body{background:#fff;padding:0;}
    .toolbar{display:none;}
    .stage{margin-top:0;}
  }
</style>
</head>
<body>

<div class="toolbar">
  <a href="profile.php?id=<?= $emp['id'] ?>">← मागे</a>
  <button onclick="window.print()"><i class="fa-solid fa-print"></i> Print करा</button>
</div>

<div class="stage">
  <div class="card">
    <div class="hole-slot"></div>
    <div class="stripe-left"><div class="s1"></div><div class="s2"></div><div class="s3"></div></div>
    <div class="corner-br"><div class="p1"></div><div class="p2"></div></div>
    <div class="empcode-tag"><?= htmlspecialchars($emp['employee_code'] ?: ('ID-' . $emp['id'])) ?></div>

    <div class="emblem">
      <?php if (!empty($org['logo_path'])): ?>
        <img src="../<?= htmlspecialchars($org['logo_path']) ?>">
      <?php else: ?>
        <span class="fallback"><?= htmlspecialchars(mb_substr($org['name'] ?? 'VJ', 0, 2)) ?></span>
      <?php endif; ?>
    </div>

    <div class="content">
      <div class="atma">॥ आत्मा मालिक ॥</div>
      <div class="trust-sub"><?= htmlspecialchars($org['name'] ?? '') ?></div>
      <div class="inst-name"><?= htmlspecialchars($emp['campus_name'] ?? ($org['name'] ?? '')) ?></div>
      <?php if ($branchLine): ?><div class="branch">Branch — <?= htmlspecialchars($branchLine) ?></div><?php else: ?><div class="branch">&nbsp;</div><?php endif; ?>

      <div class="photo-box">
        <?php if (!empty($emp['photo_path'])): ?>
          <img src="../<?= htmlspecialchars($emp['photo_path']) ?>">
        <?php else: ?>
          <div class="fallback"><?= htmlspecialchars(mb_substr($emp['name'], 0, 1)) ?></div>
        <?php endif; ?>
      </div>

      <div class="emp-name"><?= htmlspecialchars($emp['name']) ?></div>

      <div class="fields">
        <div class="frow"><div class="flabel">Department</div><div class="fcolon">:</div><div class="fval"><?= htmlspecialchars($emp['dept_name'] ?? '—') ?></div></div>
        <div class="frow"><div class="flabel">Designation</div><div class="fcolon">:</div><div class="fval"><?= htmlspecialchars($emp['designation'] ?? '—') ?></div></div>
        <div class="frow"><div class="flabel">Joining Date</div><div class="fcolon">:</div><div class="fval"><?= htmlspecialchars($joiningDateFmt) ?></div></div>
        <div class="frow"><div class="flabel">Mobile No.</div><div class="fcolon">:</div><div class="fval"><?= htmlspecialchars($emp['mobile'] ?? '—') ?></div></div>
      </div>

      <div class="sig-area">
        <div class="sig-line">Authorized Signatory</div>
      </div>
    </div>

    <div class="qr-block">
      <img src="<?= htmlspecialchars($qrSrc) ?>" alt="QR">
      <div class="qr-caption">SCAN TO<br>VERIFY</div>
    </div>
  </div>
</div>

</body>
</html>
