<?php
// 🏪 पुरवठादार यादी (Supplier Master) — सगळे पुरवठादार, संपर्क, GSTIN, प्रकार; किती तक्त्यांत, किती वेळा L1 / निवड; नवीन / बदल / सक्रिय-निष्क्रिय
require_once __DIR__ . '/../../includes/auth.php';
requireLogin('../../');
require_once __DIR__ . '/../../includes/purchase-compare.php';
require_once __DIR__ . '/../../includes/smart-insights.php';
if (!purchaseCmpCanView()) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">पुरवठादार यादी पाहण्याची परवानगी नाही.</div>'); }
if (!purchaseSuppliersReady($pdo)) { header('Location: index.php'); exit; }
$orgId = activeOrgId();
$user = currentUser();
$canEdit = purchaseCmpCanEdit();

if ($canEdit && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['action'] ?? '');
    $sid = (int)($_POST['id'] ?? 0);
    $t = fn($k, $n) => mb_substr(trim((string)($_POST[$k] ?? '')), 0, $n);
    if ($act === 'save_supplier') {
        $name = trim(preg_replace('/\s+/u', ' ', $t('name', 200)));
        $key = purchaseSupplierKey($name);
        if ($key === '') { $_SESSION['flash'] = '⚠️ पुरवठादाराचं नाव लिहा.'; }
        else {
            $st = $pdo->prepare("SELECT id FROM purchase_suppliers WHERE organization_id = ? AND name_key = ? AND id <> ?"); $st->execute([$orgId, $key, $sid]);
            if ($st->fetchColumn()) { $_SESSION['flash'] = '⚠️ "' . $name . '" हे नाव यादीत आधीच आहे.'; }
            else {
                $vals = [$name, $key, $t('category', 100) ?: null, $t('contact_person', 150) ?: null, $t('phone', 40) ?: null, $t('email', 150) ?: null, strtoupper($t('gstin', 20)) ?: null, $t('address', 500) ?: null, $t('notes', 500) ?: null];
                if ($sid) {
                    $old = $pdo->prepare("SELECT name FROM purchase_suppliers WHERE id = ? AND organization_id = ?"); $old->execute([$sid, $orgId]); $oldName = $old->fetchColumn();
                    $pdo->prepare("UPDATE purchase_suppliers SET name=?, name_key=?, category=?, contact_person=?, phone=?, email=?, gstin=?, address=?, notes=?, updated_at=NOW() WHERE id=? AND organization_id=?")->execute(array_merge($vals, [$sid, $orgId]));
                    // नाव बदललं तर तक्त्यांमध्येही तेच नाव
                    if ($oldName !== false && $oldName !== $name) {
                        $pdo->prepare("UPDATE purchase_comparison_vendors v JOIN purchase_comparisons c ON c.id = v.comparison_id SET v.name = ? WHERE c.organization_id = ? AND v.name = ?")->execute([$name, $orgId, $oldName]);
                    }
                    $_SESSION['flash'] = '✓ पुरवठादार माहिती जतन झाली.';
                } else {
                    $pdo->prepare("INSERT INTO purchase_suppliers (name, name_key, category, contact_person, phone, email, gstin, address, notes, organization_id, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())")
                        ->execute(array_merge($vals, [$orgId, $user['id']]));
                    $_SESSION['flash'] = '✓ नवीन पुरवठादार जोडला.';
                }
                logActivity('पुरवठादार यादी — जतन', 'purchase_supplier', $sid, $name);
            }
        }
    } elseif ($act === 'set_active') {
        $pdo->prepare("UPDATE purchase_suppliers SET is_active = ?, updated_at = NOW() WHERE id = ? AND organization_id = ?")->execute([(int)!empty($_POST['on']), $sid, $orgId]);
        $_SESSION['flash'] = !empty($_POST['on']) ? '✓ पुरवठादार सक्रिय.' : '✓ पुरवठादार निष्क्रिय — सुचवणीत दिसणार नाही.';
    } elseif ($act === 'sync_names') {
        $n = count(purchaseSuppliersSync($pdo, $orgId, purchaseSupplierNames($pdo, $orgId), (int)$user['id']));
        $_SESSION['flash'] = '✓ तक्त्यांतील सगळी नावं यादीत तपासली (' . $n . ').';
    }
    header('Location: suppliers.php' . (isset($_GET['q']) ? '?q=' . urlencode((string)$_GET['q']) : '')); exit;
}

$q = trim((string)($_GET['q'] ?? ''));
$cat = trim((string)($_GET['cat'] ?? ''));
$show = ($_GET['show'] ?? '') === 'all' ? 'all' : 'active';
$w = "WHERE organization_id = ?"; $p = [$orgId];
if ($show === 'active') $w .= " AND is_active = 1";
if ($q !== '') { $w .= " AND (name LIKE ? OR phone LIKE ? OR gstin LIKE ? OR contact_person LIKE ? OR category LIKE ?)"; $lk = '%' . $q . '%'; array_push($p, $lk, $lk, $lk, $lk, $lk); }
if ($cat !== '') { $w .= " AND category = ?"; $p[] = $cat; }
$st = $pdo->prepare("SELECT * FROM purchase_suppliers $w ORDER BY name"); $st->execute($p); $rows = $st->fetchAll();
$st = $pdo->prepare("SELECT DISTINCT category FROM purchase_suppliers WHERE organization_id = ? AND category IS NOT NULL AND category <> '' ORDER BY category"); $st->execute([$orgId]); $cats = $st->fetchAll(PDO::FETCH_COLUMN);
$stats = purchaseCmpReady($pdo) ? purchaseSupplierStats($pdo, $orgId) : [];
$tot = $pdo->prepare("SELECT COUNT(*), SUM(is_active) FROM purchase_suppliers WHERE organization_id = ?"); $tot->execute([$orgId]); [$nAll, $nAct] = $tot->fetch(PDO::FETCH_NUM);
$edit = null;
if (isset($_GET['edit'])) { $st = $pdo->prepare("SELECT * FROM purchase_suppliers WHERE id = ? AND organization_id = ?"); $st->execute([(int)$_GET['edit'], $orgId]); $edit = $st->fetch() ?: null; }
$topWin = $stats; uasort($topWin, fn($a, $b) => [$b['won'], $b['l1']] <=> [$a['won'], $a['l1']]);

$pageTitle = 'पुरवठादार यादी';
$rootPath = '../../';
require_once __DIR__ . '/../../includes/header.php';
?>
<style>
  .sp-f{display:flex;gap:6px;flex-wrap:wrap;margin:8px 0 12px;} .sp-f input,.sp-f select{border:1px solid var(--line);border-radius:10px;padding:7px 10px;font-size:13px;background:var(--surface);color:var(--text);} .sp-f input{flex:1;min-width:180px;}
  .sp-row{display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center;border:1px solid var(--line);border-radius:13px;background:var(--surface);padding:10px 14px;margin-bottom:7px;}
  .sp-row.off{opacity:.55;}
  .sp-n{font-weight:700;font-size:14px;} .sp-m{font-size:12px;color:var(--text-dim);margin-top:2px;}
  .sp-b{display:flex;gap:5px;flex-wrap:wrap;margin-top:5px;} .sp-b span{font-size:11px;border-radius:7px;padding:2px 8px;background:var(--canvas);} .sp-b .g{background:#DCFCE7;color:#166534;font-weight:700;} .sp-b .b{background:#DBEAFE;color:#1E40AF;font-weight:700;}
  .sp-a{display:flex;gap:6px;} .sp-a a,.sp-a button{border:1px solid var(--line);background:var(--surface);border-radius:8px;padding:5px 9px;font-size:12px;color:var(--text);text-decoration:none;}
</style>
<div class="page-eyebrow">🛒 खरेदी विभाग · 📊 तुलनात्मक तक्ते</div>
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
  <div class="page-title mb-0">🏪 पुरवठादार यादी</div>
  <div class="d-flex gap-2 flex-wrap"><a href="index.php" class="btn btn-sm" style="border:1px solid var(--line);border-radius:9px;">← तक्ते</a>
    <?php if ($canEdit): ?><form method="POST" class="m-0"><input type="hidden" name="action" value="sync_names"><button type="submit" class="btn btn-sm" style="border:1px solid var(--line);border-radius:9px;" title="तक्त्यांमधील नवीन नावं यादीत जोडा">↻ तक्त्यांतील नावं जोडा</button></form><?php endif; ?></div>
</div>
<div style="font-size:12.5px;color:var(--text-dim);margin:4px 0 8px;">इथली नावं तुलनात्मक तक्ता व "खरेदी पूर्ण" करताना <b>टाईप करताच आपोआप सुचतात</b>. नवीन तक्त्यात नवीन पुरवठादार लिहिला की तो आपोआप यादीत जोडला जातो.</div>
<?php
smartTiles([
  ['label' => '🏪 पुरवठादार', 'value' => (int)$nAct, 'sub' => ((int)$nAll - (int)$nAct) ? ((int)$nAll - (int)$nAct) . ' निष्क्रिय' : 'सगळे सक्रिय'],
  ['label' => '🏆 सर्वाधिक निवड', 'value' => $topWin ? mb_strimwidth((string)(current(array_filter($rows, fn($r) => purchaseSupplierKey($r['name']) === array_key_first($topWin)))['name'] ?? array_key_first($topWin)), 0, 22, '…') : '—', 'sub' => $topWin ? reset($topWin)['won'] . ' मंजूर · ' . reset($topWin)['l1'] . ' वेळा L1' : ''],
  ['label' => '📋 संपर्क भरलेले', 'value' => count(array_filter($rows, fn($r) => $r['phone'])) . ' / ' . count($rows), 'sub' => 'फोन नंबर असलेले'],
]);
?>
<div class="row g-3">
  <div class="col-lg-8">
    <form class="sp-f" method="get">
      <input type="search" name="q" value="<?= h($q) ?>" placeholder="नाव, फोन, GSTIN, प्रकार शोधा…">
      <?php if ($cats): ?><select name="cat" onchange="this.form.submit()"><option value="">सर्व प्रकार</option><?php foreach ($cats as $c): ?><option <?= $cat === $c ? 'selected' : '' ?>><?= h($c) ?></option><?php endforeach; ?></select><?php endif; ?>
      <select name="show" onchange="this.form.submit()"><option value="active">सक्रिय</option><option value="all" <?= $show === 'all' ? 'selected' : '' ?>>सगळे (निष्क्रिय सुद्धा)</option></select>
    </form>
    <?php if (!$rows): ?><div class="panel" style="padding:24px;text-align:center;color:var(--text-dim);">पुरवठादार सापडला नाही.</div><?php endif; ?>
    <?php foreach ($rows as $r): $s = $stats[purchaseSupplierKey($r['name'])] ?? null; ?>
      <div class="sp-row <?= $r['is_active'] ? '' : 'off' ?>">
        <div>
          <div class="sp-n"><?= h($r['name']) ?><?= $r['category'] ? ' <span style="font-size:11px;font-weight:600;color:#7C3AED;">· ' . h($r['category']) . '</span>' : '' ?></div>
          <div class="sp-m"><?= implode(' · ', array_filter([$r['contact_person'] ? '👤 ' . h($r['contact_person']) : '', $r['phone'] ? '📞 <a href="tel:' . h(preg_replace('/[^\d+]/', '', $r['phone'])) . '">' . h($r['phone']) . '</a>' : '', $r['gstin'] ? 'GSTIN ' . h($r['gstin']) : '', $r['address'] ? '📍 ' . h(mb_strimwidth($r['address'], 0, 60, '…')) : ''])) ?: '<span style="opacity:.7;">संपर्क माहिती नाही</span>' ?></div>
          <?php if ($s): ?><div class="sp-b"><span><?= (int)$s['charts'] ?> तक्ते</span><?php if ($s['l1']): ?><span class="b">🏆 <?= (int)$s['l1'] ?> वेळा L1</span><?php endif; ?><?php if ($s['won']): ?><span class="g">✅ <?= (int)$s['won'] ?> मंजूर · <?= h(purchaseInr($s['won_amt'])) ?></span><?php endif; ?><?php if ($s['last']): ?><span>शेवटचा: <a href="view.php?id=<?= (int)$s['last_id'] ?>"><?= h(date('d.m.Y', strtotime($s['last']))) ?></a></span><?php endif; ?></div><?php endif; ?>
        </div>
        <?php if ($canEdit): ?><div class="sp-a"><a href="?edit=<?= (int)$r['id'] ?><?= $q !== '' ? '&q=' . urlencode($q) : '' ?>">✎</a>
          <form method="POST" class="m-0"><input type="hidden" name="action" value="set_active"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="on" value="<?= $r['is_active'] ? 0 : 1 ?>"><button type="submit" title="<?= $r['is_active'] ? 'निष्क्रिय करा' : 'सक्रिय करा' ?>"><?= $r['is_active'] ? 'निष्क्रिय' : 'सक्रिय करा' ?></button></form></div><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php if ($canEdit): ?>
  <div class="col-lg-4">
    <div class="panel" style="padding:14px 16px;position:sticky;top:80px;">
      <div style="font-weight:800;margin-bottom:8px;"><?= $edit ? '✎ पुरवठादार बदला' : '＋ नवीन पुरवठादार' ?></div>
      <form method="POST" data-no-smart-filter><input type="hidden" name="action" value="save_supplier"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
        <label class="form-label-custom">नाव *</label><input type="text" name="name" class="form-control-custom" value="<?= h($edit['name'] ?? '') ?>" maxlength="200" required>
        <label class="form-label-custom mt-2">प्रकार</label><input type="text" name="category" class="form-control-custom" value="<?= h($edit['category'] ?? '') ?>" maxlength="100" list="spCats" placeholder="उदा. Electrical, Hardware, Stationery">
        <div class="row g-2 mt-0"><div class="col-6"><label class="form-label-custom mt-2">संपर्क व्यक्ती</label><input type="text" name="contact_person" class="form-control-custom" value="<?= h($edit['contact_person'] ?? '') ?>" maxlength="150"></div>
          <div class="col-6"><label class="form-label-custom mt-2">फोन</label><input type="tel" name="phone" class="form-control-custom" value="<?= h($edit['phone'] ?? '') ?>" maxlength="40"></div></div>
        <div class="row g-2 mt-0"><div class="col-6"><label class="form-label-custom mt-2">GSTIN</label><input type="text" name="gstin" class="form-control-custom" value="<?= h($edit['gstin'] ?? '') ?>" maxlength="20" style="text-transform:uppercase;"></div>
          <div class="col-6"><label class="form-label-custom mt-2">Email</label><input type="email" name="email" class="form-control-custom" value="<?= h($edit['email'] ?? '') ?>" maxlength="150"></div></div>
        <label class="form-label-custom mt-2">पत्ता</label><input type="text" name="address" class="form-control-custom" value="<?= h($edit['address'] ?? '') ?>" maxlength="500">
        <label class="form-label-custom mt-2">शेरा</label><input type="text" name="notes" class="form-control-custom" value="<?= h($edit['notes'] ?? '') ?>" maxlength="500">
        <div class="d-flex gap-2 mt-3"><button type="submit" class="btn" style="background:#2563EB;color:#fff;border-radius:9px;font-weight:700;">✓ जतन</button><?php if ($edit): ?><a href="suppliers.php" class="btn" style="border:1px solid var(--line);border-radius:9px;">रद्द</a><?php endif; ?></div>
        <?php if ($edit): ?><div style="font-size:11.5px;color:var(--text-dim);margin-top:6px;">नाव बदललं तर सगळ्या तक्त्यांमध्येही नवीन नाव दिसेल.</div><?php endif; ?>
      </form>
    </div>
  </div>
  <?php endif; ?>
</div>
<datalist id="spCats"><?php foreach (array_unique(array_merge($cats, ['Electrical', 'Hardware', 'Stationery', 'Computer / IT', 'Furniture', 'Sports', 'Kitchen', 'Cleaning', 'Civil / Construction', 'Printing', 'Pashukhadya (गौशाळा)'])) as $c): ?><option value="<?= h($c) ?>"><?php endforeach; ?></datalist>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
