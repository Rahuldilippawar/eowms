<?php
$pageTitle = 'दैनंदिन कामाचा अहवाल';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

// स्वतःचा विभाग + Super Admin ने दिलेले Extra विभाग (null = सगळे)
$entryVisibleDeptIds = getVisibleDepartmentIds();
$entryDeptFilter = '';
$entryDeptParams = [];
if ($entryVisibleDeptIds !== null) {
    $entryDeptIdsSafe = $entryVisibleDeptIds ?: [0];
    $entryDeptFilter = ' AND %s IN (' . implode(',', array_fill(0, count($entryDeptIdsSafe), '?')) . ')';
    $entryDeptParams = $entryDeptIdsSafe;
}

$stmt = $pdo->prepare("SELECT * FROM departments WHERE organization_id = ? AND is_active = 1" . sprintf($entryDeptFilter, 'id') . " ORDER BY name_local");
$stmt->execute(array_merge([activeOrgId()], $entryDeptParams));
$departments = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT id, name, department_id, designation FROM users WHERE organization_id = ? AND is_active = 1" . sprintf($entryDeptFilter, 'department_id') . " ORDER BY name");
$stmt->execute(array_merge([activeOrgId()], $entryDeptParams));
$allStaff = $stmt->fetchAll();

$stmt = $pdo->prepare("SELECT * FROM campuses WHERE organization_id = ? AND is_active = 1 ORDER BY (college_type = 'Trust HQ') DESC, name");
$stmt->execute([activeOrgId()]);
$campusesForEntry = $stmt->fetchAll();
$showCampusPickerForRequester = count($campusesForEntry) > 1;

// Dynamic organization-specific fields — added by Super Admin per organization's own rules
$customFields = [];
try {
    $cfStmt = $pdo->prepare("SELECT * FROM custom_fields WHERE organization_id = ? AND is_active = 1 ORDER BY sort_order, id");
    $cfStmt->execute([activeOrgId()]);
    $customFields = $cfStmt->fetchAll();
} catch (PDOException $e) {
    // custom_fields table not migrated yet — form simply shows no extra fields
}

// Store Item यादी — कामासाठी वापरलेलं साहित्य नोंदवण्यासाठी (ऐच्छिक)
$storeItems = [];
try {
    $itemStmt = $pdo->prepare("SELECT id, item_name, unit, category FROM store_items WHERE organization_id = ? AND is_active = 1 ORDER BY category IS NULL OR category = '', category, item_name");   // प्रकार (Head) नुसार
    $itemStmt->execute([activeOrgId()]);
    $storeItems = $itemStmt->fetchAll();
} catch (PDOException $e) { /* store_items not available */ }
?>

<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start">
  <div class="page-title mb-0"><?= h(t('s66')) ?></div>
  <?php if (hasRole('super_admin')): ?>
    <a href="import.php" style="font-size:11.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-file-import me-1"></i>जुन्या नोंदी Import करा</a>
  <?php endif; ?>
</div>
<div style="font-size:11.5px;color:var(--text-dim);margin-top:2px;margin-bottom:14px;">📴 Internet नसतानाही ही नोंद भरता येते — फोनवर सुरक्षित राहील, Internet आल्यावर आपोआप पाठवली जाईल.</div>

<div class="mode-tabs mb-4" style="display:flex;gap:8px;background:var(--surface);border:1px solid var(--line);border-radius:12px;padding:5px;width:fit-content;">
  <a href="entry.php" class="mode-tab active" style="padding:9px 20px;border-radius:9px;font-size:13.5px;font-weight:600;background:var(--ink);color:#fff;text-decoration:none;"><?= h(t('s89')) ?></a>
  <a href="report.php" class="mode-tab" style="padding:9px 20px;border-radius:9px;font-size:13.5px;font-weight:600;color:var(--text-dim);text-decoration:none;"><?= h(t('s90')) ?></a>
</div>

<?php if (!empty($_SESSION['form_error'])): ?>
  <div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['form_error']) ?></div>
  <?php unset($_SESSION['form_error']); ?>
<?php endif; ?>

<?php
// अलीकडची स्वतःची शीर्षकं — पुन्हा पुन्हा होणारी कामं एका टॅपवर
$recentTitles = [];
try {
    $rt = $pdo->prepare("SELECT title, MAX(id) mx FROM work_records WHERE entered_by_user_id = ? AND title <> '' AND work_date >= DATE_SUB(CURDATE(), INTERVAL 60 DAY) GROUP BY title ORDER BY mx DESC LIMIT 12");
    $rt->execute([$user['id']]);
    $recentTitles = $rt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $e) { /* ignore */ }
$weMyDept = (int)($user['department_id'] ?? 0);
$weCfRequired = false;
foreach ($customFields as $cf) { if ($cf['is_required']) { $weCfRequired = true; break; } }
$weTeamSearch = count($allStaff) > 10;
?>
<style>
  .we{padding:18px 20px 6px;}
  .we-sec{margin-bottom:16px;}
  .we-lbl{display:flex;align-items:center;gap:6px;font-size:11.5px;font-weight:700;color:var(--text-dim);margin-bottom:6px;letter-spacing:.02em;}
  .we-lbl .we-cnt{margin-left:auto;font-weight:700;font-size:11px;color:var(--rail);background:rgba(37,99,235,.08);padding:2px 8px;border-radius:999px;}
  .we-title{font-size:16px !important;font-weight:600;padding:12px 14px !important;}
  .we-recent{display:flex;gap:6px;overflow-x:auto;padding:6px 0 2px;scrollbar-width:none;}
  .we-recent::-webkit-scrollbar{display:none;}
  .we-recent button{flex:0 0 auto;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;border:1px dashed var(--line);background:var(--canvas);color:var(--text);font-size:11.5px;border-radius:999px;padding:4px 10px;}
  .we-recent button:hover{border-color:var(--rail);color:var(--rail);}
  .we-seg{display:flex;background:var(--canvas);border:1px solid var(--line);border-radius:11px;padding:3px;gap:3px;}
  .we-seg label{flex:1;margin:0;}
  .we-seg input{position:absolute;opacity:0;pointer-events:none;}
  .we-seg span{display:flex;align-items:center;justify-content:center;gap:5px;padding:9px 6px;border-radius:8px;font-size:12.5px;font-weight:600;color:var(--text-dim);cursor:pointer;white-space:nowrap;transition:.15s;}
  .we-seg input:checked + span{background:var(--surface);color:var(--ink);box-shadow:0 1px 3px rgba(0,0,0,.12);}
  .we-seg input:focus-visible + span{outline:2px solid var(--rail);}
  .we-seg.st input[value=pending]:checked + span{color:#B45309;}
  .we-seg.st input[value=in_progress]:checked + span{color:#2563EB;}
  .we-seg.st input[value=completed]:checked + span{color:#047857;}
  .we-pri{display:flex;gap:6px;flex-wrap:wrap;}
  .we-pri label{margin:0;}
  .we-pri input{position:absolute;opacity:0;pointer-events:none;}
  .we-pri span{display:inline-flex;align-items:center;gap:5px;padding:7px 12px;border:1px solid var(--line);border-radius:999px;font-size:12px;font-weight:600;color:var(--text-dim);cursor:pointer;background:var(--surface);}
  .we-pri span i{width:8px;height:8px;border-radius:50%;display:inline-block;}
  .we-pri input:checked + span{border-color:currentColor;background:var(--canvas);}
  .we-pri input[value=urgent]:checked + span{color:#DC2626;}
  .we-pri input[value=high]:checked + span{color:#EA580C;}
  .we-pri input[value=normal]:checked + span{color:#2563EB;}
  .we-pri input[value=low]:checked + span{color:#64748B;}
  .we-date{display:flex;gap:6px;align-items:center;}
  .we-date input{flex:1;min-width:0;}
  .we-date button{flex:0 0 auto;border:1px solid var(--line);background:var(--surface);border-radius:8px;font-size:11.5px;font-weight:600;padding:8px 10px;color:var(--text-dim);}
  .we-date button.on{background:var(--ink);color:#fff;border-color:var(--ink);}
  .we-team{border:1px solid var(--line);border-radius:12px;padding:8px;}
  .we-team-tools{display:flex;gap:6px;margin-bottom:8px;}
  .we-team-tools input{flex:1;min-width:0;font-size:12.5px !important;padding:7px 10px !important;}
  .we-team-tools button{flex:0 0 auto;border:1px solid var(--line);background:var(--surface);border-radius:8px;font-size:11.5px;padding:6px 10px;color:var(--text-dim);font-weight:600;}
  .we-chips{display:flex;flex-wrap:wrap;gap:6px;max-height:168px;overflow-y:auto;}
  .we-chip{margin:0;}
  .we-chip input{position:absolute;opacity:0;pointer-events:none;}
  .we-chip span{display:inline-flex;align-items:center;gap:6px;padding:6px 11px 6px 6px;border:1px solid var(--line);border-radius:999px;font-size:12.5px;cursor:pointer;background:var(--surface);max-width:260px;}
  .we-chip span b{width:24px;height:24px;flex:0 0 24px;border-radius:50%;background:var(--canvas);color:var(--text-dim);display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700;}
  .we-chip span em{font-style:normal;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .we-chip span small{color:var(--text-dim);font-size:10.5px;}
  .we-chip input:checked + span{background:var(--ink);color:#fff;border-color:var(--ink);}
  .we-chip input:checked + span b{background:#22C55E;color:#fff;}
  .we-chip input:checked + span small{color:rgba(255,255,255,.7);}
  .we-chip.hide{display:none;}
  .we-photos{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
  .we-ph{position:relative;margin:0;border:1.5px dashed var(--line);border-radius:12px;min-height:84px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;cursor:pointer;background:var(--canvas);overflow:hidden;text-align:center;padding:8px;}
  .we-ph:hover{border-color:var(--rail);}
  .we-ph input{position:absolute;inset:0;opacity:0;cursor:pointer;}
  .we-ph .ic{font-size:20px;}
  .we-ph .t{font-size:12px;font-weight:600;}
  .we-ph .s{font-size:10.5px;color:var(--text-dim);}
  .we-ph img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;}
  .we-ph.has{border-style:solid;border-color:#22C55E;}
  .we-ph .x{position:absolute;top:4px;right:4px;z-index:2;background:rgba(0,0,0,.6);color:#fff;border:0;border-radius:50%;width:22px;height:22px;font-size:12px;line-height:22px;padding:0;display:none;}
  .we-ph.has .x{display:block;}
  .we-more{border:1px solid var(--line);border-radius:12px;margin-bottom:14px;}
  .we-more > summary{list-style:none;cursor:pointer;padding:12px 14px;font-size:13px;font-weight:700;display:flex;align-items:center;gap:8px;}
  .we-more > summary::-webkit-details-marker{display:none;}
  .we-more > summary .hint{font-weight:400;font-size:11px;color:var(--text-dim);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1;min-width:0;}
  .we-more > summary .chev{transition:.2s;color:var(--text-dim);}
  .we-more[open] > summary .chev{transform:rotate(180deg);}
  .we-more > .we-in{padding:4px 14px 14px;border-top:1px solid var(--line);}
  .we-sub{font-size:11px;letter-spacing:.05em;text-transform:uppercase;color:var(--rail);font-weight:700;margin:14px 0 6px;}
  .we-bar{display:flex;justify-content:flex-end;align-items:center;gap:8px;padding:12px 0 14px;}
  .we-bar .sum{margin-right:auto;font-size:11.5px;color:var(--text-dim);}
  .we-bar .btn-go{background:var(--rail);color:#fff;border-radius:10px;font-size:14px;padding:10px 22px;font-weight:700;}
  @media (max-width:767px){
    .we{padding:14px 12px 4px;}
    .we-bar{position:sticky;bottom:70px;z-index:20;background:var(--surface);margin:0 -12px;padding:10px 12px;border-top:1px solid var(--line);box-shadow:0 -6px 14px rgba(0,0,0,.05);}
    .we-bar .sum{display:none;}
    .we-bar .btn-go{flex:1;}
    .we-seg span{font-size:12px;padding:9px 4px;}
  }
</style>

<div class="panel">
  <div class="panel-head">
    <div class="title"><?= h(t('s216')) ?></div>
    <div class="sub"><?= h(t('s217')) ?></div>
  </div>
  <form method="POST" action="save.php" enctype="multipart/form-data" id="workEntryForm" class="we" onsubmit="return validateTeamSelection()"
    data-offline-queue="daily-work-entry" data-offline-label="कामाची नोंद" data-offline-redirect="../index.php">

    <div class="we-sec">
      <label class="we-lbl" for="weTitle">📝 <?= h(t('s221')) ?> *</label>
      <input type="text" name="title" id="weTitle" class="form-control-custom we-title" placeholder="उदा. जनरेटर एएमसी तिमाही सर्व्हिसिंग" list="weTitleList" autocomplete="off" required>
      <?php if ($recentTitles): ?>
        <datalist id="weTitleList"><?php foreach ($recentTitles as $rtl): ?><option value="<?= h($rtl) ?>"><?php endforeach; ?></datalist>
        <div class="we-recent" aria-label="अलीकडची कामं">
          <?php foreach (array_slice($recentTitles, 0, 6) as $rtl): ?>
            <button type="button" data-t="<?= h($rtl) ?>">↻ <?= h($rtl) ?></button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <textarea name="description" class="form-control-custom mt-2" rows="2" placeholder="केलेल्या कामाचा तपशील लिहा… (ऐच्छिक)" aria-label="<?= h(t('s222')) ?>"></textarea>
    </div>

    <div class="row g-3 we-sec">
      <div class="col-md-6">
        <div class="we-lbl"><?= h(t('s220')) ?></div>
        <div class="we-seg st" role="radiogroup">
          <label><input type="radio" name="status" value="pending" required><span>⏳ <?= h(t('s7')) ?></span></label>
          <label><input type="radio" name="status" value="in_progress" checked><span>🔧 <?= h(t('s9')) ?></span></label>
          <label><input type="radio" name="status" value="completed"><span>✅ <?= h(t('s10')) ?></span></label>
        </div>
      </div>
      <div class="col-md-6">
        <div class="we-lbl"><?= h(t('s20')) ?></div>
        <div class="we-pri" role="radiogroup">
          <label><input type="radio" name="priority" value="urgent" required><span><i style="background:#DC2626"></i><?= h(t('s84')) ?></span></label>
          <label><input type="radio" name="priority" value="high"><span><i style="background:#EA580C"></i><?= h(t('s85')) ?></span></label>
          <label><input type="radio" name="priority" value="normal" checked><span><i style="background:#2563EB"></i><?= h(t('s86')) ?></span></label>
          <label><input type="radio" name="priority" value="low"><span><i style="background:#94A3B8"></i><?= h(t('s87')) ?></span></label>
        </div>
      </div>
      <div class="col-md-6">
        <label class="we-lbl" for="weDate">📅 <?= h(t('s13')) ?></label>
        <div class="we-date">
          <input type="date" name="work_date" id="weDate" class="form-control-custom" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d', strtotime('+1 day')) ?>" required>
          <button type="button" data-d="<?= date('Y-m-d') ?>">आज</button>
          <button type="button" data-d="<?= date('Y-m-d', strtotime('-1 day')) ?>">काल</button>
        </div>
      </div>
      <div class="col-md-6">
        <label class="we-lbl" for="weDept">🏢 <?= h(t('s1')) ?></label>
        <select name="department_id" id="weDept" class="form-select-custom" required>
          <?php foreach ($departments as $dept): ?>
            <option value="<?= $dept['id'] ?>" <?= (int)$dept['id'] === $weMyDept ? 'selected' : '' ?>><?= h($dept['name_local'] ?: $dept['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>

    <div class="we-sec">
      <div class="we-lbl">👥 <?= h(t('s219')) ?> <span class="we-cnt" id="weTeamCnt">1</span></div>
      <div class="we-team">
        <?php if ($weTeamSearch): ?>
        <div class="we-team-tools">
          <input type="search" id="weTeamQ" class="form-control-custom" placeholder="🔍 नाव शोधा…" autocomplete="off">
          <button type="button" id="weTeamAll" aria-pressed="false">सर्व विभाग</button>
        </div>
        <?php endif; ?>
        <div class="we-chips" id="weChips">
          <?php
          $weStaffSorted = $allStaff;
          usort($weStaffSorted, function ($a, $b) use ($user) { return ((int)($b['id'] === $user['id'])) <=> ((int)($a['id'] === $user['id'])); });
          foreach ($weStaffSorted as $staff):
            $nm = (string)$staff['name']; $ini = mb_strtoupper(mb_substr(trim($nm), 0, 1));
          ?>
            <label class="we-chip" data-dept="<?= (int)$staff['department_id'] ?>" data-n="<?= h(mb_strtolower($nm . ' ' . ($staff['designation'] ?? ''))) ?>" title="<?= h($nm . (!empty($staff['designation']) ? ' — ' . $staff['designation'] : '')) ?>">
              <input type="checkbox" name="employee_ids[]" value="<?= $staff['id'] ?>" <?= $staff['id'] === $user['id'] ? 'checked' : '' ?>>
              <span><b><?= h($ini) ?></b><em><?= h($nm) ?><?= $staff['id'] === $user['id'] ? ' (मी)' : '' ?></em><?php if (!empty($staff['designation'])): ?><small><?= h($staff['designation']) ?></small><?php endif; ?></span>
            </label>
          <?php endforeach; ?>
        </div>
        <div id="weTeamNone" style="display:none;font-size:12px;color:var(--text-dim);padding:6px 4px;">कोणी सापडलं नाही.</div>
      </div>
      <div style="font-size:10.5px;color:var(--text-dim);margin-top:4px;">टीमने एकत्र केलेलं काम असल्यास प्रत्येकाला निवडा.</div>
    </div>

    <div class="we-sec">
      <div class="we-lbl">📷 फोटो <span style="font-weight:400">(ऐच्छिक)</span></div>
      <div class="we-photos">
        <label class="we-ph"><input type="file" name="before_photo" accept="image/*"><span class="ic">📸</span><span class="t"><?= h(t('s227')) ?></span><span class="s">टॅप करा</span><button type="button" class="x" aria-label="काढा">✕</button></label>
        <label class="we-ph"><input type="file" name="after_photo" accept="image/*"><span class="ic">✨</span><span class="t"><?= h(t('s228')) ?></span><span class="s">टॅप करा</span><button type="button" class="x" aria-label="काढा">✕</button></label>
      </div>
    </div>

    <details class="we-more" id="weMore" <?= $weCfRequired ? 'open' : '' ?>>
      <summary>➕ अधिक तपशील <span class="hint" id="weMoreHint">विनंती करणारा · तारखा · खर्च · साहित्य<?= $customFields ? ' · ' . h(t('s229')) : '' ?></span><span class="chev">▾</span></summary>
      <div class="we-in">
        <div class="we-sub">🙋 <?= h(t('s218')) ?></div>
        <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;cursor:pointer;background:var(--canvas);padding:10px 12px;border-radius:9px;margin-bottom:8px;">
          <input type="checkbox" id="isDailyRoutineCheck" checked onclick="eowmsToggleRequesterPicker(!this.checked)">
          🔁 हे रोजचंच/नियमित काम आहे — कोणी विशिष्ट विनंती केलेली नाही
        </label>
        <div id="requesterPickerBox" style="display:none;">
          <div class="row g-2">
          <?php if ($showCampusPickerForRequester): ?>
            <div class="col-md-4"><select id="reqCampusSelect" class="form-select-custom" onchange="eowmsOnReqCampusChange(this.value)">
              <option value="">-- College/School/Trust --</option>
              <?php foreach ($campusesForEntry as $c): ?>
                <option value="<?= $c['id'] ?>"><?= $c['college_type'] === 'Trust HQ' ? '🏛️ ' : '🏫 ' ?><?= h($c['name']) ?></option>
              <?php endforeach; ?>
            </select></div>
            <div class="col-md-4"><select id="reqDeptSelect" class="form-select-custom" onchange="eowmsOnReqDeptChange(this.value)" disabled>
              <option value="">-- आधी College/School निवडा --</option>
            </select></div>
          <?php else: ?>
            <div class="col-md-4"><select id="reqDeptSelect" class="form-select-custom" onchange="eowmsOnReqDeptChange(this.value)">
              <option value="">-- विभाग निवडा --</option>
              <?php foreach ($departments as $dept): ?><option value="<?= $dept['id'] ?>"><?= h($dept['name_local'] ?: $dept['name']) ?></option><?php endforeach; ?>
            </select></div>
          <?php endif; ?>
            <div class="col-md-4"><select id="reqEmpSelect" class="form-select-custom" onchange="eowmsOnReqEmpChange(this)" disabled>
              <option value="">-- आधी विभाग निवडा --</option>
            </select></div>
            <div class="col-12"><input type="text" id="reqOtherText" class="form-control-custom" placeholder="किंवा — यादीत नसेल तर, नाव/पद टाईप करा (उदा. प्राचार्य कार्यालय)" oninput="document.getElementById('requestedByHidden').value = this.value;"></div>
          </div>
        </div>
        <input type="hidden" name="requested_by" id="requestedByHidden" value="">

        <div class="we-sub">🗓️ तारखा व खर्च</div>
        <div class="row g-2">
          <div class="col-6 col-md-3">
            <label class="form-label-custom"><?= h(t('s223')) ?></label>
            <input type="date" name="start_date" class="form-control-custom">
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label-custom"><?= h(t('s224')) ?></label>
            <input type="date" name="due_date" class="form-control-custom">
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label-custom"><?= h(t('s225')) ?></label>
            <input type="number" step="0.01" min="0" inputmode="decimal" name="estimated_cost" class="form-control-custom" placeholder="₹ 0">
          </div>
          <div class="col-6 col-md-3">
            <label class="form-label-custom"><?= h(t('s226')) ?></label>
            <input type="number" step="0.01" min="0" inputmode="decimal" name="actual_cost" class="form-control-custom" placeholder="₹ 0">
          </div>
        </div>

        <div class="we-sub">📦 वापरलेलं साहित्य (Store मधून)</div>
        <?php if (empty($storeItems)): ?>
          <div style="font-size:12px;color:var(--text-dim);background:var(--canvas);border-radius:8px;padding:10px 14px;">
            अजून Store Module मध्ये एकही वस्तू (Item) जोडलेली नाही — त्यामुळे इथे निवडण्यासाठी काहीच नाही.
            <?php if (hasPermission('manage_store') || hasRole('super_admin')): ?>
              आधी <a href="../store/items.php" target="_blank" style="color:var(--rail);font-weight:600;">Store → वस्तू व्यवस्थापन</a> मधून वस्तू जोडा.
            <?php endif; ?>
          </div>
        <?php else: ?>
        <div id="materialRows"></div>
        <button type="button" id="addMaterialRow" class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;font-size:12px;padding:6px 14px;">+ साहित्य जोडा</button>
        <template id="materialRowTemplate">
          <div class="d-flex gap-2 mb-2 material-row">
            <div style="flex:2;min-width:0;"><select name="material_item_id[]" class="form-select-custom" style="width:100%;">
              <option value="">-- वस्तू निवडा --</option>
              <?php $lastCat = false; foreach ($storeItems as $si): if ($si['category'] !== $lastCat) { if ($lastCat !== false) echo '</optgroup>'; echo '<optgroup label="' . h($si['category'] ?: 'इतर') . '">'; $lastCat = $si['category']; } ?><option value="<?= $si['id'] ?>"><?= h($si['item_name']) ?> (<?= h($si['unit']) ?>)</option><?php endforeach; if ($storeItems) echo '</optgroup>'; ?>
            </select></div>
            <input type="number" step="0.01" name="material_quantity[]" class="form-control-custom" placeholder="प्रमाण" style="flex:1;min-width:0;">
            <button type="button" class="btn btn-sm remove-material-row" style="border:1px solid var(--rose);color:var(--rose);border-radius:8px;padding:6px 12px;">×</button>
          </div>
        </template>
        <div style="font-size:10.5px;color:var(--text-dim);margin-top:4px;">💡 उदा. Bathroom Clean करताना Phenyl/Soap वापरलं असेल, तर इथे नोंदवा — वापर-अहवाल आपोआप तयार होईल.</div>
        <script>
        (function () {
          var container = document.getElementById('materialRows');
          var template = document.getElementById('materialRowTemplate');
          document.getElementById('addMaterialRow').addEventListener('click', function () {
            container.appendChild(template.content.cloneNode(true));
          });
          container.addEventListener('click', function (e) {
            if (e.target.classList.contains('remove-material-row')) { e.target.closest('.material-row').remove(); }
          });
        })();
        </script>
        <?php endif; ?>

        <?php if (!empty($customFields)): ?>
          <div class="we-sub"><?= h(t('s229')) ?></div>
          <div class="row g-2">
          <?php foreach ($customFields as $cf):
            $fieldName = 'custom_' . h($cf['field_key']);
            $req = $cf['is_required'] ? 'required' : '';
          ?>
            <div class="col-md-6">
              <label class="form-label-custom"><?= h($cf['field_label']) ?><?= $cf['is_required'] ? ' *' : '' ?></label>
              <?php if ($cf['field_type'] === 'textarea'): ?>
                <textarea name="<?= $fieldName ?>" class="form-control-custom" rows="2" <?= $req ?>></textarea>
              <?php elseif ($cf['field_type'] === 'select'): ?>
                <select name="<?= $fieldName ?>" class="form-select-custom" <?= $req ?>>
                  <option value=""><?= h(t('s36')) ?></option>
                  <?php foreach (explode(',', $cf['field_options'] ?? '') as $opt): $opt = trim($opt); if ($opt === '') continue; ?>
                    <option value="<?= h($opt) ?>"><?= h($opt) ?></option>
                  <?php endforeach; ?>
                </select>
              <?php elseif ($cf['field_type'] === 'checkbox'): ?>
                <select name="<?= $fieldName ?>" class="form-select-custom">
                  <option value="होय"><?= h(t('s40')) ?></option>
                  <option value="नाही"><?= h(t('s41')) ?></option>
                </select>
              <?php elseif ($cf['field_type'] === 'date'): ?>
                <input type="date" name="<?= $fieldName ?>" class="form-control-custom" <?= $req ?>>
              <?php elseif ($cf['field_type'] === 'number'): ?>
                <input type="number" name="<?= $fieldName ?>" class="form-control-custom" <?= $req ?>>
              <?php else: ?>
                <input type="text" name="<?= $fieldName ?>" class="form-control-custom" <?= $req ?>>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </details>

    <div class="we-bar">
      <span class="sum" id="weSum"></span>
      <a href="../index.php" class="btn" style="border:1px solid var(--line);border-radius:10px;font-size:13.5px;padding:10px 18px;"><?= h(t('s3')) ?></a>
      <button type="submit" class="btn btn-go">✓ <?= h(t('s230')) ?></button>
    </div>
  </form>
</div>

<script>
(function () {
  var f = document.getElementById('workEntryForm'); if (!f) return;
  var title = document.getElementById('weTitle');
  // अलीकडची कामं — टॅप केल्यावर शीर्षक भरा
  f.querySelectorAll('.we-recent button').forEach(function (b) {
    b.addEventListener('click', function () { title.value = b.getAttribute('data-t'); title.focus(); });
  });
  // आज / काल
  var dateIn = document.getElementById('weDate');
  var dBtns = f.querySelectorAll('.we-date button');
  function markDate() { dBtns.forEach(function (b) { b.classList.toggle('on', b.getAttribute('data-d') === dateIn.value); }); }
  dBtns.forEach(function (b) { b.addEventListener('click', function () { dateIn.value = b.getAttribute('data-d'); markDate(); }); });
  dateIn.addEventListener('change', markDate); markDate();
  // टीम chips — विभागानुसार, शोध, संख्या
  var chips = Array.prototype.slice.call(f.querySelectorAll('.we-chip'));
  var dept = document.getElementById('weDept');
  var q = document.getElementById('weTeamQ'); var allBtn = document.getElementById('weTeamAll');
  var showAll = false, cnt = document.getElementById('weTeamCnt'), none = document.getElementById('weTeamNone');
  function filterTeam() {
    var term = q ? q.value.trim().toLowerCase() : '';
    var dv = dept ? dept.value : '', shown = 0;
    var deptHas = chips.some(function (c) { return c.getAttribute('data-dept') === dv; });
    chips.forEach(function (c) {
      var cb = c.querySelector('input');
      var ok = term ? c.getAttribute('data-n').indexOf(term) !== -1
                    : (showAll || !q || !deptHas || cb.checked || c.getAttribute('data-dept') === dv);
      c.classList.toggle('hide', !ok); if (ok) shown++;
    });
    if (none) none.style.display = shown ? 'none' : 'block';
  }
  function countTeam() {
    var n = f.querySelectorAll('input[name="employee_ids[]"]:checked').length;
    cnt.textContent = n ? ('✓ ' + n) : '0'; cnt.style.color = n ? '' : '#DC2626';
  }
  if (q) q.addEventListener('input', filterTeam);
  if (allBtn) allBtn.addEventListener('click', function () { showAll = !showAll; allBtn.setAttribute('aria-pressed', showAll); allBtn.classList.toggle('on', showAll); allBtn.style.background = showAll ? 'var(--ink)' : ''; allBtn.style.color = showAll ? '#fff' : ''; filterTeam(); });
  if (dept) dept.addEventListener('change', filterTeam);
  f.addEventListener('change', function (e) { if (e.target.name === 'employee_ids[]') countTeam(); });
  filterTeam(); countTeam();
  // फोटो preview
  f.querySelectorAll('.we-ph').forEach(function (box) {
    var inp = box.querySelector('input[type=file]'), x = box.querySelector('.x');
    inp.addEventListener('change', function () {
      var old = box.querySelector('img'); if (old) old.remove();
      if (inp.files && inp.files[0]) {
        var img = document.createElement('img'); img.alt = '';
        img.src = URL.createObjectURL(inp.files[0]); box.insertBefore(img, x); box.classList.add('has');
      } else { box.classList.remove('has'); }
    });
    x.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); inp.value = ''; inp.dispatchEvent(new Event('change')); });
  });
  // बंद "अधिक तपशील" मधलं required field — आधी उघडा
  var more = document.getElementById('weMore');
  f.addEventListener('invalid', function (e) { if (more && more.contains(e.target)) more.open = true; }, true);
})();
</script>

<script>
function validateTeamSelection() {
  var checked = document.querySelectorAll('input[name="employee_ids[]"]:checked');
  if (checked.length === 0) {
    alert('कृपया किमान एक कर्मचारी निवडा — काम कोणी केलं ते.');
    return false;
  }
  return true;
}

function eowmsToggleRequesterPicker(show) {
  document.getElementById('requesterPickerBox').style.display = show ? 'block' : 'none';
  if (!show) { document.getElementById('requestedByHidden').value = ''; }
}

function eowmsOnReqCampusChange(campusId) {
  var deptSelect = document.getElementById('reqDeptSelect');
  var empSelect = document.getElementById('reqEmpSelect');
  empSelect.innerHTML = '<option value="">-- आधी विभाग निवडा --</option>';
  empSelect.disabled = true;
  if (!campusId) {
    deptSelect.innerHTML = '<option value="">-- आधी College/School निवडा --</option>';
    deptSelect.disabled = true;
    return;
  }
  deptSelect.disabled = true;
  deptSelect.innerHTML = '<option value="">लोड होत आहे...</option>';
  fetch('../ajax-cascade.php?action=departments_for_campus&campus_id=' + campusId)
    .then(function (r) { return r.json(); })
    .then(function (data) {
      var depts = data.departments || [];
      if (depts.length === 0) { deptSelect.innerHTML = '<option value="">-- विभाग नाही --</option>'; return; }
      deptSelect.disabled = false;
      deptSelect.innerHTML = '<option value="">-- विभाग निवडा --</option>' + depts.map(function (d) {
        return '<option value="' + d.id + '">' + d.name + '</option>';
      }).join('');
    });
}

function eowmsOnReqDeptChange(deptId) {
  var empSelect = document.getElementById('reqEmpSelect');
  if (!deptId) {
    empSelect.innerHTML = '<option value="">-- आधी विभाग निवडा --</option>';
    empSelect.disabled = true;
    return;
  }
  empSelect.disabled = true;
  empSelect.innerHTML = '<option value="">लोड होत आहे...</option>';
  fetch('../ajax-cascade.php?action=staff_for_department&department_id=' + deptId)
    .then(function (r) { return r.json(); })
    .then(function (data) {
      var staff = data.staff || [];
      empSelect.disabled = false;
      empSelect.innerHTML = '<option value="">-- व्यक्ती निवडा --</option>' + staff.map(function (s) {
        return '<option value="' + s.id + '" data-name="' + s.name.replace(/"/g,'&quot;') + '">' + s.name + (s.designation ? ' (' + s.designation + ')' : '') + '</option>';
      }).join('');
    });
}

function eowmsOnReqEmpChange(sel) {
  if (sel.value) {
    document.getElementById('requestedByHidden').value = sel.options[sel.selectedIndex].dataset.name || '';
    document.getElementById('reqOtherText').value = '';
  } else {
    document.getElementById('requestedByHidden').value = '';
  }
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
