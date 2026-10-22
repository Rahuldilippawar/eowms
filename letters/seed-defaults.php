<?php
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$user = currentUser();
$orgId = activeOrgId();

// ---------------- मानक पत्र-प्रकार (शासकीय/संस्था कार्यालयात नेहमी लागणारे) ----------------
$standardCategories = [
    'आवक पत्र' => 'Inward Letter',
    'जावक पत्र' => 'Outward Letter',
    'परिपत्रक' => 'Circular',
    'आदेश' => 'Order',
    'सूचना' => 'Notice',
    'अर्ज/विनंती' => 'Application/Request',
    'निवेदन' => 'Representation',
    'मंजुरी पत्र' => 'Approval Letter',
    'तक्रार' => 'Complaint',
    'आमंत्रण पत्र' => 'Invitation Letter',
    'निविदा/करार' => 'Tender/Contract',
    'स्मरणपत्र' => 'Reminder',
    'खरेदी मागणी' => 'Purchase Requisition',
    'शासन निर्णय (GR) संदर्भ' => 'Government Resolution Reference',
    'नियुक्ती पत्र' => 'Appointment Letter',
    'आर्थिक/Budget मंजुरी' => 'Financial/Budget Approval',
    'माहिती अधिकार (RTI)' => 'Right to Information',
    'सर्वसाधारण पत्रव्यवहार' => 'General Correspondence',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $created = ['categories' => 0, 'workflows' => 0];

    // ---------------- 1. पत्र-प्रकार तयार करतो (आधीच असेल तर वगळतो) ----------------
    $existingCatStmt = $pdo->prepare("SELECT name FROM letter_categories WHERE organization_id = ?");
    $existingCatStmt->execute([$orgId]);
    $existingCats = $existingCatStmt->fetchAll(PDO::FETCH_COLUMN);

    foreach ($standardCategories as $nameLocal => $nameEn) {
        if (in_array($nameLocal, $existingCats, true)) continue;
        $pdo->prepare("INSERT INTO letter_categories (organization_id, name, name_local) VALUES (?, ?, ?)")
            ->execute([$orgId, $nameEn, $nameLocal]);
        $created['categories']++;
    }

    // ---------------- 2. सगळ्यात वरिष्ठ Management व्यक्ती शोधतो (Workflow च्या शेवटच्या टप्प्यासाठी) ----------------
    $seniorityOrder = ['president', 'executive_president', 'general_manager', 'deputy_general_manager', 'assistant_general_manager', 'vice_president', 'director', 'secretary', 'treasurer'];
    $topManagementUserId = null;
    $topManagementLabel = '';
    foreach ($seniorityOrder as $roleKey) {
        $stmt = $pdo->prepare("SELECT id, name FROM users WHERE organization_id = ? AND role = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$orgId, $roleKey]);
        $found = $stmt->fetch();
        if ($found) { $topManagementUserId = $found['id']; $topManagementLabel = $found['name']; break; }
    }
    // वरीलपैकी कोणीच सापडलं नाही तर — कुठलीही एक Management-श्रेणीतली व्यक्ती
    if (!$topManagementUserId) {
        $stmt = $pdo->prepare("
            SELECT u.id, u.name FROM users u JOIN role_definitions rd ON rd.role_key = u.role
            WHERE u.organization_id = ? AND u.is_active = 1 AND rd.category = 'management' LIMIT 1
        ");
        $stmt->execute([$orgId]);
        $found = $stmt->fetch();
        if ($found) { $topManagementUserId = $found['id']; $topManagementLabel = $found['name']; }
    }

    // "लेखा/Accounts" या नावाशी जुळणारा विभाग असेल तर — Budget-Workflow साठी उपयोगी
    $accountsDeptStmt = $pdo->prepare("SELECT id, hod_id, name_local, name FROM departments WHERE organization_id = ? AND is_active = 1 AND (name_local LIKE '%लेखा%' OR name LIKE '%Account%' OR name_local LIKE '%हिशेब%') LIMIT 1");
    $accountsDeptStmt->execute([$orgId]);
    $accountsDept = $accountsDeptStmt->fetch();

    // ---------------- 3. मानक Workflows तयार करतो ----------------
    $existingWfStmt = $pdo->prepare("SELECT name FROM workflow_templates WHERE organization_id = ?");
    $existingWfStmt->execute([$orgId]);
    $existingWf = $existingWfStmt->fetchAll(PDO::FETCH_COLUMN);

    function createWorkflow($pdo, $orgId, $name, $steps, &$existingWf, &$created) {
        if (in_array($name, $existingWf, true)) return;
        $pdo->prepare("INSERT INTO workflow_templates (organization_id, name) VALUES (?, ?)")->execute([$orgId, $name]);
        $wfId = $pdo->lastInsertId();
        $order = 1;
        foreach ($steps as $step) {
            $pdo->prepare("INSERT INTO workflow_steps (workflow_template_id, step_order, step_name, approver_type, department_id, user_id) VALUES (?, ?, ?, ?, ?, ?)")
                ->execute([$wfId, $order, $step['name'], $step['type'], $step['dept_id'] ?? null, $step['user_id'] ?? null]);
            $order++;
        }
        $created['workflows']++;
    }

    // (अ) फक्त विभाग-प्रमुखाची मंजुरी — सर्वात सोपं, कुठल्याही विभागातून सुरू होणाऱ्या पत्रासाठी
    createWorkflow($pdo, $orgId, '① फक्त विभाग-प्रमुख मंजुरी', [
        ['name' => 'विभाग-प्रमुखाकडे', 'type' => 'letter_dept_hod'],
    ], $existingWf, $created);

    // (आ) साधी 2-स्तरीय मंजुरी — विभाग-प्रमुख → वरिष्ठ व्यवस्थापन
    if ($topManagementUserId) {
        createWorkflow($pdo, $orgId, '② साधी मंजुरी (विभाग-प्रमुख → व्यवस्थापन)', [
            ['name' => 'विभाग-प्रमुखाकडे', 'type' => 'letter_dept_hod'],
            ['name' => "$topManagementLabel कडे (अंतिम मंजुरी)", 'type' => 'specific_user', 'user_id' => $topManagementUserId],
        ], $existingWf, $created);
    }

    // (इ) खरेदी/आर्थिक मंजुरी — विभाग-प्रमुख → लेखा विभाग (असल्यास) → वरिष्ठ व्यवस्थापन
    if ($topManagementUserId) {
        $steps = [['name' => 'विभाग-प्रमुखाकडे', 'type' => 'letter_dept_hod']];
        if ($accountsDept) {
            $steps[] = ['name' => ($accountsDept['name_local'] ?: $accountsDept['name']) . ' विभागाकडे (पडताळणी)', 'type' => 'department_hod', 'dept_id' => $accountsDept['id']];
        }
        $steps[] = ['name' => "$topManagementLabel कडे (अंतिम आर्थिक मंजुरी)", 'type' => 'specific_user', 'user_id' => $topManagementUserId];
        createWorkflow($pdo, $orgId, '③ खरेदी/आर्थिक मंजुरी', $steps, $existingWf, $created);
    }

    // (ई) तातडीचं — थेट वरिष्ठ व्यवस्थापनाकडे
    if ($topManagementUserId) {
        createWorkflow($pdo, $orgId, '④ तातडीचं — थेट व्यवस्थापनाकडे', [
            ['name' => "$topManagementLabel कडे (थेट, तातडीने)", 'type' => 'specific_user', 'user_id' => $topManagementUserId],
        ], $existingWf, $created);
    }

    logActivity('मानक पत्र-प्रकार व Workflows तयार केले', 'letter_setup', null, "{$created['categories']} प्रकार, {$created['workflows']} Workflows");
    $_SESSION['flash'] = "✅ {$created['categories']} नवीन पत्र-प्रकार आणि {$created['workflows']} नवीन Workflows तयार झाले." . ($created['categories'] + $created['workflows'] === 0 ? ' (सगळे आधीच अस्तित्वात होते.)' : '');
    header('Location: categories.php');
    exit;
}

// GET — Preview दाखवण्यासाठी
$existingCatStmt = $pdo->prepare("SELECT name FROM letter_categories WHERE organization_id = ?");
$existingCatStmt->execute([$orgId]);
$existingCats = $existingCatStmt->fetchAll(PDO::FETCH_COLUMN);
$newCatCount = 0;
foreach ($standardCategories as $nameLocal => $nameEn) { if (!in_array($nameLocal, $existingCats, true)) { $newCatCount++; } }

$pageTitle = 'मानक पत्र-प्रकार व Workflows तयार करा';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="page-eyebrow">Super Admin</div>
<div class="page-title mb-1">📋 मानक पत्र-प्रकार व Workflows — एका क्लिकवर तयार करा</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:20px;">शासकीय/संस्था-कार्यालयात नेहमी लागणारे १८ पत्र-प्रकार आणि ४ ठराविक Workflows — तुमच्या संस्थेतल्या सध्याच्या विभाग/व्यवस्थापन-रचनेनुसार आपोआप जुळवून तयार केले जातील. आधीच अस्तित्वात असलेले (नावाने जुळणारे) वगळले जातील — Duplicate होणार नाहीत.</p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="panel">
      <div class="panel-head"><div class="title">📁 तयार होणारे पत्र-प्रकार (<?= $newCatCount ?> नवीन)</div></div>
      <div style="padding:14px 20px;">
        <?php foreach ($standardCategories as $nameLocal => $nameEn):
          $exists = in_array($nameLocal, $existingCats, true);
        ?>
          <div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--line);font-size:12.5px;">
            <span><?= h($nameLocal) ?></span>
            <?php if ($exists): ?><span style="color:var(--text-dim);font-size:11px;">आधीच आहे</span><?php else: ?><span style="color:var(--rail);font-size:11px;">✨ नवीन</span><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="panel">
      <div class="panel-head"><div class="title">🔀 तयार होणारे Workflows</div></div>
      <div style="padding:14px 20px;font-size:12.5px;">
        <div style="margin-bottom:12px;">
          <strong>① फक्त विभाग-प्रमुख मंजुरी</strong>
          <div style="color:var(--text-dim);">विभाग-प्रमुखाकडे → पूर्ण</div>
        </div>
        <div style="margin-bottom:12px;">
          <strong>② साधी मंजुरी (2-स्तर)</strong>
          <div style="color:var(--text-dim);">विभाग-प्रमुख → वरिष्ठ व्यवस्थापन</div>
        </div>
        <div style="margin-bottom:12px;">
          <strong>③ खरेदी/आर्थिक मंजुरी</strong>
          <div style="color:var(--text-dim);">विभाग-प्रमुख → लेखा विभाग (असल्यास) → वरिष्ठ व्यवस्थापन</div>
        </div>
        <div>
          <strong>④ तातडीचं — थेट व्यवस्थापनाकडे</strong>
          <div style="color:var(--text-dim);">थेट वरिष्ठ व्यवस्थापनाकडे, एकाच टप्प्यात</div>
        </div>
        <div style="margin-top:14px;font-size:11px;color:var(--text-dim);border-top:1px solid var(--line);padding-top:10px;">
          ⚠️ "वरिष्ठ व्यवस्थापन" म्हणजे — सध्या नोंदवलेल्या सर्वात वरिष्ठ पदावरील (President/Executive President/GM/इ.) सक्रिय व्यक्ती. ती व्यक्ती नंतर बदलल्यास, Workflow Edit करून अद्ययावत करावी लागेल.
        </div>
      </div>
    </div>
  </div>
</div>

<form method="POST" class="mt-3">
  <button type="submit" class="btn" style="background:var(--rail);color:#fff;border-radius:9px;font-size:14px;padding:11px 28px;font-weight:600;">✅ हे सगळं तयार करा</button>
  <a href="categories.php" class="btn" style="border:1px solid var(--line);border-radius:9px;font-size:14px;padding:11px 24px;">रद्द करा</a>
</form>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
