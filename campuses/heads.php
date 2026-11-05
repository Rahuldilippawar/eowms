<?php
// 🏛️ Campus प्रमुख नेमणूक (फक्त Super Admin) — सर्व Campus चे केंद्रीय प्रमुख + प्रत्येक Campus चे प्रमुख (Principal / Manager)
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
require_once __DIR__ . '/../includes/campus-heads.php';
$me = currentUser();
$orgId = activeOrgId();
if (!campusHeadsReady($pdo)) {
    $pageTitle = 'Campus प्रमुख'; $rootPath = '../';
    require_once __DIR__ . '/../includes/header.php';
    echo '<div class="panel" style="padding:30px;text-align:center;">🛠️ आधी <b>System Update → Database Update</b> चालवा (campus_heads table).</div>';
    require_once __DIR__ . '/../includes/footer.php';
    exit;
}
$back = function ($msg) { $_SESSION['flash'] = $msg; header('Location: heads.php'); exit; };

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    if ($a === 'assign') {
        $uid = (int)($_POST['user_id'] ?? 0);
        $cid = ($_POST['campus_id'] ?? '') === 'all' ? null : (int)($_POST['campus_id'] ?? 0);
        $title = trim(mb_substr((string)($_POST['title'] ?? ''), 0, 100)) ?: null;
        $u = $pdo->prepare("SELECT id, name FROM users WHERE id = ? AND organization_id = ? AND is_active = 1");
        $u->execute([$uid, $orgId]);
        $target = $u->fetch();
        $okCampus = $cid === null;
        if (!$okCampus) { $c = $pdo->prepare("SELECT id FROM campuses WHERE id = ? AND organization_id = ?"); $c->execute([$cid, $orgId]); $okCampus = (bool)$c->fetch(); }
        if (!$target || !$okCampus) { $back('कर्मचारी किंवा Campus सापडला नाही.'); }
        $exists = $pdo->prepare("SELECT id FROM campus_heads WHERE organization_id = ? AND user_id = ? AND " . ($cid === null ? 'campus_id IS NULL' : 'campus_id = ?'));
        $exists->execute($cid === null ? [$orgId, $uid] : [$orgId, $uid, $cid]);
        if ($row = $exists->fetch()) {
            $pdo->prepare("UPDATE campus_heads SET title = ? WHERE id = ?")->execute([$title, $row['id']]);
        } else {
            $pdo->prepare("INSERT INTO campus_heads (organization_id, campus_id, user_id, title, assigned_by) VALUES (?, ?, ?, ?, ?)")->execute([$orgId, $cid, $uid, $title, $me['id']]);
        }
        logActivity($cid === null ? 'केंद्रीय Campus प्रमुख नेमला' : 'Campus प्रमुख नेमला', 'campus', $cid, $target['name'] . ($title ? " ($title)" : ''));
        $back('✅ ' . $target['name'] . ' — ' . ($cid === null ? 'सर्व Campus चे केंद्रीय प्रमुख' : 'Campus प्रमुख') . ' म्हणून नेमले. पुढच्या पानापासून त्यांना Campus Dashboard दिसेल.');
    }
    if ($a === 'unassign') {
        $hid = (int)($_POST['head_id'] ?? 0);
        $r = $pdo->prepare("SELECT h.*, u.name FROM campus_heads h JOIN users u ON u.id = h.user_id WHERE h.id = ? AND h.organization_id = ?");
        $r->execute([$hid, $orgId]);
        if ($row = $r->fetch()) {
            $pdo->prepare("DELETE FROM campus_heads WHERE id = ?")->execute([$hid]);
            logActivity('Campus प्रमुख पदावरून काढले', 'campus', $row['campus_id'], $row['name']);
            $back($row['name'] . ' यांचा Campus प्रमुख Access काढला.');
        }
        $back('नोंद सापडली नाही.');
    }
}

$staff = $pdo->prepare("SELECT u.id, u.name, u.designation, c.name AS campus_name FROM users u LEFT JOIN campuses c ON c.id = u.campus_id
    WHERE u.organization_id = ? AND u.is_active = 1 AND u.role <> 'super_admin' ORDER BY u.name");
$staff->execute([$orgId]);
$staff = $staff->fetchAll();
$campuses = $pdo->prepare("SELECT c.*, (SELECT COUNT(*) FROM departments d WHERE d.campus_id = c.id AND d.is_active = 1) AS depts,
    (SELECT COUNT(*) FROM users u WHERE u.campus_id = c.id AND u.is_active = 1) AS staff_n
    FROM campuses c WHERE c.organization_id = ? AND c.is_active = 1 ORDER BY (c.college_type = 'Trust HQ') DESC, c.name");
$campuses->execute([$orgId]);
$campuses = $campuses->fetchAll();
$heads = $pdo->prepare("SELECT h.*, u.name, u.designation, u.email FROM campus_heads h JOIN users u ON u.id = h.user_id WHERE h.organization_id = ? ORDER BY u.name");
$heads->execute([$orgId]);
$byCampus = [];
foreach ($heads->fetchAll() as $hd) { $byCampus[$hd['campus_id'] === null ? 'all' : (int)$hd['campus_id']][] = $hd; }
$flash = $_SESSION['flash'] ?? null; unset($_SESSION['flash']);

$pageTitle = 'Campus प्रमुख';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';

$assignForm = function ($campusKey, $placeholder) use ($staff) {
    ob_start(); ?>
  <form method="POST" class="d-flex gap-2 flex-wrap align-items-center mt-2">
    <input type="hidden" name="action" value="assign"><input type="hidden" name="campus_id" value="<?= h($campusKey) ?>">
    <select name="user_id" class="form-select-custom" style="max-width:260px;" required>
      <option value="">— कर्मचारी निवडा —</option>
      <?php foreach ($staff as $s): ?><option value="<?= (int)$s['id'] ?>"><?= h($s['name'] . ($s['designation'] ? ' — ' . $s['designation'] : '') . ($s['campus_name'] ? ' · ' . $s['campus_name'] : '')) ?></option><?php endforeach; ?>
    </select>
    <input type="text" name="title" class="form-control-custom" style="max-width:190px;" placeholder="<?= h($placeholder) ?>" maxlength="100">
    <button class="btn btn-sm" style="background:var(--rail);color:#fff;border-radius:8px;font-size:12.5px;padding:7px 14px;font-weight:600;"><i class="fa-solid fa-user-plus me-1"></i>नेमा</button>
  </form>
<?php return ob_get_clean(); };
$headChips = function ($list) {
    if (!$list) { return '<div style="font-size:12.5px;color:var(--amber);font-weight:600;">⚠️ अजून कोणी नेमलेलं नाही</div>'; }
    $o = '<div class="d-flex flex-wrap gap-2">';
    foreach ($list as $hd) {
        $o .= '<span style="display:inline-flex;gap:8px;align-items:center;background:var(--canvas);border:1px solid var(--line);border-radius:99px;padding:5px 6px 5px 12px;font-size:12.5px;">'
            . '<b>' . h($hd['name']) . '</b>' . ($hd['title'] ? '<span style="color:var(--rail);font-weight:600;">' . h($hd['title']) . '</span>' : '')
            . '<form method="POST" style="display:inline;margin:0;" onsubmit="return window.confirm(\'' . h($hd['name']) . ' यांचा Campus प्रमुख Access काढायचा?\');"><input type="hidden" name="action" value="unassign"><input type="hidden" name="head_id" value="' . (int)$hd['id'] . '">'
            . '<button title="काढा" style="border:none;background:var(--rose-soft,#FDECEC);color:var(--rose);border-radius:99px;width:22px;height:22px;font-size:12px;line-height:1;cursor:pointer;">×</button></form></span>';
    }
    return $o . '</div>';
};
?>
<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1 flex-wrap gap-2">
  <div class="page-title mb-0">🏛️ Campus प्रमुख — Principal / Manager</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>कॅम्पस व्यवस्थापन</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:14px;">ज्याला Campus प्रमुख नेमाल त्याला Login केल्यावर <b>त्याच Campus चा Dashboard</b> दिसतो — त्या Campus चे सगळे विभाग, कर्मचारी, कामं, Tasks, नूतनीकरण आणि विनंत्या. केंद्रीय प्रमुखाला सर्व Campus.</p>
<?php if ($flash): ?><div class="alert alert-success" style="border-radius:10px;font-size:13px;"><?= h($flash) ?></div><?php endif; ?>

<div class="panel mb-3" style="padding:16px 20px;border:2px solid var(--rail);">
  <div class="d-flex justify-content-between flex-wrap gap-2 align-items-start">
    <div><div style="font-weight:800;font-size:15.5px;">🌐 सर्व Campus — केंद्रीय प्रमुख (Centralized Head)</div>
    <div style="font-size:12px;color:var(--text-dim);">संपूर्ण संस्थेचा Dashboard + प्रत्येक Campus चा वेगळा Dashboard</div></div>
  </div>
  <div class="mt-2"><?= $headChips($byCampus['all'] ?? []) ?></div>
  <?= $assignForm('all', 'पद (उदा. Director / CEO)') ?>
</div>

<div class="row g-3">
<?php foreach ($campuses as $c): $list = $byCampus[(int)$c['id']] ?? []; ?>
  <div class="col-lg-6">
    <div class="panel h-100" style="padding:16px 20px;">
      <div class="d-flex justify-content-between gap-2 flex-wrap align-items-start">
        <div>
          <div style="font-weight:800;font-size:15px;"><?= h($c['name']) ?></div>
          <div style="font-size:12px;color:var(--text-dim);"><?= h(($c['college_type'] ?: 'Campus') . ($c['city'] ? ' · ' . $c['city'] : '')) ?> · <?= (int)$c['depts'] ?> विभाग · <?= (int)$c['staff_n'] ?> कर्मचारी<?= $c['principal_name'] ? ' · प्राचार्य (नोंद): ' . h($c['principal_name']) : '' ?></div>
        </div>
        <a href="dashboard.php?id=<?= (int)$c['id'] ?>" class="btn btn-sm" style="border:1px solid var(--line);border-radius:8px;font-size:12px;"><i class="fa-solid fa-gauge-high me-1"></i>Dashboard</a>
      </div>
      <div class="mt-2"><?= $headChips($list) ?></div>
      <?= $assignForm((string)$c['id'], 'पद (उदा. Principal / Manager)') ?>
    </div>
  </div>
<?php endforeach; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
