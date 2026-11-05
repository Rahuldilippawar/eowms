<?php
// 🚗🗑️ नूतनीकरणातून सर्व वाहनांच्या नोंदी हटवा (फक्त Super Admin)
//  - वाहनांशी जोडलेल्या नूतनीकरण नोंदी (vehicle_id) + त्यांचा Renew इतिहास, Reminder log, कागदपत्रं (Files सह) कायमच्या हटतात
//  - हवं तर हाताने बनवलेल्या वाहन-प्रकारच्या नोंदी (RTO/Transport, vehicle_…, वाहन…) पण
//  - हवं तर वाहनांच्या नोंदीतल्या Validity तारखा रिकाम्या — नाहीतर वाहन बदलताच/Import करताच नोंदी परत तयार होतात
//  - वाहनं (Vehicles module), फेऱ्या, इंधन, देखभाल — यांना हात लावत नाही
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
$orgId = activeOrgId();
const CLRV_CONFIRM = 'हटवा';
const CLRV_VALIDITY = ['permit_validity', 'puc_validity', 'insurance_validity', 'fitness_validity', 'road_tax_validity', 'green_tax_validity', 'mv_tax_validity'];
$manualWhere = "vehicle_id IS NULL AND (regulatory_body = 'RTO/Transport' OR item_type LIKE 'vehicle%' OR item_type LIKE 'वाहन%')";

$count = function ($where) use ($pdo, $orgId) {
    $st = $pdo->prepare("SELECT COUNT(*) FROM renewal_items WHERE organization_id = ? AND $where");
    $st->execute([$orgId]);
    return (int)$st->fetchColumn();
};
$linked = $count('vehicle_id IS NOT NULL');
$manualSt = $pdo->prepare("SELECT id, item_name, item_type, expiry_date, status FROM renewal_items WHERE organization_id = ? AND $manualWhere ORDER BY item_name");
$manualSt->execute([$orgId]);
$manual = $manualSt->fetchAll();
$hist = $pdo->prepare("SELECT COUNT(*) FROM renewal_history h JOIN renewal_items r ON r.id = h.renewal_item_id WHERE r.organization_id = ? AND r.vehicle_id IS NOT NULL");
$hist->execute([$orgId]);
$histCount = (int)$hist->fetchColumn();
$vehSt = $pdo->prepare("SELECT COUNT(*) FROM vehicles WHERE organization_id = ? AND (" . implode(' IS NOT NULL OR ', CLRV_VALIDITY) . " IS NOT NULL)");
$vehSt->execute([$orgId]);
$vehWithDates = (int)$vehSt->fetchColumn();
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (trim($_POST['confirm_text'] ?? '') !== CLRV_CONFIRM) {
        $error = 'खात्रीसाठी खालच्या चौकटीत असं टाइप करा: <!--i18n:off-->' . CLRV_CONFIRM . '<!--i18n:on-->';
    } else {
        $withManual = !empty($_POST['with_manual']);
        $clearDates = !empty($_POST['clear_dates']);
        $where = $withManual ? "(vehicle_id IS NOT NULL OR ($manualWhere))" : 'vehicle_id IS NOT NULL';
        // कागदपत्रांच्या Files — DB मधून हटण्यापूर्वी यादी
        $fs = $pdo->prepare("SELECT a.file_path FROM renewal_attachments a JOIN renewal_items r ON r.id = a.renewal_item_id WHERE r.organization_id = ? AND $where");
        $fs->execute([$orgId]);
        $files = $fs->fetchAll(PDO::FETCH_COLUMN);
        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare("DELETE FROM renewal_items WHERE organization_id = ? AND $where"); // इतिहास/Reminder/कागदपत्रं — ON DELETE CASCADE
            $del->execute([$orgId]);
            $deleted = $del->rowCount();
            $cleared = 0;
            if ($clearDates) {
                $up = $pdo->prepare("UPDATE vehicles SET " . implode(' = NULL, ', CLRV_VALIDITY) . " = NULL WHERE organization_id = ?");
                $up->execute([$orgId]);
                $cleared = $up->rowCount();
            }
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        $removedFiles = 0;
        foreach ($files as $f) {
            $p = __DIR__ . '/../' . $f;
            if (str_starts_with((string)$f, 'assets/uploads/renewals/') && is_file($p) && @unlink($p)) { $removedFiles++; }
        }
        logActivity('नूतनीकरणातून सर्व वाहनांच्या नोंदी हटवल्या', 'renewal_item', null,
            "$deleted नोंदी" . ($withManual ? ' (हाताने बनवलेल्या वाहन-नोंदींसह)' : '') . ($clearDates ? ", $cleared वाहनांच्या Validity तारखा रिकाम्या" : '') . ", $removedFiles Files");
        $_SESSION['flash'] = "🚗 नूतनीकरणातून वाहनांच्या $deleted नोंदी हटवल्या" . ($clearDates ? " · $cleared वाहनांच्या Validity तारखा रिकाम्या केल्या" : '') . ($removedFiles ? " · $removedFiles कागदपत्रं हटवली" : '') . '.';
        header('Location: index.php');
        exit;
    }
}

$pageTitle = 'नूतनीकरणातून वाहनांच्या नोंदी हटवा';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
$total = $linked + count($manual);
?>
<div class="page-eyebrow"><?= h(activeOrgName()) ?></div>
<div class="d-flex justify-content-between align-items-start mb-1">
  <div class="page-title mb-0">🚗🗑️ नूतनीकरणातून सर्व वाहनांच्या नोंदी हटवा</div>
  <a href="index.php" style="font-size:12.5px;color:var(--rail);font-weight:600;text-decoration:none;align-self:center;"><i class="fa-solid fa-arrow-left me-1"></i>नूतनीकरण यादी</a>
</div>
<p style="font-size:12.5px;color:var(--text-dim);margin-bottom:16px;">वाहनं (Vehicles module), फेऱ्या, इंधन आणि देखभाल यांना हात लागत नाही — फक्त नूतनीकरण / Renew मधल्या वाहनांच्या नोंदी हटतात.</p>
<?php if ($error): ?><div class="alert alert-danger" style="border-radius:10px;font-size:13px;"><?= $error /* स्थिर मजकूर — User input नाही */ ?></div><?php endif; ?>

<div class="row g-3 mb-3">
  <div class="col-md-4"><div class="panel" style="padding:14px 18px;"><div style="font-size:12px;color:var(--text-dim);font-weight:600;">वाहनांशी जोडलेल्या नोंदी</div><div style="font-size:26px;font-weight:800;"><?= $linked ?></div><div style="font-size:11.5px;color:var(--text-dim);">परमिट, PUC, विमा, फिटनेस, टॅक्स…</div></div></div>
  <div class="col-md-4"><div class="panel" style="padding:14px 18px;"><div style="font-size:12px;color:var(--text-dim);font-weight:600;">त्यांचा Renew इतिहास</div><div style="font-size:26px;font-weight:800;"><?= $histCount ?></div><div style="font-size:11.5px;color:var(--text-dim);">नोंदींसोबत हटेल</div></div></div>
  <div class="col-md-4"><div class="panel" style="padding:14px 18px;"><div style="font-size:12px;color:var(--text-dim);font-weight:600;">Validity तारखा असलेली वाहनं</div><div style="font-size:26px;font-weight:800;"><?= $vehWithDates ?></div><div style="font-size:11.5px;color:var(--text-dim);">Vehicles module मध्ये</div></div></div>
</div>

<?php if ($total === 0 && !$vehWithDates): ?>
  <div class="panel" style="padding:36px;text-align:center;color:var(--text-dim);"><div style="font-size:34px;">✅</div>नूतनीकरणात वाहनांची एकही नोंद नाही.</div>
<?php else: ?>
<form method="POST" class="panel" style="padding:18px 22px;border:2px solid var(--rose);" onsubmit="return window.confirm('वाहनांच्या नूतनीकरण नोंदी कायमच्या हटवायच्या? हे परत आणता येणार नाही.');">
  <div style="font-weight:700;font-size:14.5px;color:var(--rose);margin-bottom:10px;">⚠️ हे कायमचं हटवणं आहे — आधी <a href="../backup/index.php" style="color:var(--rail);">Backup</a> घ्या.</div>
  <label class="d-flex gap-2 align-items-start mb-2" style="font-size:13px;"><input type="checkbox" checked disabled style="width:16px;height:16px;margin-top:2px;"> <span>वाहनांशी जोडलेल्या <b><?= $linked ?></b> नोंदी, त्यांचा Renew इतिहास, Reminder आणि कागदपत्रं (Files सह)</span></label>
  <?php if ($manual): ?>
  <label class="d-flex gap-2 align-items-start mb-1" style="font-size:13px;"><input type="checkbox" name="with_manual" value="1" checked style="width:16px;height:16px;margin-top:2px;accent-color:var(--rose);"> <span>हाताने बनवलेल्या वाहन-प्रकारच्या <b><?= count($manual) ?></b> नोंदी पण (RTO/Transport, vehicle_…):</span></label>
  <ul style="font-size:12.5px;color:var(--text-dim);margin:0 0 10px 26px;"><?php foreach ($manual as $m): ?><li><?= h($m['item_name']) ?> <span style="opacity:.7;">(<?= h($m['item_type']) ?><?= $m['expiry_date'] ? ' · ' . h(date('d-m-Y', strtotime($m['expiry_date']))) : '' ?>)</span></li><?php endforeach; ?></ul>
  <?php endif; ?>
  <label class="d-flex gap-2 align-items-start mb-3" style="font-size:13px;"><input type="checkbox" name="clear_dates" value="1" checked style="width:16px;height:16px;margin-top:2px;accent-color:var(--rose);"> <span>वाहनांच्या नोंदीतल्या Validity तारखा (परमिट, PUC, विमा, फिटनेस, टॅक्स) पण रिकाम्या करा — <b>नाहीतर एखादं वाहन बदलताच किंवा Excel Import करताच नूतनीकरण नोंदी परत तयार होतील.</b></span></label>
  <label class="form-label-custom">खात्रीसाठी <!--i18n:off-->"<?= CLRV_CONFIRM ?>"<!--i18n:on--> असं टाइप करा</label>
  <div class="d-flex gap-2 flex-wrap">
    <input type="text" name="confirm_text" class="form-control-custom" style="max-width:220px;" autocomplete="off" required>
    <button class="btn" style="background:var(--rose);color:#fff;border-radius:9px;font-weight:700;padding:9px 20px;"><i class="fa-solid fa-trash me-1"></i>सर्व वाहनांच्या नोंदी हटवा</button>
    <a href="index.php" class="btn" style="border:1px solid var(--line);border-radius:9px;">रद्द करा</a>
  </div>
</form>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
