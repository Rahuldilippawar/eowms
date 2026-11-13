<?php
// ✉️ Email नोंद — कधी, कोणाला, कोणता Email गेला आणि तो यशस्वी झाला की नाही
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
requireRole(['super_admin'], '../');
require_once __DIR__ . '/../includes/mailer.php';
$orgId = activeOrgId();
$ready = saTableReady($pdo, 'email_log');
$cats = eowmsMailCategories();

$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-d', strtotime('-29 days'));
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
if ($from > $to) { [$from, $to] = [$to, $from]; }
$cat = isset($cats[$_GET['cat'] ?? '']) ? $_GET['cat'] : '';
$status = in_array($_GET['status'] ?? '', ['sent', 'failed'], true) ? $_GET['status'] : '';
$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['p'] ?? 1)); $per = 50;

// संस्थेचे Email + संस्था न कळलेले (उदा. बाहेरचा Backup पत्ता)
$where = "(l.organization_id = ? OR l.organization_id IS NULL) AND l.sent_at >= ? AND l.sent_at < ? + INTERVAL 1 DAY";
$p = [$orgId, $from, $to];
$base = [$where, $p];
if ($cat !== '') { $where .= " AND l.category = ?"; $p[] = $cat; }
if ($status !== '') { $where .= " AND l.status = ?"; $p[] = $status; }
if ($q !== '') { $where .= " AND (l.to_email LIKE ? OR u.name LIKE ? OR l.subject LIKE ?)"; $lk = '%' . $q . '%'; array_push($p, $lk, $lk, $lk); }

$rows = []; $total = 0; $sum = ['n' => 0, 'sent' => 0, 'failed' => 0, 'people' => 0]; $byCat = []; $today = ['sent' => 0, 'failed' => 0]; $daily = [];
if ($ready) {
    $join = "FROM email_log l LEFT JOIN users u ON u.id = l.to_user_id LEFT JOIN departments d ON d.id = u.department_id LEFT JOIN users b ON b.id = l.sent_by";
    if (($_GET['export'] ?? '') === 'csv') {
        $st = $pdo->prepare("SELECT l.sent_at, u.name, l.to_email, d.name dept, l.category, l.subject, l.status, l.error_message, l.attachments, b.name by_name, l.source $join WHERE $where ORDER BY l.id DESC LIMIT 20000");
        $st->execute($p);
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="email-log-' . $from . '-to-' . $to . '.csv"');
        $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['वेळ', 'नाव', 'Email', 'विभाग', 'प्रकार', 'विषय', 'स्थिती', 'कारण', 'Attachments', 'कोणी पाठवला', 'कुठून']);
        foreach ($st as $r) {
            fputcsv($out, [date('d-m-Y h:i A', strtotime($r['sent_at'])), $r['name'], $r['to_email'], $r['dept'], strip_tags($cats[$r['category']] ?? $r['category']), $r['subject'],
                $r['status'] === 'sent' ? 'पाठवला' : 'अयशस्वी', $r['error_message'], $r['attachments'], $r['by_name'] ?: (str_starts_with((string)$r['source'], 'cron:') ? 'आपोआप (Cron)' : ''), $r['source']]);
        }
        exit;
    }
    $st = $pdo->prepare("SELECT COUNT(*) n, SUM(l.status = 'sent') sent, SUM(l.status = 'failed') failed, COUNT(DISTINCT l.to_email) people $join WHERE $where");
    $st->execute($p); $sum = array_map('intval', $st->fetch(PDO::FETCH_ASSOC) ?: $sum); $total = $sum['n'];
    $st = $pdo->prepare("SELECT l.category, COUNT(*) n, SUM(l.status = 'failed') f FROM email_log l WHERE {$base[0]} GROUP BY l.category ORDER BY n DESC");
    $st->execute($base[1]); $byCat = $st->fetchAll();
    $st = $pdo->prepare("SELECT SUM(status = 'sent') s, SUM(status = 'failed') f FROM email_log WHERE (organization_id = ? OR organization_id IS NULL) AND sent_at >= CURDATE()");
    $st->execute([$orgId]); $t = $st->fetch(); $today = ['sent' => (int)$t['s'], 'failed' => (int)$t['f']];
    $st = $pdo->prepare("SELECT DATE(l.sent_at) d, SUM(l.status = 'sent') s, SUM(l.status = 'failed') f $join WHERE $where GROUP BY DATE(l.sent_at)");
    $st->execute($p); foreach ($st as $r) { $daily[$r['d']] = [(int)$r['s'], (int)$r['f']]; }
    $st = $pdo->prepare("SELECT l.*, u.name, u.photo_path, d.name dept, b.name by_name $join WHERE $where ORDER BY l.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
    $st->execute($p); $rows = $st->fetchAll();
}
$pages = max(1, (int)ceil($total / $per));
$qs = fn($o) => '?' . http_build_query(array_filter(array_merge(['from' => $from, 'to' => $to, 'cat' => $cat, 'status' => $status, 'q' => $q], $o), fn($v) => $v !== '' && $v !== null));
$ago = function ($ts) {
    $s = time() - strtotime($ts);
    if ($s < 60) return 'आत्ता';
    if ($s < 3600) return (int)floor($s / 60) . ' मिनिटांपूर्वी';
    if ($s < 86400) return (int)floor($s / 3600) . ' तासांपूर्वी';
    return (int)floor($s / 86400) . ' दिवसांपूर्वी';
};

$pageTitle = 'Email नोंद';
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
require_once __DIR__ . '/../includes/smart-insights.php';
?>
<div class="page-eyebrow">Super Admin</div>
<div class="page-title mb-1">✉️ Email नोंद</div>
<div style="font-size:12.5px;color:var(--text-dim);margin-bottom:8px;">System मधून कधी, कोणाला, कोणता Email गेला आणि तो Server ने स्वीकारला की नाही. (Email चा मजकूर साठवला जात नाही; OTP/पासवर्ड लपवलेले.)</div>
<?= saNav('email-log') ?>
<?php if (!$ready): ?><div class="alert alert-warning">⚠ Database Update बाकी — <a href="../system-update/db-update.php">System Update → Database Update</a> चालवा. त्यानंतर पाठवलेल्या Email ची नोंद इथे दिसेल.</div><?php endif; ?>

<?php smartTiles([
    ['label' => 'आज पाठवले', 'value' => $today['sent'], 'sub' => $today['failed'] ? '⚠ ' . $today['failed'] . ' अयशस्वी' : 'एकही अयशस्वी नाही', 'tone' => $today['failed'] ? 'warn' : 'ok', 'href' => $qs(['from' => date('Y-m-d'), 'to' => date('Y-m-d'), 'p' => ''])],
    ['label' => 'या कालावधीत', 'value' => $sum['n'], 'sub' => $sum['people'] . ' पत्ते'],
    ['label' => 'यशस्वी', 'value' => $sum['sent'], 'sub' => $sum['n'] ? round($sum['sent'] * 100 / $sum['n']) . '%' : '—', 'tone' => 'ok', 'href' => $qs(['status' => 'sent', 'p' => ''])],
    ['label' => 'अयशस्वी', 'value' => $sum['failed'], 'sub' => $sum['failed'] ? 'कारण बघा' : 'सगळे गेले', 'tone' => $sum['failed'] ? 'bad' : '', 'href' => $qs(['status' => 'failed', 'p' => ''])],
]); ?>

<?php if ($byCat): ?>
<div class="el-cats">
  <a href="<?= h($qs(['cat' => '', 'p' => ''])) ?>" class="<?= $cat === '' ? 'on' : '' ?>">सगळे</a>
  <?php foreach ($byCat as $c): ?>
    <a href="<?= h($qs(['cat' => $c['category'], 'p' => ''])) ?>" class="<?= $cat === $c['category'] ? 'on' : '' ?>"><?= h($cats[$c['category']] ?? $c['category']) ?> <b><?= (int)$c['n'] ?></b><?= $c['f'] ? ' <i>' . (int)$c['f'] . ' ✗</i>' : '' ?></a>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($daily && count($daily) > 1): $mx = max(array_map(fn($x) => $x[0] + $x[1], $daily)); ?>
<div class="panel mb-3 el-spark" title="दिवसनिहाय Email">
  <?php for ($d = strtotime($from); $d <= strtotime($to); $d += 86400): $k = date('Y-m-d', $d); [$s, $f] = $daily[$k] ?? [0, 0]; ?>
    <a href="<?= h($qs(['from' => $k, 'to' => $k, 'p' => ''])) ?>" title="<?= date('d M', $d) ?>: <?= $s ?> पाठवले<?= $f ? ', ' . $f . ' अयशस्वी' : '' ?>"><span class="f" style="height:<?= $mx ? round($f * 100 / $mx) : 0 ?>%"></span><span class="s" style="height:<?= $mx ? round($s * 100 / $mx) : 0 ?>%"></span></a>
  <?php endfor; ?>
</div>
<?php endif; ?>

<form method="get" class="panel mb-3 el-filter">
  <input type="date" name="from" value="<?= h($from) ?>" class="form-control form-control-sm">
  <input type="date" name="to" value="<?= h($to) ?>" class="form-control form-control-sm">
  <select name="cat" class="form-select form-select-sm"><option value="">सगळे प्रकार</option><?php foreach ($cats as $k => $l): ?><option value="<?= h($k) ?>" <?= $cat === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select>
  <select name="status" class="form-select form-select-sm"><option value="">सगळी स्थिती</option><option value="sent" <?= $status === 'sent' ? 'selected' : '' ?>>✅ पाठवला</option><option value="failed" <?= $status === 'failed' ? 'selected' : '' ?>>❌ अयशस्वी</option></select>
  <input type="search" name="q" value="<?= h($q) ?>" placeholder="नाव / Email / विषय" class="form-control form-control-sm">
  <button class="btn btn-sm btn-primary">शोधा</button>
  <a href="<?= h($qs(['export' => 'csv'])) ?>" class="btn btn-sm btn-outline-secondary">⬇ Excel (CSV)</a>
</form>

<div class="panel">
  <?php if (!$rows): ?><div class="sm-empty"><?= $ready ? 'या कालावधीत कोणताही Email नोंदलेला नाही.' : 'Database Update नंतर नोंद सुरू होईल.' ?></div><?php else: ?>
  <div class="table-responsive"><table class="rtable el-table">
    <thead><tr><th>कधी</th><th>कोणाला</th><th>कोणता Email</th><th>स्थिती</th><th>कोणी पाठवला</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr class="<?= $r['status'] === 'failed' ? 'bad' : '' ?>">
        <td data-sort="<?= h($r['sent_at']) ?>" style="white-space:nowrap;"><b><?= date('d M Y', strtotime($r['sent_at'])) ?></b><div class="el-dim"><?= date('h:i A', strtotime($r['sent_at'])) ?> · <?= h($ago($r['sent_at'])) ?></div></td>
        <td><b><?= h($r['name'] ?: '—') ?></b><div class="el-dim"><?= h($r['to_email']) ?><?= $r['dept'] ? ' · ' . h($r['dept']) : '' ?></div></td>
        <td><span class="el-cat"><?= h($cats[$r['category']] ?? $r['category']) ?></span><div style="font-size:12.5px;margin-top:3px;"><?= h($r['subject']) ?><?= $r['attachments'] ? ' <span class="el-dim">📎 ' . (int)$r['attachments'] . '</span>' : '' ?></div></td>
        <td style="white-space:nowrap;"><?php if ($r['status'] === 'sent'): ?><span class="status-chip done">✅ पाठवला</span><?php else: ?><span class="status-chip" style="background:#FDECEC;color:#B42318;">❌ अयशस्वी</span><?php if ($r['error_message']): ?><div class="el-dim" style="max-width:220px;white-space:normal;"><?= h(mb_strimwidth($r['error_message'], 0, 90, '…')) ?></div><?php endif; ?><?php endif; ?></td>
        <td class="el-dim"><?= $r['by_name'] ? '👤 ' . h($r['by_name']) : (str_starts_with((string)$r['source'], 'cron:') ? '⏱️ आपोआप (Cron)' : '⚙️ System') ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($pages > 1): ?>
  <div class="el-pages">
    <?php if ($page > 1): ?><a href="<?= h($qs(['p' => $page - 1])) ?>">← मागे</a><?php endif; ?>
    <span>पान <?= $page ?> / <?= $pages ?> · एकूण <?= number_format($total) ?></span>
    <?php if ($page < $pages): ?><a href="<?= h($qs(['p' => $page + 1])) ?>">पुढे →</a><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
<div class="el-dim mt-2">ℹ️ "पाठवला" म्हणजे Hosting च्या Mail Server ने Email स्वीकारला. Spam मध्ये गेला की नाही हे Server सांगू शकत नाही. नोंद 1 वर्ष ठेवली जाते.</div>
<style>
  .el-cats{display:flex;flex-wrap:wrap;gap:6px;margin-bottom:12px;}
  .el-cats a{border:1px solid var(--line);border-radius:999px;padding:4px 11px;font-size:12px;color:var(--text);text-decoration:none;background:var(--surface,#fff);}
  .el-cats a b{font-family:'JetBrains Mono',monospace;margin-left:2px;} .el-cats a i{font-style:normal;color:#B42318;font-weight:700;margin-left:3px;}
  .el-cats a.on{background:var(--rail);border-color:var(--rail);color:#fff;} .el-cats a.on i{color:#fff;}
  .el-spark{display:flex;align-items:flex-end;gap:2px;height:64px;padding:8px 12px;}
  .el-spark a{flex:1;height:100%;display:flex;flex-direction:column-reverse;min-width:3px;}
  .el-spark .s{background:var(--rail);opacity:.75;border-radius:2px 2px 0 0;} .el-spark .f{background:#DC2626;}
  .el-spark a:hover .s{opacity:1;}
  .el-filter{display:flex;flex-wrap:wrap;gap:8px;align-items:center;padding:10px 12px;}
  .el-filter .form-control,.el-filter .form-select{width:auto;flex:1 1 140px;}
  .el-dim{font-size:11.5px;color:var(--text-dim);}
  .el-cat{font-size:11px;font-weight:700;background:var(--canvas);border-radius:6px;padding:2px 7px;white-space:nowrap;}
  .el-table tr.bad td{background:rgba(220,38,38,.05);}
  @media (max-width:640px){
    table.rtable.el-table{min-width:0 !important;}
    .el-table thead{display:none;} .el-table,.el-table tbody,.el-table tr,.el-table td{display:block;width:100%;}
    .el-table tr{border-bottom:1px solid var(--line);padding:10px 4px;display:grid;grid-template-columns:1fr auto;gap:2px 10px;}
    .el-table td{border:0 !important;padding:0 !important;white-space:normal !important;}
    .el-table td:nth-child(1){grid-column:1;} .el-table td:nth-child(4){grid-column:2;grid-row:1;text-align:right;}
    .el-table td:nth-child(2),.el-table td:nth-child(3),.el-table td:nth-child(5){grid-column:1 / -1;}
  }
  .el-pages{display:flex;gap:14px;justify-content:center;align-items:center;padding:12px 0 2px;font-size:13px;}
  .el-pages a{color:var(--rail);font-weight:600;text-decoration:none;}
</style>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>
