<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
$user = currentUser();

$letterId = (int)($_GET['id'] ?? 0);
$orgId = activeOrgId();

$stmt = $pdo->prepare("
    SELECT l.*, lc.name_local AS category_name, d.name_local AS dept_name, d.hod_id AS dept_hod_id, creator.name AS creator_name, creator.designation AS creator_designation,
      camp.name AS college_name, camp.letterhead_logo_path AS college_letterhead_path
    FROM letters l
    LEFT JOIN letter_categories lc ON lc.id = l.category_id
    LEFT JOIN departments d ON d.id = l.department_id
    LEFT JOIN campuses camp ON camp.id = d.campus_id
    LEFT JOIN users creator ON creator.id = l.created_by
    WHERE l.id = ?
");
$stmt->execute([$letterId]);
$letter = $stmt->fetch();
if (!$letter) { die('फाईल सापडली नाही.'); }

$sigStmt = $pdo->prepare("SELECT * FROM letter_signatures WHERE letter_id = ? ORDER BY signed_at ASC");
$sigStmt->execute([$letterId]);
$letterSignatures = $sigStmt->fetchAll();

$stmt = $pdo->prepare("SELECT COUNT(*) c FROM letter_actions WHERE letter_id = ? AND (from_user_id = ? OR to_user_id = ?)");
$stmt->execute([$letterId, $user['id'], $user['id']]);
$everInvolved = (int)$stmt->fetch()['c'] > 0;
$isOwnOrgLetter = (int)$letter['organization_id'] === (int)$orgId;
$canView = (int)$letter['created_by'] === (int)$user['id'] || (int)$letter['current_holder_id'] === (int)$user['id'] || $everInvolved || ($isOwnOrgLetter && hasPermission('manage_letters_org_wide'));
if (!$canView) { die('तुम्हाला ही फाईल पाहण्याची परवानगी नाही.'); }

$org = $pdo->query("SELECT * FROM organizations WHERE id = " . (int)$orgId)->fetch();

$stmt = $pdo->prepare("
    SELECT la.*, fu.name AS from_name, tu.name AS to_name
    FROM letter_actions la
    LEFT JOIN users fu ON fu.id = la.from_user_id
    LEFT JOIN users tu ON tu.id = la.to_user_id
    WHERE la.letter_id = ? ORDER BY la.id ASC
");
$stmt->execute([$letterId]);
$actions = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT la.*, u.name AS uploader_name FROM letter_attachments la LEFT JOIN users u ON u.id = la.uploaded_by WHERE la.letter_id = ? ORDER BY la.id");
$stmt->execute([$letterId]);
$attachments = $stmt->fetchAll();

// This department's HOD name, for the signature block
$signatoryName = null;
if (!empty($letter['dept_hod_id'])) {
    $hodStmt = $pdo->prepare("SELECT name, designation FROM users WHERE id = ?");
    $hodStmt->execute([$letter['dept_hod_id']]);
    $hod = $hodStmt->fetch();
    if ($hod) { $signatoryName = $hod; }
}

$priorityLabels = ['low' => 'कमी', 'normal' => 'सामान्य', 'high' => 'जास्त', 'urgent' => 'तातडीचे'];
$statusLabels = ['draft' => 'Draft', 'in_progress' => 'प्रगतीत', 'completed' => 'पूर्ण/दाखल', 'returned' => 'परत आले'];
$actionLabels = ['created' => 'तयार केली', 'submitted' => 'सबमिट केली', 'forwarded' => 'पुढे पाठवली', 'returned' => 'परत पाठवली', 'remark_added' => 'टिप्पणी', 'completed' => 'पूर्ण/दाखल केली'];
?>
<!DOCTYPE html>
<html lang="mr">
<head>
<meta charset="UTF-8">
<title><?= h($letter['file_number']) ?> — <?= h($letter['subject']) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@600;700&family=Noto+Sans+Devanagari:wght@400;500;600;700&family=Tiro+Devanagari+Marathi&display=swap" rel="stylesheet">
<style>
  :root{ --ink:#10162B; --rail:#1B8A83; --line:#B9BEC9; --text:#1A1D29; --text-dim:#666B7D; }
  *{box-sizing:border-box;}
  body{font-family:'Noto Sans Devanagari','Segoe UI',sans-serif;color:var(--text);margin:0;background:#F0F1F4;font-size:13px;}
  .toolbar{position:sticky;top:0;background:var(--ink);padding:14px 24px;display:flex;justify-content:space-between;align-items:center;z-index:10;}
  .toolbar a{color:#fff;text-decoration:none;font-size:13px;}
  .toolbar button{background:var(--rail);color:#fff;border:none;border-radius:8px;padding:9px 20px;font-size:13.5px;font-weight:600;cursor:pointer;font-family:inherit;}

  .page{max-width:820px;margin:24px auto;background:#fff;padding:0.6in 0.7in;box-shadow:0 4px 24px rgba(0,0,0,.1);}

  /* ---------------- Letterhead ---------------- */
  .letterhead{text-align:center;border-bottom:3px double var(--ink);padding-bottom:14px;margin-bottom:18px;}
  .letterhead .logo{height:64px;margin-bottom:8px;}
  .letterhead .org-name{font-family:'Space Grotesk';font-weight:700;font-size:22px;color:var(--ink);letter-spacing:.2px;}
  .letterhead .dept-name{font-size:14px;font-weight:700;color:var(--rail);margin-top:3px;}
  .letterhead .org-contact{font-size:10.5px;color:var(--text-dim);margin-top:4px;}

  /* ---------------- Reference block ---------------- */
  .ref-row{display:flex;justify-content:space-between;font-size:12px;margin-bottom:18px;flex-wrap:wrap;gap:8px;}
  .ref-row .ref-block div{margin-bottom:2px;}
  .ref-row .ref-label{color:var(--text-dim);}
  .ref-row .ref-val{font-weight:700;color:var(--ink);}

  /* ---------------- Subject ---------------- */
  .subject-line{margin:10px 0 18px;font-size:14px;}
  .subject-line .lbl{font-weight:700;text-decoration:underline;}

  /* ---------------- Body ---------------- */
  .letter-body{font-size:13.5px;line-height:2;color:var(--text);min-height:1.8in;white-space:pre-wrap;text-align:justify;}
  .no-content{color:var(--text-dim);font-style:italic;}

  /* ---------------- Signature ---------------- */
  .sig-block{margin-top:0.55in;text-align:right;}
  .sig-block .sig-line{border-top:1px solid var(--text);width:2.4in;margin-left:auto;padding-top:6px;}
  .sig-block .sig-name{font-weight:700;font-size:13px;}
  .sig-block .sig-desig{font-size:11px;color:var(--text-dim);}

  /* ---------------- Attachments + Movement (reference section, lighter) ---------------- */
  .ref-section{margin-top:0.5in;border-top:1px dashed var(--line);padding-top:14px;}
  .ref-section h3{font-family:'Space Grotesk';font-size:12.5px;color:var(--text-dim);text-transform:uppercase;letter-spacing:.4px;margin:14px 0 8px;}
  .timeline-item{display:flex;gap:8px;padding:6px 0;border-bottom:1px solid #EEF0F4;font-size:11px;}
  .timeline-item .dot{width:20px;height:20px;border-radius:50%;background:#DCEFEC;color:#0F5E58;display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:700;flex-shrink:0;}
  .att-item{font-size:11px;padding:3px 0;color:var(--text-dim);}

  @media print{
    body{background:#fff;}
    .toolbar{display:none;}
    .page{margin:0;padding:0.5in 0.6in;box-shadow:none;max-width:100%;}
  }
</style>
</head>
<body>

<div class="toolbar">
  <a href="view.php?id=<?= $letterId ?>">← मागे</a>
  <button onclick="window.print()"><i class="fa-solid fa-download"></i> PDF डाउनलोड करा</button>
</div>

<div class="page">

  <!-- ============ Letterhead ============ -->
  <div class="letterhead">
    <?php $logoToShow = !empty($letter['college_letterhead_path']) ? $letter['college_letterhead_path'] : ($org['logo_path'] ?? null); ?>
    <?php if (!empty($logoToShow)): ?><img class="logo" src="../<?= h($logoToShow) ?>"><?php endif; ?>
    <div class="org-name"><?= h($org['name'] ?? '') ?></div>
    <?php if (!empty($letter['college_name'])): ?><div style="font-size:12.5px;font-weight:600;color:var(--ink);margin-top:2px;"><?= h($letter['college_name']) ?></div><?php endif; ?>
    <?php if ($letter['dept_name']): ?><div class="dept-name"><?= h($letter['dept_name']) ?> विभाग</div><?php endif; ?>
    <?php if (!empty($org['contact_email']) || !empty($org['contact_phone'])): ?>
      <div class="org-contact"><?= h(trim(implode(' · ', array_filter([$org['contact_phone'] ?? null, $org['contact_email'] ?? null])))) ?></div>
    <?php endif; ?>
  </div>

  <!-- ============ Reference block ============ -->
  <div class="ref-row">
    <div class="ref-block">
      <div><span class="ref-label">File No.: </span><span class="ref-val"><?= h($letter['file_number']) ?></span></div>
      <?php if ($letter['outward_no']): ?><div><span class="ref-label">जावक क्र.: </span><span class="ref-val"><?= h($letter['outward_no']) ?></span></div><?php endif; ?>
      <?php if ($letter['reference_no']): ?><div><span class="ref-label">संदर्भ क्र.: </span><span class="ref-val"><?= h($letter['reference_no']) ?></span></div><?php endif; ?>
    </div>
    <div class="ref-block" style="text-align:right;">
      <div><span class="ref-label">दिनांक: </span><span class="ref-val"><?= date('d-m-Y', strtotime($letter['created_at'])) ?></span></div>
      <div><span class="ref-label">प्राधान्य: </span><span class="ref-val"><?= $priorityLabels[$letter['priority']] ?? $letter['priority'] ?></span></div>
      <div><span class="ref-label">स्थिती: </span><span class="ref-val"><?= $statusLabels[$letter['status']] ?? $letter['status'] ?></span></div>
    </div>
  </div>

  <!-- ============ Subject ============ -->
  <div class="subject-line"><span class="lbl">विषय:</span> <?= h($letter['subject']) ?><?= $letter['category_name'] ? ' (' . h($letter['category_name']) . ')' : '' ?></div>

  <!-- ============ Body ============ -->
  <div class="letter-body">
    <?php if ($letter['description']): ?>
      <?= nl2br(h($letter['description'])) ?>
    <?php else: ?>
      <span class="no-content">(या फाईलसाठी कुठलाही तपशीलवार मजकूर नोंदवलेला नाही.)</span>
    <?php endif; ?>
  </div>

  <!-- ============ Signature ============ -->
  <div class="sig-block">
    <div class="sig-line">
      <div class="sig-name"><?= h(($signatoryName['name'] ?? $letter['creator_name']) ?? '') ?></div>
      <div class="sig-desig"><?= h(($signatoryName['designation'] ?? $letter['creator_designation']) ?? '') ?><?= $letter['dept_name'] ? ' — ' . h($letter['dept_name']) : '' ?></div>
    </div>
  </div>

  <?php foreach ($letterSignatures as $sig):
    $verifyUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/verify-signature.php?code=' . urlencode($sig['verification_code']);
    $qrSrc = 'https://api.qrserver.com/v1/create-qr-code/?size=110x110&margin=1&data=' . urlencode($verifyUrl);
  ?>
    <div style="margin-top:0.35in;border:1px solid #1B8A83;border-radius:8px;padding:12px 16px;display:flex;align-items:center;gap:14px;background:#F7FBFA;">
      <img src="<?= h($qrSrc) ?>" style="width:0.9in;height:0.9in;">
      <div style="font-size:11px;">
        <div style="font-weight:700;color:#0F5E58;font-size:12px;">🔏 Digitally Signed by <?= h($sig['signer_name']) ?></div>
        <div style="color:#666B7D;margin-top:2px;"><?= h($sig['signer_designation'] ?? '') ?></div>
        <div style="color:#666B7D;margin-top:4px;"><?= date('d M Y, h:i A', strtotime($sig['signed_at'])) ?> · <?= $sig['verification_method'] === 'fingerprint' ? '👆 Fingerprint/Face ID' : '🔑 Password' ?> ने पडताळलेलं</div>
        <div style="color:#666B7D;margin-top:2px;">Verification ID: #<?= h($sig['verification_code']) ?> — QR स्कॅन करून पडताळा</div>
      </div>
    </div>
  <?php endforeach; ?>

  <!-- ============ Reference section: movement + attachments (for internal record) ============ -->
  <div class="ref-section">
    <h3>हालचालीचा क्रम (Movement Timeline)</h3>
    <?php foreach ($actions as $a): ?>
      <div class="timeline-item">
        <div class="dot"><?= h(mb_substr($a['from_name'] ?? '?', 0, 1)) ?></div>
        <div>
          <strong><?= h($a['from_name']) ?></strong> — <?= $actionLabels[$a['action_type']] ?? $a['action_type'] ?><?= $a['to_name'] ? ' → ' . h($a['to_name']) : '' ?>
          <?php if ($a['remark']): ?> — <?= h($a['remark']) ?><?php endif; ?>
          <span style="color:var(--text-dim);"> (<?= date('d-m-Y, h:i A', strtotime($a['created_at'])) ?>)</span>
        </div>
      </div>
    <?php endforeach; ?>

    <?php if (!empty($attachments)): ?>
      <h3>जोडपत्रे (Attachments)</h3>
      <?php foreach ($attachments as $att): ?>
        <div class="att-item">📎 <?= h($att['original_name']) ?> — <?= h($att['uploader_name']) ?></div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

</div>

</body>
</html>
