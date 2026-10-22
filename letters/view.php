<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../includes/budget-helpers.php';
$user = currentUser();

$letterId = (int)($_GET['id'] ?? 0);
$orgId = activeOrgId();

$loadLetter = function () use ($pdo, $letterId) {
    $stmt = $pdo->prepare("
        SELECT l.*, lc.name_local AS category_name, d.name_local AS dept_name,
          creator.name AS creator_name, holder.name AS holder_name,
          wt.name AS workflow_name
        FROM letters l
        LEFT JOIN letter_categories lc ON lc.id = l.category_id
        LEFT JOIN departments d ON d.id = l.department_id
        LEFT JOIN users creator ON creator.id = l.created_by
        LEFT JOIN users holder ON holder.id = l.current_holder_id
        LEFT JOIN workflow_templates wt ON wt.id = l.workflow_template_id
        WHERE l.id = ?
    ");
    $stmt->execute([$letterId]);
    return $stmt->fetch();
};
$letter = $loadLetter();
if (!$letter) { die('फाईल सापडली नाही.'); }

// पाहण्याची परवानगी: तयार करणारा, सध्याचा धारक, हालचालीत कधीही आलेला कोणीही,
// किंवा याच संस्थेचं पत्र असेल तर संस्था-स्तरीय परवानगी असलेले.
$stmt = $pdo->prepare("SELECT COUNT(*) c FROM letter_actions WHERE letter_id = ? AND (from_user_id = ? OR to_user_id = ?)");
$stmt->execute([$letterId, $user['id'], $user['id']]);
$everInvolved = (int)$stmt->fetch()['c'] > 0;

$isOwnOrgLetter = (int)$letter['organization_id'] === (int)$orgId;
$canView = (int)$letter['created_by'] === (int)$user['id'] || (int)$letter['current_holder_id'] === (int)$user['id'] || $everInvolved || ($isOwnOrgLetter && hasPermission('manage_letters_org_wide'));
if (!$canView) {
    http_response_code(403);
    die('तुम्हाला ही फाईल पाहण्याची परवानगी नाही.');
}

$isCreator = (int)$letter['created_by'] === (int)$user['id'];
$isHolder = (int)$letter['current_holder_id'] === (int)$user['id'] && in_array($letter['status'], ['in_progress', 'returned'], true);
$hasWorkflow = !empty($letter['workflow_template_id']);
// कार्यप्रवाहाच्या शेवटच्या पायरीवर "पुढे पाठवा" नाही — फक्त Sign & Approve
$atFinalWorkflowStep = $hasWorkflow && (int)$letter['current_step_order'] > 0 && !letterHasNextWorkflowStep($pdo, $letter);
$canSign = $isHolder && (!$hasWorkflow || $atFinalWorkflowStep);
$canForward = $isHolder && !$atFinalWorkflowStep;

if ($isHolder && empty($letter['current_holder_seen_at'])) {
    $pdo->prepare("UPDATE letters SET current_holder_seen_at = NOW() WHERE id = ?")->execute([$letterId]);
    $letter['current_holder_seen_at'] = date('Y-m-d H:i:s');
}

/** याच संस्थेचा (किंवा शाखा-संस्थेचा) सक्रिय कर्मचारी आहे ना */
$isValidRecipient = function ($userId) use ($pdo, $user) {
    if (!$userId || (int)$userId === (int)$user['id']) { return false; }
    $st = $pdo->prepare("SELECT 1 FROM users WHERE id = ? AND is_active = 1");
    $st->execute([$userId]);
    return (bool)$st->fetchColumn();
};

// ---------------- POST actions ----------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $remark = trim($_POST['remark'] ?? '');

    if (($action === 'submit' && $isCreator && $letter['status'] === 'draft') || ($action === 'forward' && $canForward)) {
        $actionType = $action === 'submit' ? 'submitted' : 'forwarded';
        if ($hasWorkflow) {
            if ($action === 'submit') { $letter['current_step_order'] = 0; }
            $result = advanceLetterToNextStep($pdo, $letter);
            if ($result['error']) {
                $_SESSION['form_error'] = $result['error'];
            } else {
                letterSendTo($pdo, $letter, $result['holder'], $user['id'], $actionType, $remark, $orgId, true);
                logActivity($action === 'submit' ? 'फाईल सबमिट केली' : 'फाईल पुढे पाठवली', 'letter', $letterId, $letter['file_number']);
                $_SESSION['flash'] = 'फाईल कार्यप्रवाहाच्या पुढच्या पायरीकडे पाठवली गेली.';
            }
        } else {
            $toUserId = (int)($_POST['to_user_id'] ?? 0);
            if (!$isValidRecipient($toUserId)) {
                $_SESSION['form_error'] = 'कृपया फाईल कोणाकडे पाठवायची ते निवडा.';
            } else {
                letterSendTo($pdo, $letter, $toUserId, $user['id'], $actionType, $remark, $orgId);
                logActivity($action === 'submit' ? 'फाईल सबमिट केली' : 'फाईल पुढे पाठवली', 'letter', $letterId, $letter['file_number']);
                $_SESSION['flash'] = 'फाईल पाठवली गेली.';
            }
        }
    }

    elseif ($action === 'return' && $isHolder) {
        // ज्याने ही फाईल सध्याच्या धारकाकडे पाठवली त्यालाच परत
        $stmt = $pdo->prepare("SELECT from_user_id FROM letter_actions WHERE letter_id = ? AND to_user_id = ? AND action_type IN ('submitted','forwarded','returned') ORDER BY id DESC LIMIT 1");
        $stmt->execute([$letterId, $user['id']]);
        $returnTo = (int)($stmt->fetch()['from_user_id'] ?? 0) ?: (int)$letter['created_by'];
        if ($returnTo === (int)$user['id']) {
            $_SESSION['form_error'] = 'ही फाईल परत पाठवण्यासाठी कोणी आधीचा धारक नाही.';
        } else {
            // कार्यप्रवाहात एक पायरी मागे — म्हणजे पुन्हा पुढे पाठवल्यावर ही पायरी वगळली जाणार नाही
            $newStep = $hasWorkflow ? letterPreviousWorkflowStepOrder($pdo, $letter) : (int)$letter['current_step_order'];
            $pdo->prepare("UPDATE letters SET current_holder_id=?, status='returned', current_step_order=?, current_holder_seen_at=NULL WHERE id=?")
                ->execute([$returnTo, $newStep, $letterId]);
            $pdo->prepare("INSERT INTO letter_actions (letter_id, action_type, from_user_id, to_user_id, remark) VALUES (?, 'returned', ?, ?, ?)")
                ->execute([$letterId, $user['id'], $returnTo, $remark ?: null]);
            notifyUser($returnTo, $orgId, 'फाईल परत पाठवली गेली', $letter['subject'] . ' — ' . $letter['file_number'], 'letters/view.php?id=' . $letterId);
            logActivity('फाईल परत पाठवली', 'letter', $letterId, $letter['file_number']);
            $_SESSION['flash'] = 'फाईल परत पाठवली गेली.';
        }
    }

    elseif ($action === 'remark' && ($isHolder || $isCreator)) {
        if ($remark !== '') {
            $pdo->prepare("INSERT INTO letter_actions (letter_id, action_type, from_user_id, remark) VALUES (?, 'remark_added', ?, ?)")
                ->execute([$letterId, $user['id'], $remark]);
            $_SESSION['flash'] = 'टिप्पणी जोडली गेली.';
        }
    }

    elseif ($action === 'reassign' && hasRole('super_admin') && in_array($letter['status'], ['in_progress', 'returned'], true)) {
        $newHolder = (int)($_POST['new_holder_id'] ?? 0);
        $st = $pdo->prepare("SELECT 1 FROM users WHERE id = ? AND is_active = 1");
        $st->execute([$newHolder]);
        if ($newHolder && $st->fetchColumn()) {
            letterSendTo($pdo, $letter, $newHolder, $user['id'], 'forwarded', '⚙️ Super Admin ने पुन्हा नेमले (Reassigned)' . ($remark !== '' ? ' — ' . $remark : ''), $orgId);
            logActivity('Super Admin ने फाईल reassign केली', 'letter', $letterId, $letter['file_number']);
            $_SESSION['flash'] = 'फाईल पुन्हा नेमली गेली.';
        }
    }

    elseif ($action === 'mark_dispatched' && ($isCreator || $isHolder || hasRole('super_admin'))) {
        $dispatchMode = in_array($_POST['dispatch_mode'] ?? '', ['Post', 'Courier', 'Speed Post', 'Email', 'Hand Delivery', 'Other'], true) ? $_POST['dispatch_mode'] : null;
        $trackingNo = trim($_POST['dispatch_tracking_no'] ?? '') ?: null;
        $dispatchDate = ($_POST['dispatch_date'] ?? '') ?: date('Y-m-d');
        $pdo->prepare("UPDATE letters SET dispatch_mode = ?, dispatch_tracking_no = ?, dispatch_date = ? WHERE id = ?")
            ->execute([$dispatchMode, $trackingNo, $dispatchDate, $letterId]);
        logActivity('पत्र Dispatch नोंदवलं', 'letter', $letterId, $letter['file_number']);
        $_SESSION['flash'] = '✅ Dispatch माहिती जतन झाली.';
    }

    elseif ($action === 'attach' && ($isCreator || $isHolder)) {
        if (!isset($_FILES['attachment']) || $_FILES['attachment']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['form_error'] = 'File निवडा (किंवा File खूप मोठी आहे).';
        } else {
            $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx'];
            $ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExts, true)) {
                $_SESSION['form_error'] = 'फक्त फोटो, PDF, Word किंवा Excel फाईल्स Attach करता येतात.';
            } else {
                $uploadDir = __DIR__ . '/../assets/uploads/letters/' . $letterId . '/';
                if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
                $safeName = uniqid('att_') . '.' . $ext;
                if (move_uploaded_file($_FILES['attachment']['tmp_name'], $uploadDir . $safeName)) {
                    $pdo->prepare("INSERT INTO letter_attachments (letter_id, file_path, original_name, uploaded_by) VALUES (?, ?, ?, ?)")
                        ->execute([$letterId, 'assets/uploads/letters/' . $letterId . '/' . $safeName, $_FILES['attachment']['name'], $user['id']]);
                    $_SESSION['flash'] = 'जोडपत्र (Attachment) अपलोड झाले.';
                }
            }
        }
    }

    header('Location: view.php?id=' . $letterId);
    exit;
}

// ---------------- Data for display ----------------
$stmt = $pdo->prepare("
    SELECT la.*, fu.name AS from_name, tu.name AS to_name
    FROM letter_actions la
    LEFT JOIN users fu ON fu.id = la.from_user_id
    LEFT JOIN users tu ON tu.id = la.to_user_id
    WHERE la.letter_id = ? ORDER BY la.id ASC
");
$stmt->execute([$letterId]);
$actions = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT la.*, u.name AS uploader_name FROM letter_attachments la LEFT JOIN users u ON u.id = la.uploaded_by WHERE la.letter_id = ? ORDER BY la.id DESC");
$stmt->execute([$letterId]);
$attachments = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT lin.*, d.name_local AS dept_name FROM letter_inward_numbers lin
    JOIN departments d ON d.id = lin.department_id
    WHERE lin.letter_id = ? ORDER BY lin.created_at ASC
");
$stmt->execute([$letterId]);
$inwardNumbers = $stmt->fetchAll();

$sigStmt = $pdo->prepare("SELECT * FROM letter_signatures WHERE letter_id = ? ORDER BY signed_at ASC");
$sigStmt->execute([$letterId]);
$letterSignatures = $sigStmt->fetchAll();

// कार्यप्रवाहाच्या पायऱ्या — सध्या कोणत्या पायरीवर आहे ते दाखवण्यासाठी
$workflowSteps = [];
if ($hasWorkflow) {
    $wsStmt = $pdo->prepare("SELECT step_order, step_name FROM workflow_steps WHERE workflow_template_id = ? ORDER BY step_order");
    $wsStmt->execute([$letter['workflow_template_id']]);
    $workflowSteps = $wsStmt->fetchAll();
}

$staffPool = [];
if ($isHolder || ($isCreator && $letter['status'] === 'draft') || hasRole('super_admin')) {
    $stmt = $pdo->prepare("
        SELECT u.id, u.name, u.designation, d.name_local AS dept_name FROM users u
        LEFT JOIN departments d ON d.id = u.department_id
        WHERE u.organization_id = ? AND u.is_active = 1 AND u.id != ? ORDER BY d.name_local, u.name
    ");
    $stmt->execute([$orgId, $user['id']]);
    $staffPool = $stmt->fetchAll();
}

$hasFingerprint = false;
if ($canSign) {
    try {
        $fpStmt = $pdo->prepare("SELECT COUNT(*) c FROM webauthn_credentials WHERE user_id = ?");
        $fpStmt->execute([$user['id']]);
        $hasFingerprint = (int)$fpStmt->fetch()['c'] > 0;
    } catch (PDOException $e) { /* webauthn table not migrated */ }
}

$priorityLabels = ['low' => 'कमी', 'normal' => 'सामान्य', 'high' => 'जास्त', 'urgent' => 'तातडीचे'];
$statusLabels = ['draft' => 'Draft', 'in_progress' => 'प्रगतीत', 'completed' => 'पूर्ण/दाखल', 'returned' => 'परत आले'];
$statusChip = ['draft' => 'pending', 'in_progress' => 'pending', 'completed' => 'done', 'returned' => 'overdue'];
$actionLabels = ['created' => 'तयार केली', 'submitted' => 'पाठवली', 'forwarded' => 'पुढे पाठवली', 'returned' => 'परत पाठवली', 'remark_added' => 'टिप्पणी', 'completed' => 'Sign करून पूर्ण/दाखल केली'];

$renderStaffOptions = function ($pool, $selected = 0) {
    $html = '';
    $currentDept = null;
    foreach ($pool as $s) {
        $dept = $s['dept_name'] ?: 'इतर';
        if ($dept !== $currentDept) {
            if ($currentDept !== null) { $html .= '</optgroup>'; }
            $html .= '<optgroup label="' . h($dept) . '">';
            $currentDept = $dept;
        }
        $html .= '<option value="' . (int)$s['id'] . '"' . ((int)$s['id'] === (int)$selected ? ' selected' : '') . '>' . h($s['name']) . ($s['designation'] ? ' (' . h($s['designation']) . ')' : '') . '</option>';
    }
    return $html . ($currentDept !== null ? '</optgroup>' : '');
};

$pageTitle = $letter['file_number'];
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow"><?= h($letter['file_number']) ?><?= $letter['outward_no'] ? ' · जावक क्र.: ' . h($letter['outward_no']) : '' ?></div>
<div class="d-flex justify-content-between align-items-start mb-1 gap-2">
  <div>
    <div class="page-title mb-1"><?= h($letter['subject']) ?></div>
    <?php if ($letter['reference_no']): ?>
      <div style="font-size:12px;color:var(--amber);font-weight:600;margin-bottom:4px;">संदर्भ क्र.: <?= h($letter['reference_no']) ?></div>
    <?php endif; ?>
    <?php if (!empty($letter['is_budget_expense'])): ?>
      <div style="font-size:12px;font-weight:600;margin-bottom:4px;">
        💰 <?= h($letter['budget_category'] ?? '') ?> — मागणी: ₹<?= number_format($letter['requested_amount'] ?: 0, 0) ?>
        <?php if ($letter['budget_deducted']): ?>
          <span style="color:var(--rail);"> · ✓ मंजूर: ₹<?= number_format($letter['approved_amount'], 0) ?> (Budget मधून वजा झाले)</span>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <div style="font-size:12.5px;color:var(--text-dim);margin-bottom:6px;">
      तयार केले: <?= h($letter['creator_name']) ?> · <?= date('d M Y', strtotime($letter['created_at'])) ?>
      <?= $letter['dept_name'] ? ' · ' . h($letter['dept_name']) : '' ?>
      <?= $letter['category_name'] ? ' · ' . h($letter['category_name']) : '' ?>
    </div>
    <div style="margin-bottom:12px;">
      <?php foreach ($inwardNumbers as $inw): ?>
        <span style="display:inline-block;background:var(--rail-soft);color:var(--rail);border-radius:14px;padding:3px 12px;font-size:11px;font-weight:600;margin:0 6px 6px 0;">
          <?= h($inw['dept_name']) ?> आवक: <?= h($inw['inward_no']) ?>
        </span>
      <?php endforeach; ?>
    </div>
    <?php foreach ($letterSignatures as $sig): ?>
      <div style="background:linear-gradient(135deg,#F3F4F7,#fff);border:1px solid var(--rail);border-radius:10px;padding:10px 14px;margin-bottom:12px;display:flex;align-items:center;gap:12px;">
        <div style="font-size:22px;">🔏</div>
        <div style="flex:1;">
          <div style="font-size:12.5px;font-weight:700;color:var(--ink);">Digitally Signed by <?= h($sig['signer_name']) ?><?= $sig['signer_designation'] ? ' — ' . h($sig['signer_designation']) : '' ?></div>
          <div style="font-size:11px;color:var(--text-dim);">
            <?= date('d M Y, h:i A', strtotime($sig['signed_at'])) ?> ·
            <?= $sig['verification_method'] === 'fingerprint' ? '👆 Fingerprint/Face ID' : '🔑 Password' ?> ने पडताळलेलं ·
            Verification ID: #<?= h($sig['verification_code']) ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
  <div class="d-flex gap-2" style="flex-shrink:0;">
    <a href="print.php?id=<?= $letterId ?>" target="_blank" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:13px;padding:9px 16px;align-self:center;"><i class="fa-solid fa-file-pdf me-1"></i>PDF</a>
    <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>पत्र व्यवहार</a>
  </div>
</div>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>
<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">स्थिती</div><div class="val" style="font-size:15px;"><span class="status-chip <?= $statusChip[$letter['status']] ?? 'pending' ?>"><?= $statusLabels[$letter['status']] ?? h($letter['status']) ?></span></div></div></div>
  <div class="col-6 col-lg-3"><div class="kpi-mini"><div class="lbl">प्राधान्य</div><div class="val" style="font-size:15px;"><?= $priorityLabels[$letter['priority']] ?? h($letter['priority']) ?></div></div></div>
  <div class="col-12 col-lg-6"><div class="kpi-mini"><div class="lbl">सध्या कोणाकडे</div><div class="val" style="font-size:15px;">
    <?= h($letter['holder_name'] ?? '—') ?>
    <?php if ($letter['holder_name']): ?>
      <?php if (!empty($letter['current_holder_seen_at'])): ?>
        <span style="font-size:10.5px;color:var(--rail);font-weight:600;margin-left:6px;"><i class="fa-solid fa-check-double"></i> वाचली</span>
      <?php else: ?>
        <span style="font-size:10.5px;color:var(--rose);font-weight:600;margin-left:6px;"><i class="fa-solid fa-circle"></i> वाचली नाही</span>
      <?php endif; ?>
    <?php endif; ?>
  </div></div></div>
</div>

<?php if ($hasWorkflow && !empty($workflowSteps)): ?>
  <div class="panel mb-3" style="padding:14px 20px;">
    <div style="font-size:12px;color:var(--text-dim);margin-bottom:8px;">कार्यप्रवाह: <strong><?= h($letter['workflow_name'] ?? '') ?></strong></div>
    <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center;">
      <?php foreach ($workflowSteps as $i => $ws):
        $done = $letter['status'] === 'completed' || (int)$ws['step_order'] < (int)$letter['current_step_order'];
        $current = $letter['status'] !== 'completed' && (int)$ws['step_order'] === (int)$letter['current_step_order'];
      ?>
        <?php if ($i > 0): ?><span style="color:var(--text-dim);">→</span><?php endif; ?>
        <span style="font-size:12px;border-radius:14px;padding:4px 12px;<?= $current ? 'background:var(--rail);color:#fff;font-weight:600;' : ($done ? 'background:var(--rail-soft);color:var(--rail);' : 'background:var(--canvas);color:var(--text-dim);') ?>">
          <?= $done ? '✓ ' : '' ?><?= (int)$ws['step_order'] ?>. <?= h($ws['step_name']) ?>
        </span>
      <?php endforeach; ?>
    </div>
  </div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-7">
    <?php if ($letter['description']): ?>
      <div class="panel mb-3" style="padding:16px 20px;font-size:13px;color:var(--text-dim);"><?= nl2br(h($letter['description'])) ?></div>
    <?php endif; ?>

    <div class="panel mb-3">
      <div class="panel-head"><div class="title">हालचालीचा क्रम (Movement Timeline)</div></div>
      <div style="padding:16px 20px;">
        <?php foreach ($actions as $a): ?>
          <div style="display:flex;gap:12px;padding:10px 0;border-bottom:1px solid var(--line);">
            <div style="width:32px;height:32px;border-radius:50%;background:var(--rail-soft);color:var(--rail);display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;flex-shrink:0;"><?= h(mb_substr($a['from_name'] ?? '?', 0, 1)) ?></div>
            <div>
              <div style="font-size:13px;"><strong><?= h($a['from_name']) ?></strong> — <?= $actionLabels[$a['action_type']] ?? h($a['action_type']) ?><?= $a['to_name'] ? ' → ' . h($a['to_name']) : '' ?></div>
              <?php if ($a['remark']): ?><div style="font-size:12.5px;color:var(--text-dim);margin-top:2px;"><?= h($a['remark']) ?></div><?php endif; ?>
              <div style="font-size:11px;color:var(--text-dim);margin-top:2px;"><?= date('d M Y, h:i A', strtotime($a['created_at'])) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="panel mb-3">
      <div class="panel-head d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div class="title">जोडपत्रे (Attachments)</div>
        <?php if ($isCreator || $isHolder): ?>
          <form method="POST" enctype="multipart/form-data" class="d-flex gap-2">
            <input type="hidden" name="action" value="attach">
            <input type="file" name="attachment" style="font-size:12px;max-width:190px;" required>
            <button type="submit" class="btn btn-sm" style="border:1px solid var(--line);border-radius:7px;font-size:12px;padding:6px 12px;">अपलोड</button>
          </form>
        <?php endif; ?>
      </div>
      <div style="padding:12px 20px;">
        <?php if (empty($attachments)): ?><p style="font-size:12.5px;color:var(--text-dim);margin:0;">कोणतेही जोडपत्र नाही</p><?php endif; ?>
        <?php foreach ($attachments as $att): ?>
          <div style="font-size:13px;padding:8px 0;border-bottom:1px solid var(--line);">
            <i class="fa-solid fa-paperclip me-2" style="color:var(--rail);"></i><a href="../<?= h($att['file_path']) ?>" target="_blank"><?= h($att['original_name']) ?></a>
            <span style="color:var(--text-dim);font-size:11.5px;">— <?= h($att['uploader_name']) ?>, <?= date('d M Y', strtotime($att['uploaded_at'] ?? 'now')) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>

    <?php if ($isCreator || $isHolder || hasRole('super_admin')): ?>
    <div class="panel mb-3">
      <div class="panel-head"><div class="title">📮 Dispatch तपशील</div></div>
      <div style="padding:14px 20px;">
        <?php if ($letter['dispatch_mode']): ?>
          <div style="font-size:12.5px;"><strong><?= h($letter['dispatch_mode']) ?></strong><?= $letter['dispatch_tracking_no'] ? ' — Tracking: ' . h($letter['dispatch_tracking_no']) : '' ?></div>
          <div style="font-size:11px;color:var(--text-dim);"><?= $letter['dispatch_date'] ? date('d M Y', strtotime($letter['dispatch_date'])) . ' रोजी पाठवलं' : '' ?></div>
        <?php else: ?>
          <p style="font-size:12px;color:var(--text-dim);margin-bottom:8px;">अजून Dispatch नोंदवलेलं नाही</p>
        <?php endif; ?>
        <button type="button" class="btn btn-sm mt-2" style="border:1px solid var(--rail);color:var(--rail);border-radius:7px;font-size:11.5px;padding:6px 14px;" data-bs-toggle="modal" data-bs-target="#dispatchModal">
          <?= $letter['dispatch_mode'] ? '✏️ Update करा' : '📮 Dispatch नोंदवा' ?>
        </button>
      </div>
    </div>

    <div class="modal fade" id="dispatchModal" tabindex="-1">
      <div class="modal-dialog">
        <div class="modal-content" style="border-radius:14px;">
          <form method="POST">
            <input type="hidden" name="action" value="mark_dispatched">
            <div class="modal-body" style="padding:22px 24px;">
              <div style="font-weight:700;font-size:15px;margin-bottom:6px;">📮 Dispatch नोंदवा</div>
              <div style="font-size:12px;color:var(--text-dim);margin-bottom:14px;">जावक क्रमांक: <strong><?= h($letter['outward_no'] ?: '—') ?></strong></div>
              <label class="form-label-custom">कसं पाठवलं</label>
              <select name="dispatch_mode" class="form-select-custom mb-2" required>
                <option value="">-- निवडा --</option>
                <?php foreach (['Post', 'Courier', 'Speed Post', 'Email', 'Hand Delivery', 'Other'] as $dm): ?>
                  <option value="<?= $dm ?>" <?= ($letter['dispatch_mode'] ?? '') === $dm ? 'selected' : '' ?>><?= $dm ?></option>
                <?php endforeach; ?>
              </select>
              <label class="form-label-custom">Tracking Number (ऐच्छिक)</label>
              <input type="text" name="dispatch_tracking_no" class="form-control-custom mb-2" value="<?= h($letter['dispatch_tracking_no'] ?? '') ?>">
              <label class="form-label-custom">दिनांक</label>
              <input type="date" name="dispatch_date" class="form-control-custom" value="<?= h($letter['dispatch_date'] ?? date('Y-m-d')) ?>">
            </div>
            <div class="modal-footer" style="border-top:1px solid var(--line);">
              <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
              <button type="submit" class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:7px;padding:8px 18px;">जतन करा</button>
            </div>
          </form>
        </div>
      </div>
    </div>
    <?php endif; ?>
  </div>

  <div class="col-lg-5">
    <div class="panel">
      <div class="panel-head"><div class="title">कृती (Actions)</div></div>
      <div style="padding:18px 20px;">

        <?php if ($isCreator && $letter['status'] === 'draft'): ?>
          <form method="POST" class="mb-3">
            <input type="hidden" name="action" value="submit">
            <?php if ($hasWorkflow): ?>
              <p style="font-size:12.5px;color:var(--text-dim);">कार्यप्रवाहानुसार आपोआप पहिल्या पायरीकडे जाईल.</p>
            <?php else: ?>
              <label class="form-label-custom">कोणाकडे पाठवायचे</label>
              <select name="to_user_id" class="form-select-custom mb-2" required>
                <option value="">-- निवडा --</option>
                <?= $renderStaffOptions($staffPool) ?>
              </select>
            <?php endif; ?>
            <textarea name="remark" class="form-control-custom mb-2" rows="2" placeholder="टिप्पणी (ऐच्छिक)"></textarea>
            <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:10px;font-weight:600;">पाठवा (Submit)</button>
          </form>

        <?php elseif ($isHolder): ?>
          <?php if ($letter['status'] === 'returned'): ?>
            <div class="alert alert-warning" style="border-radius:9px;font-size:12.5px;padding:10px 14px;margin-bottom:12px;">⚠️ ही फाईल तुमच्याकडे परत आली आहे — टिप्पणी बघून आवश्यक ते करा आणि पुन्हा पुढे पाठवा.</div>
          <?php endif; ?>

          <?php if ($canForward): ?>
            <form method="POST" class="mb-3">
              <input type="hidden" name="action" value="forward">
              <?php if ($hasWorkflow): ?>
                <p style="font-size:12.5px;color:var(--text-dim);">कार्यप्रवाहानुसार पुढच्या पायरीकडे जाईल.</p>
              <?php else: ?>
                <label class="form-label-custom">पुढे कोणाकडे पाठवायचे</label>
                <select name="to_user_id" class="form-select-custom mb-2" required>
                  <option value="">-- निवडा --</option>
                  <?= $renderStaffOptions($staffPool) ?>
                </select>
              <?php endif; ?>
              <textarea name="remark" class="form-control-custom mb-2" rows="2" placeholder="टिप्पणी (ऐच्छिक)"></textarea>
              <button type="submit" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13.5px;padding:10px;font-weight:600;">पुढे पाठवा (Forward)</button>
            </form>
          <?php else: ?>
            <div style="background:var(--rail-soft);color:var(--rail);border-radius:9px;padding:10px 14px;font-size:12.5px;margin-bottom:12px;">✅ ही कार्यप्रवाहाची शेवटची पायरी आहे — मंजूर करण्यासाठी "Sign & Approve" करा.</div>
          <?php endif; ?>

          <div class="row g-2 mb-3">
            <div class="<?= $canSign ? 'col-6' : 'col-12' ?>">
              <form method="POST" onsubmit="return confirm('फाईल आधीच्या व्यक्तीकडे परत पाठवायची?');">
                <input type="hidden" name="action" value="return">
                <input type="hidden" name="remark" value="" class="return-remark">
                <button type="submit" class="btn w-100" style="border:1px solid var(--amber);color:#8A5A14;border-radius:9px;font-size:12.5px;padding:9px;" onclick="this.form.querySelector('.return-remark').value = prompt('परत पाठवण्याचं कारण (ऐच्छिक):') || '';">← परत पाठवा</button>
              </form>
            </div>
            <?php if ($canSign): ?>
              <div class="col-6">
                <button type="button" class="btn w-100" style="background:var(--rail);color:#fff;border-radius:9px;font-size:12.5px;padding:9px;font-weight:600;" data-bs-toggle="modal" data-bs-target="#signApproveModal">🔏 Sign & Approve</button>
              </div>
            <?php endif; ?>
          </div>
        <?php elseif ($letter['status'] === 'completed'): ?>
          <p style="font-size:12.5px;color:var(--text-dim);">✅ ही फाईल पूर्ण/दाखल झाली आहे.</p>
        <?php else: ?>
          <p style="font-size:12.5px;color:var(--text-dim);">ही फाईल सध्या <?= h($letter['holder_name'] ?? '—') ?> यांच्याकडे आहे.</p>
        <?php endif; ?>

        <?php if (($isCreator || $isHolder) && $letter['status'] !== 'completed'): ?>
          <form method="POST">
            <input type="hidden" name="action" value="remark">
            <label class="form-label-custom">फक्त टिप्पणी जोडा (फाईल न हलवता)</label>
            <textarea name="remark" class="form-control-custom mb-2" rows="2" required></textarea>
            <button type="submit" class="btn w-100" style="border:1px solid var(--line);border-radius:9px;font-size:12.5px;padding:8px;">टिप्पणी जोडा</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <?php if (hasRole('super_admin') && in_array($letter['status'], ['in_progress', 'returned'], true)): ?>
      <div class="panel mt-3" style="border-color:var(--amber);">
        <div class="panel-head"><div class="title" style="color:#8A5A14;">⚙️ Super Admin — पुन्हा नेमा (Reassign)</div><div class="sub">फाईल कुठे अडकली असेल तर ती कोणाकडेही थेट पाठवा</div></div>
        <form method="POST" style="padding:16px 20px;">
          <input type="hidden" name="action" value="reassign">
          <select name="new_holder_id" class="form-select-custom mb-2" required>
            <option value="">-- व्यक्ती निवडा --</option>
            <?= $renderStaffOptions($staffPool, $letter['current_holder_id']) ?>
          </select>
          <input type="text" name="remark" class="form-control-custom mb-2" placeholder="कारण (ऐच्छिक)">
          <button type="submit" class="btn w-100" style="border:1px solid var(--amber);color:#8A5A14;border-radius:9px;font-size:12.5px;padding:9px;font-weight:600;">पुन्हा नेमा</button>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php if ($canSign): ?>
<div class="modal fade" id="signApproveModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content" style="border-radius:14px;">
      <div class="modal-body" style="padding:24px;">
        <div style="font-family:'Space Grotesk';font-weight:600;font-size:16px;margin-bottom:4px;">🔏 Sign & Approve</div>
        <div style="font-size:12px;color:var(--text-dim);margin-bottom:16px;">ओळख पडताळून फाईल Digitally Sign करून पूर्ण/दाखल करा</div>

        <?php if (!empty($letter['is_budget_expense']) && !$letter['budget_deducted']): ?>
          <div style="font-size:12.5px;color:var(--text-dim);margin-bottom:10px;">
            मागणी रक्कम: <strong>₹<?= number_format($letter['requested_amount'] ?: 0, 0) ?></strong> — <?= h($letter['budget_category'] ?? '') ?>
          </div>
          <label class="form-label-custom">मंजूर रक्कम (₹) — गरज असल्यास बदला</label>
          <input type="number" step="0.01" min="0" id="signApprovedAmount" class="form-control-custom mb-2" value="<?= h($letter['requested_amount'] ?: '') ?>" required>
          <div style="font-size:11px;color:var(--amber);margin-bottom:10px;">⚠️ ही रक्कम संबंधित विभागाच्या Budget मधून आपोआप वजा होईल.</div>
        <?php endif; ?>
        <label class="form-label-custom">टिप्पणी (ऐच्छिक)</label>
        <input type="text" id="signRemark" class="form-control-custom mb-3">

        <div id="signApproveError" class="alert alert-danger" style="display:none;border-radius:8px;font-size:12px;padding:10px 14px;"></div>

        <?php if ($hasFingerprint): ?>
          <button type="button" onclick="eowmsSignApprove(<?= $letterId ?>, 'fingerprint')" class="btn w-100 mb-2" style="background:var(--rail);color:#fff;border-radius:9px;font-size:13px;padding:10px;font-weight:600;">👆 Fingerprint/Face ID ने Sign करा</button>
          <div style="display:flex;align-items:center;gap:10px;margin:12px 0;">
            <div style="flex:1;height:1px;background:var(--line);"></div><span style="font-size:11px;color:var(--text-dim);">किंवा</span><div style="flex:1;height:1px;background:var(--line);"></div>
          </div>
        <?php endif; ?>

        <label class="form-label-custom">🔑 तुमचा Password टाकून Sign करा</label>
        <input type="password" id="signPassword" class="form-control-custom mb-2" placeholder="Password">
        <button type="button" onclick="eowmsSignApprove(<?= $letterId ?>, 'password')" class="btn w-100" style="border:1.5px solid var(--rail);color:var(--rail);background:#fff;border-radius:9px;font-size:13px;padding:9px;font-weight:600;">Password ने Sign करा</button>
      </div>
      <div class="modal-footer" style="border-top:1px solid var(--line);">
        <button type="button" class="btn btn-sm" data-bs-dismiss="modal" style="border:1px solid var(--line);border-radius:7px;">रद्द करा</button>
      </div>
    </div>
  </div>
</div>

<script src="../assets/js/webauthn.js"></script>
<script>
async function eowmsSignApprove(letterId, method) {
  const errBox = document.getElementById('signApproveError');
  errBox.style.display = 'none';
  const amountField = document.getElementById('signApprovedAmount');
  const remark = document.getElementById('signRemark').value;

  const payload = new URLSearchParams();
  payload.set('letter_id', letterId);
  payload.set('method', method);
  payload.set('remark', remark);
  if (amountField) { payload.set('approved_amount', amountField.value); }

  try {
    if (method === 'fingerprint') {
      if (!window.PublicKeyCredential) { throw new Error('हा Browser Fingerprint/Face ID ला समर्थन देत नाही.'); }
      const optRes = await fetch('../webauthn-verify-identity-options.php');
      const options = await optRes.json();
      if (!options.challenge) { throw new Error(options.message || 'Fingerprint सुरू केलेलं नाही.'); }
      options.challenge = b64urlToBuffer(options.challenge);
      options.allowCredentials = (options.allowCredentials || []).map((c) => ({ ...c, id: b64urlToBuffer(c.id) }));

      const assertion = await navigator.credentials.get({ publicKey: options });
      const verifyRes = await fetch('../webauthn-verify-identity-verify.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          id: bufferToB64url(assertion.rawId),
          clientDataJSON: bufferToB64url(assertion.response.clientDataJSON),
          authenticatorData: bufferToB64url(assertion.response.authenticatorData),
          signature: bufferToB64url(assertion.response.signature),
        }),
      });
      const verifyResult = await verifyRes.json();
      if (!verifyResult.success) { throw new Error(verifyResult.message); }
    } else {
      const password = document.getElementById('signPassword').value;
      if (!password) { throw new Error('कृपया Password टाका.'); }
      payload.set('password', password);
    }

    const res = await fetch('sign-approve.php', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: payload.toString() });
    const result = await res.json();
    if (result.success) {
      alert(result.message);
      window.location.reload();
    } else {
      throw new Error(result.message);
    }
  } catch (err) {
    errBox.textContent = err.message || 'चूक झाली — पुन्हा प्रयत्न करा.';
    errBox.style.display = 'block';
  }
}
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
