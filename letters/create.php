<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/helpers.php';
$user = currentUser();

$orgId = activeOrgId();
$error = null;

$stmt = $pdo->prepare("SELECT * FROM letter_categories WHERE organization_id = ? AND is_active = 1 ORDER BY name_local");
$stmt->execute([$orgId]);
$categories = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM workflow_templates WHERE organization_id = ? AND is_active = 1 ORDER BY name");
$stmt->execute([$orgId]);
$workflows = $stmt->fetchAll();

// प्राप्तकर्ते — स्वतःच्या संस्थेचे कर्मचारी (विभागानुसार) + शाखा-संस्था (एकाच Trust परिवारातल्या)
$stmt = $pdo->prepare("
    SELECT u.id, u.name, u.designation, d.name_local AS dept_name FROM users u
    LEFT JOIN departments d ON d.id = u.department_id
    WHERE u.organization_id = ? AND u.is_active = 1 AND u.id != ? ORDER BY d.name_local, u.name
");
$stmt->execute([$orgId, $user['id']]);
$staffPool = $stmt->fetchAll();

$stmt = $pdo->prepare("
    SELECT d.id AS dept_id, d.name_local, d.name, u.id AS hod_id, u.name AS hod_name FROM departments d
    JOIN users u ON u.id = d.hod_id AND u.is_active = 1
    WHERE d.organization_id = ? AND d.is_active = 1 AND u.id != ? ORDER BY d.name_local
");
$stmt->execute([$orgId, $user['id']]);
$hodPool = $stmt->fetchAll();

$sisterStaff = [];
try {
    $myOrgStmt = $pdo->prepare("SELECT parent_organization_id FROM organizations WHERE id = ?");
    $myOrgStmt->execute([$orgId]);
    $myParentId = $myOrgStmt->fetch()['parent_organization_id'] ?? null;
    $sisterStmt = $pdo->prepare("
        SELECT o.name AS org_name, u.id, u.name, u.designation FROM organizations o
        JOIN users u ON u.organization_id = o.id AND u.is_active = 1
        WHERE o.is_active = 1 AND o.id != ? AND (o.id = ? OR o.parent_organization_id = ? OR (? IS NOT NULL AND o.parent_organization_id = ?))
        ORDER BY o.name, u.name
    ");
    $sisterStmt->execute([$orgId, $myParentId, $orgId, $myParentId, $myParentId]);
    $sisterStaff = $sisterStmt->fetchAll();
} catch (PDOException $e) { /* sister-organization column not migrated */ }

$allowedRecipientIds = array_map('intval', array_merge(array_column($staffPool, 'id'), array_column($sisterStaff, 'id')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $subject = trim($_POST['subject'] ?? '');
    $description = trim($_POST['description'] ?? '') ?: null;
    $categoryId = in_array((int)($_POST['category_id'] ?? 0), array_map('intval', array_column($categories, 'id')), true) ? (int)$_POST['category_id'] : null;
    // जावक क्रमांक नेहमी पत्र तयार करणाऱ्याच्या स्वतःच्या विभागानुसार
    $departmentId = $user['department_id'] ?? null;
    $priority = in_array($_POST['priority'] ?? '', ['low', 'normal', 'high', 'urgent'], true) ? $_POST['priority'] : 'normal';
    $referenceNo = trim($_POST['reference_no'] ?? '') ?: null;
    $workflowId = in_array((int)($_POST['workflow_template_id'] ?? 0), array_map('intval', array_column($workflows, 'id')), true) ? (int)$_POST['workflow_template_id'] : null;
    $toUserId = (int)($_POST['to_user_id'] ?? 0);
    $isBudgetExpense = isset($_POST['is_budget_expense']) ? 1 : 0;
    $budgetCategory = $isBudgetExpense && in_array($_POST['budget_category'] ?? '', ['नवीन खरेदी', 'देखभाल', 'इंधन', 'इतर'], true) ? $_POST['budget_category'] : null;
    $requestedAmount = $isBudgetExpense && ($_POST['requested_amount'] ?? '') !== '' ? (float)$_POST['requested_amount'] : null;

    if ($subject === '') {
        $error = 'कृपया विषय भरा.';
    } elseif (!$workflowId && $toUserId && !in_array($toUserId, $allowedRecipientIds, true)) {
        $error = 'निवडलेली व्यक्ती सापडली नाही.';
    } elseif ($isBudgetExpense && (!$departmentId || $requestedAmount === null || $requestedAmount <= 0)) {
        $error = 'Budget खर्चाच्या पत्रासाठी तुमचा विभाग आणि मागणी रक्कम आवश्यक आहे.';
    } else {
        [$fyStart, $fyEnd] = getFinancialYearRange();
        $countStmt = $pdo->prepare("SELECT COUNT(*) c FROM letters WHERE organization_id = ? AND created_at BETWEEN ? AND ?");
        $countStmt->execute([$orgId, $fyStart . ' 00:00:00', $fyEnd . ' 23:59:59']);
        $seq = $countStmt->fetch()['c'] + 1;
        $fileNumber = generateUniqueCode($pdo, 'letters', 'file_number', 'FILE-%s%04d', $seq, getFinancialYearLabel());
        $outwardNo = $departmentId ? generateOutwardNumber($pdo, $orgId, $departmentId) : null;

        $pdo->prepare("
            INSERT INTO letters (organization_id, file_number, outward_no, reference_no, is_budget_expense, budget_category, requested_amount, subject, description, category_id, department_id, priority, status, workflow_template_id, current_step_order, current_holder_id, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?, 0, NULL, ?)
        ")->execute([$orgId, $fileNumber, $outwardNo, $referenceNo, $isBudgetExpense, $budgetCategory, $requestedAmount, $subject, $description, $categoryId, $departmentId, $priority, $workflowId, $user['id']]);
        $letterId = (int)$pdo->lastInsertId();

        $pdo->prepare("INSERT INTO letter_actions (letter_id, action_type, from_user_id, remark) VALUES (?, 'created', ?, ?)")
            ->execute([$letterId, $user['id'], 'फाईल तयार केली — ' . $fileNumber . ($outwardNo ? " (जावक क्र.: $outwardNo)" : '')]);
        logActivity('पत्र/फाईल तयार केली', 'letter', $letterId, $fileNumber);

        $letter = ['id' => $letterId, 'subject' => $subject, 'file_number' => $fileNumber, 'department_id' => $departmentId,
                   'workflow_template_id' => $workflowId, 'current_step_order' => 0];

        if ($workflowId) {
            // कार्यप्रवाह निवडला — लगेच पहिल्या पायरीकडे
            $result = advanceLetterToNextStep($pdo, $letter);
            if ($result['error']) {
                $_SESSION['form_error'] = "'$fileNumber' तयार झाली (Draft), पण पाठवता आली नाही: " . $result['error'];
            } else {
                letterSendTo($pdo, $letter, $result['holder'], $user['id'], 'submitted', null, $orgId, true);
                $_SESSION['flash'] = "'$fileNumber' फाईल तयार होऊन कार्यप्रवाहाच्या पहिल्या पायरीकडे पाठवली गेली.";
            }
        } elseif ($toUserId) {
            letterSendTo($pdo, $letter, $toUserId, $user['id'], 'submitted', null, $orgId);
            $_SESSION['flash'] = "'$fileNumber' फाईल तयार होऊन पाठवली गेली.";
        } else {
            $_SESSION['flash'] = "'$fileNumber' फाईल तयार झाली (Draft) — आता Submit करून पाठवा.";
        }

        // जोडपत्र — तयार करतानाच
        if (isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'pdf', 'doc', 'docx', 'xls', 'xlsx'], true)) {
                $uploadDir = __DIR__ . '/../assets/uploads/letters/' . $letterId . '/';
                if (!is_dir($uploadDir)) { mkdir($uploadDir, 0755, true); }
                $safeName = uniqid('att_') . '.' . $ext;
                if (move_uploaded_file($_FILES['attachment']['tmp_name'], $uploadDir . $safeName)) {
                    $pdo->prepare("INSERT INTO letter_attachments (letter_id, file_path, original_name, uploaded_by) VALUES (?, ?, ?, ?)")
                        ->execute([$letterId, 'assets/uploads/letters/' . $letterId . '/' . $safeName, $_FILES['attachment']['name'], $user['id']]);
                }
            }
        }

        header('Location: view.php?id=' . $letterId);
        exit;
    }
}

// निर्देशिकेतून ("या व्यक्तीला पत्र") आलेलं असेल तर प्राप्तकर्ता आधीच निवडलेला
$preselectedUser = (int)($_GET['to_user'] ?? ($_POST['to_user_id'] ?? 0));
$preselectedDept = (int)($_GET['to_dept'] ?? 0);
if ($preselectedDept && !$preselectedUser) {
    foreach ($hodPool as $hd) { if ((int)$hd['dept_id'] === $preselectedDept) { $preselectedUser = (int)$hd['hod_id']; } }
}

$ownDeptName = '—';
if (!empty($user['department_id'])) {
    $ownDeptStmt = $pdo->prepare("SELECT name_local, name FROM departments WHERE id = ?");
    $ownDeptStmt->execute([$user['department_id']]);
    $ownDept = $ownDeptStmt->fetch();
    if ($ownDept) { $ownDeptName = $ownDept['name_local'] ?: $ownDept['name']; }
}

$pageTitle = 'नवीन पत्र / फाईल';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
$val = fn($k, $d = '') => h($_POST[$k] ?? $d);
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title">नवीन पत्र / फाईल तयार करा</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>पत्र व्यवहार</a>
</div>

<?php if ($error): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($error) ?></div>
<?php endif; ?>

<div class="panel">
  <form method="POST" enctype="multipart/form-data" style="padding:22px 24px;">
    <div class="row g-3">
      <div class="col-12">
        <label class="form-label-custom">विषय</label>
        <input type="text" name="subject" class="form-control-custom" required value="<?= $val('subject') ?>" placeholder="उदा. नवीन जनरेटर खरेदीसाठी परवानगी अर्ज">
      </div>
      <div class="col-12">
        <label class="form-label-custom">वर्णन / तपशील</label>
        <textarea name="description" class="form-control-custom" rows="4"><?= $val('description') ?></textarea>
      </div>
      <div class="col-md-6">
        <label class="form-label-custom">संदर्भ क्रमांक <span style="font-weight:400;color:var(--text-dim);font-size:11px;">(ऐच्छिक)</span></label>
        <input type="text" name="reference_no" class="form-control-custom" value="<?= $val('reference_no') ?>" placeholder="उदा. MTN/25-26/0034">
      </div>
      <div class="col-md-6">
        <label class="form-label-custom">जोडपत्र <span style="font-weight:400;color:var(--text-dim);font-size:11px;">(ऐच्छिक — PDF/फोटो/Word/Excel)</span></label>
        <input type="file" name="attachment" class="form-control-custom">
      </div>

      <div class="col-md-4">
        <label class="form-label-custom">पत्र प्रकार</label>
        <select name="category_id" class="form-select-custom">
          <option value="">-- निवडा --</option>
          <?php foreach ($categories as $c): ?><option value="<?= $c['id'] ?>" <?= (int)($_POST['category_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= h($c['name_local'] ?: $c['name']) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">कोणत्या विभागाकडून <span style="font-weight:400;color:var(--text-dim);font-size:11px;">(जावक क्र. यावरून)</span></label>
        <input type="text" class="form-control-custom" value="<?= h($ownDeptName) ?>" disabled style="background:var(--canvas);">
      </div>
      <div class="col-md-4">
        <label class="form-label-custom">प्राधान्य</label>
        <select name="priority" class="form-select-custom">
          <?php foreach (['normal' => 'सामान्य', 'low' => 'कमी', 'high' => 'जास्त', 'urgent' => 'तातडीचे'] as $pk => $pl): ?>
            <option value="<?= $pk ?>" <?= ($_POST['priority'] ?? 'normal') === $pk ? 'selected' : '' ?>><?= $pl ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="col-12">
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;background:var(--canvas);padding:12px 16px;border-radius:9px;">
          <input type="checkbox" name="is_budget_expense" value="1" <?= isset($_POST['is_budget_expense']) ? 'checked' : '' ?> onclick="document.getElementById('budgetExpenseFields').style.display=this.checked?'flex':'none';">
          💰 ही खर्चाची मागणी आहे — मंजूर झाल्यावर रक्कम विभागाच्या Budget मधून आपोआप वजा व्हावी
        </label>
      </div>
      <div class="col-12 row g-3" id="budgetExpenseFields" style="display:<?= isset($_POST['is_budget_expense']) ? 'flex' : 'none' ?>;margin:0;">
        <div class="col-md-6">
          <label class="form-label-custom">कशासाठी (Budget श्रेणी)</label>
          <select name="budget_category" class="form-select-custom">
            <?php foreach (['नवीन खरेदी' => '🆕 नवीन खरेदी', 'देखभाल' => '🔧 देखभाल', 'इंधन' => '⛽ इंधन', 'इतर' => '📋 इतर'] as $bk => $bl): ?>
              <option value="<?= $bk ?>" <?= ($_POST['budget_category'] ?? '') === $bk ? 'selected' : '' ?>><?= $bl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="col-md-6">
          <label class="form-label-custom">मागणी रक्कम (₹)</label>
          <input type="number" step="0.01" min="1" name="requested_amount" class="form-control-custom" value="<?= $val('requested_amount') ?>" placeholder="उदा. 50000">
        </div>
      </div>

      <div class="col-12" style="border-top:1px solid var(--line);padding-top:16px;">
        <div style="font-weight:700;font-size:14px;margin-bottom:10px;">📤 कोणाकडे पाठवायचे</div>
        <?php if (!empty($workflows)): ?>
          <label class="form-label-custom">कार्यप्रवाह (Workflow) <span style="font-weight:400;color:var(--text-dim);font-size:11px;">— ठरलेल्या क्रमाने आपोआप पुढे जाईल</span></label>
          <select name="workflow_template_id" id="workflowField" class="form-select-custom mb-3" onchange="document.getElementById('recipientBlock').style.display = this.value ? 'none' : 'block';">
            <option value="">-- नाही, थेट एखाद्या व्यक्तीकडे पाठवा --</option>
            <?php foreach ($workflows as $w): ?><option value="<?= $w['id'] ?>" <?= (int)($_POST['workflow_template_id'] ?? 0) === (int)$w['id'] ? 'selected' : '' ?>><?= h($w['name']) ?></option><?php endforeach; ?>
          </select>
        <?php endif; ?>
        <div id="recipientBlock" style="display:<?= !empty($_POST['workflow_template_id']) ? 'none' : 'block' ?>;">
          <label class="form-label-custom">व्यक्ती / विभाग-प्रमुख</label>
          <select name="to_user_id" class="form-select-custom">
            <option value="">-- आत्ता नाही, Draft म्हणून ठेवा --</option>
            <?php if (!empty($hodPool)): ?>
              <optgroup label="विभाग-प्रमुख (HOD)">
                <?php foreach ($hodPool as $hd): ?>
                  <option value="<?= $hd['hod_id'] ?>" <?= $preselectedUser === (int)$hd['hod_id'] ? 'selected' : '' ?>><?= h($hd['name_local'] ?: $hd['name']) ?> — <?= h($hd['hod_name']) ?></option>
                <?php endforeach; ?>
              </optgroup>
            <?php endif; ?>
            <?php $currentDept = null; foreach ($staffPool as $s):
              $dept = $s['dept_name'] ?: 'इतर';
              if ($dept !== $currentDept): if ($currentDept !== null): ?></optgroup><?php endif; ?><optgroup label="<?= h($dept) ?>"><?php $currentDept = $dept; endif; ?>
              <option value="<?= $s['id'] ?>" <?= $preselectedUser === (int)$s['id'] && !in_array($preselectedUser, array_map('intval', array_column($hodPool, 'hod_id')), true) ? 'selected' : '' ?>><?= h($s['name']) ?><?= $s['designation'] ? ' (' . h($s['designation']) . ')' : '' ?></option>
            <?php endforeach; if ($currentDept !== null): ?></optgroup><?php endif; ?>
            <?php $currentOrg = null; foreach ($sisterStaff as $s):
              if ($s['org_name'] !== $currentOrg): if ($currentOrg !== null): ?></optgroup><?php endif; ?><optgroup label="🏢 <?= h($s['org_name']) ?>"><?php $currentOrg = $s['org_name']; endif; ?>
              <option value="<?= $s['id'] ?>" <?= $preselectedUser === (int)$s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?><?= $s['designation'] ? ' (' . h($s['designation']) . ')' : '' ?></option>
            <?php endforeach; if ($currentOrg !== null): ?></optgroup><?php endif; ?>
          </select>
        </div>
      </div>

      <div class="col-12">
        <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:14px;padding:11px 26px;font-weight:600;"><i class="fa-solid fa-paper-plane me-1"></i>फाईल तयार करा</button>
      </div>
    </div>
  </form>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
