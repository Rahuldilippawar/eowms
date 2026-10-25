<?php
$pageTitle = 'माझी कामे';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$status = $_GET['status'] ?? 'pending';

// Staff sees work where they are part of the team (solo or with others);
// HOD sees their whole department's work
$isHod = hasCategory('hod');
// HOD — स्वतःचा विभाग + Extra दिलेले विभाग (getVisibleDepartmentIds)
$hodDeptIds = $isHod ? (getVisibleDepartmentIds() ?: [0]) : [];
$sql = "SELECT wr.*, d.name_local AS dept_name,
          (SELECT GROUP_CONCAT(u2.name SEPARATOR ', ') FROM work_record_team wt2 JOIN users u2 ON u2.id = wt2.user_id WHERE wt2.work_record_id = wr.id) AS team_names
        FROM work_records wr
        JOIN departments d ON d.id = wr.department_id
        WHERE wr.organization_id = ? AND " . ($isHod ? "wr.department_id IN (" . implode(',', array_fill(0, count($hodDeptIds), '?')) . ")" : "EXISTS (SELECT 1 FROM work_record_team wt WHERE wt.work_record_id = wr.id AND wt.user_id = ?)");
$params = array_merge([activeOrgId()], $isHod ? $hodDeptIds : [$user['id']]);
if ($status !== 'all') { $sql .= " AND wr.status = ?"; $params[] = $status; }
$sql .= " ORDER BY wr.work_date DESC LIMIT 100";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll();

// Edit करण्याचा Access फक्त "स्वतःच्याच" नोंदींसाठी — HOD ला संपूर्ण विभाग
// दिसत असला तरी, तो/ती स्वतः त्या कामात सहभागी असेल तरच Edit करू शकेल.
$myEntryIds = [];
if (!empty($user['can_edit_daily_work']) && !empty($records)) {
    $recordIds = array_column($records, 'id');
    $ph = implode(',', array_fill(0, count($recordIds), '?'));
    $myEntryStmt = $pdo->prepare("SELECT work_record_id FROM work_record_team WHERE work_record_id IN ($ph) AND user_id = ?");
    $myEntryStmt->execute(array_merge($recordIds, [$user['id']]));
    $myEntryIds = $myEntryStmt->fetchAll(PDO::FETCH_COLUMN);
}

$statusLabels = ['pending' => 'प्रलंबित', 'in_progress' => 'सुरू आहे', 'completed' => 'पूर्ण झाले'];
$statusChip = ['pending' => 'pending', 'in_progress' => 'pending', 'completed' => 'done'];
?>

<div class="page-eyebrow"><?= h($user['name']) ?></div>
<div class="page-title"><?= h(t('s215')) ?></div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:18px;">
  <?= $isHod ? 'तुमच्या विभागातील सर्व कामे — पूर्ण करा किंवा विलंबाचे कारण नोंदवा' : 'तुम्ही नोंदवलेली कामे — पूर्ण करा किंवा विलंबाचे कारण नोंदवा' ?>
</p>

<?php if (!empty($_SESSION['flash'])): ?>
  <div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($_SESSION['flash']) ?></div>
  <?php unset($_SESSION['flash']); ?>
<?php endif; ?>

<div class="period-toggle mb-3">
  <a href="?status=pending" class="period-btn <?= $status === 'pending' ? 'active' : '' ?>"><?= h(t('s7')) ?></a>
  <a href="?status=in_progress" class="period-btn <?= $status === 'in_progress' ? 'active' : '' ?>"><?= h(t('s9')) ?></a>
  <a href="?status=completed" class="period-btn <?= $status === 'completed' ? 'active' : '' ?>"><?= h(t('s10')) ?></a>
  <a href="?status=all" class="period-btn <?= $status === 'all' ? 'active' : '' ?>"><?= h(t('s19')) ?></a>
</div>

<div class="row g-3">
  <?php if (empty($records)): ?>
    <div class="col-12"><div class="panel" style="padding:30px;text-align:center;color:var(--text-dim);"><?= h(t('s34')) ?></div></div>
  <?php endif; ?>
  <?php foreach ($records as $rec): ?>
    <div class="col-md-6">
      <div class="panel h-100">
        <div style="padding:18px 20px;">
          <div class="d-flex justify-content-between align-items-start mb-2">
            <div class="font-display" style="font-weight:600;font-size:14.5px;"><?= h($rec['title']) ?></div>
            <span class="status-chip <?= $statusChip[$rec['status']] ?>"><?= $statusLabels[$rec['status']] ?></span>
          </div>
          <div class="font-mono" style="font-size:11px;color:var(--text-dim);margin-bottom:8px;"><?= h($rec['work_code']) ?> · <?= date('d M Y', strtotime($rec['work_date'])) ?></div>
          <?php if ($rec['team_names']): ?><div style="font-size:12px;color:var(--text-dim);margin-bottom:6px;"><i class="fa-solid fa-users me-1"></i><?= h($rec['team_names']) ?></div><?php endif; ?>
          <?php if ($rec['description']): ?><p style="font-size:13px;color:var(--text-dim);"><?= h($rec['description']) ?></p><?php endif; ?>

          <?php if (!empty($rec['remarks'])): ?>
            <div style="background:var(--amber-soft);color:#8A5A14;border-radius:8px;padding:8px 12px;font-size:12.5px;margin-bottom:10px;">
              <i class="fa-solid fa-comment-dots me-1"></i><?= h($rec['remarks']) ?>
            </div>
          <?php endif; ?>

          <?php if (!empty($user['can_edit_daily_work']) && in_array($rec['id'], $myEntryIds, true)): ?>
            <a href="edit.php?id=<?= $rec['id'] ?>" class="btn btn-sm mb-2" style="border:1px solid var(--rail);color:var(--rail);border-radius:8px;font-size:12px;padding:5px 14px;display:inline-block;">
              <i class="fa-solid fa-pen me-1"></i>ही नोंद दुरुस्त करा
            </a>
          <?php endif; ?>

          <?php if ($rec['status'] !== 'completed'): ?>
            <form method="POST" action="update-status.php">
              <input type="hidden" name="id" value="<?= $rec['id'] ?>">
              <div class="row g-2">
                <div class="col-7">
                  <select name="status" class="form-select-custom">
                    <option value="pending" <?= $rec['status'] === 'pending' ? 'selected' : '' ?>><?= h(t('s7')) ?></option>
                    <option value="in_progress" <?= $rec['status'] === 'in_progress' ? 'selected' : '' ?>><?= h(t('s9')) ?></option>
                    <option value="completed"><?= h(t('s10')) ?></option>
                  </select>
                </div>
                <div class="col-5">
                  <button type="submit" class="btn btn-sm w-100" style="background:var(--rail);color:#fff;border-radius:8px;font-size:12.5px;padding:9px;"><?= h(t('s39')) ?></button>
                </div>
                <div class="col-12">
                  <textarea name="remarks" class="form-control-custom" rows="2" placeholder="विलंबाचे कारण / टिप्पणी (ऐच्छिक — काम अजून पूर्ण नसेल तर इथे लिहा)"><?= h($rec['remarks']) ?></textarea>
                </div>
              </div>
            </form>
          <?php else: ?>
            <div style="font-size:12px;color:var(--rail);font-weight:600;">
              <i class="fa-solid fa-circle-check me-1"></i><?= $rec['completion_date'] ? date('d M Y', strtotime($rec['completion_date'])) : '' ?> रोजी पूर्ण झाले
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
