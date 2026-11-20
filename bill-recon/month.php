<?php
// 🧾 एका महिन्याचा बिल ताळमेळ — भांडार / खरेदी / लेखा: संख्या, डिजिटल मान्यता, फरक, कारणे मिमांसा, कार्याध्यक्ष शेरा, इतिहास
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
require_once __DIR__ . '/../includes/bill-recon.php';
if (!billReconCanView()) { http_response_code(403); die('<div style="font-family:sans-serif;padding:40px;">बिल ताळमेळ पाहण्याची परवानगी नाही.</div>'); }
if (!billReconReady($pdo)) { header('Location: index.php'); exit; }
$orgId = activeOrgId();
$user = currentUser();
$p = (string)($_GET['p'] ?? date('Y-m'));
if (!preg_match('/^(20\d{2})-(0[1-9]|1[0-2])$/', $p) || $p > date('Y-m')) { header('Location: index.php'); exit; }
$depts = billReconDepts();
$self = 'month.php?p=' . $p;
$row = billReconMonth($pdo, $orgId, $p, null, false);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['action'] ?? '');
    $dept = (string)($_POST['dept'] ?? '');
    $uName = (string)($user['name'] ?? '');
    $flash = null;
    if (!$row && billReconCanAny()) $row = billReconMonth($pdo, $orgId, $p, (int)$user['id']);
    if (!$row) { http_response_code(403); die('परवानगी नाही.'); }
    $mid = (int)$row['id'];
    $link = 'bill-recon/' . $self;
    $mName = billReconMonthName($p);

    if (in_array($act, ['save_count', 'approve_dept', 'withdraw_approval'], true)) {
        if (!billReconCan($dept)) { http_response_code(403); die('या विभागाची परवानगी नाही.'); }
        $dn = $depts[$dept][0];
        if ($act === 'save_count') {
            $raw = trim((string)($_POST['count'] ?? ''));
            $c = $raw === '' ? null : max(0, min(100000, (int)$raw));
            $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255);
            $changed = (string)$row[$dept . '_count'] !== (string)$c;
            $approve = !empty($_POST['approve_now']) && $c !== null;
            if ($changed && $row[$dept . '_at']) {
                // संख्या बदलली — आधीची मान्यता रद्द (नवीन संख्येवर पुन्हा मान्यता हवी)
                $pdo->prepare("UPDATE bill_recon_months SET {$dept}_by = NULL, {$dept}_name = NULL, {$dept}_at = NULL WHERE id = ?")->execute([$mid]);
                billReconLog($pdo, $orgId, $mid, 'unapproved', $dn . ' — संख्या बदलल्यामुळे', $user['id']);
            }
            $pdo->prepare("UPDATE bill_recon_months SET {$dept}_count = ?, {$dept}_note = ?, updated_at = NOW() WHERE id = ?")->execute([$c, $note ?: null, $mid]);
            if ($changed) billReconLog($pdo, $orgId, $mid, 'count', $dn . ': ' . ($row[$dept . '_count'] ?? '—') . ' → ' . ($c ?? '—'), $user['id']);
            if ($approve) {
                $pdo->prepare("UPDATE bill_recon_months SET {$dept}_by = ?, {$dept}_name = ?, {$dept}_at = NOW() WHERE id = ?")->execute([$user['id'], $uName, $mid]);
                billReconLog($pdo, $orgId, $mid, 'approved', $dn . ' — ' . $c . ' बिलं', $user['id']);
                billReconNotifyOthers($pdo, $orgId, $dept, '🧾 बिल ताळमेळ — ' . $mName, $dn . ': ' . $c . ' बिलं (डिजिटल मान्यता — ' . $uName . ')', $link, $user['id']);
            } elseif ($changed && $c !== null) {
                billReconNotifyOthers($pdo, $orgId, $dept, '🧾 बिल ताळमेळ — ' . $mName, $dn . ' ने ' . $c . ' बिलं नोंदली', $link, $user['id']);
            }
            logActivity('बिल ताळमेळ — संख्या', 'bill_recon', $mid, $mName . ' · ' . $dn . ' · ' . ($c ?? '—'));
            $flash = '✓ ' . $dn . ' — ' . ($c ?? '—') . ' बिलं जतन' . ($approve ? ' व डिजिटल मान्यता दिली.' : '.');
        } elseif ($act === 'approve_dept') {
            if ($row[$dept . '_count'] === null) { $flash = '⚠️ आधी बिलांची संख्या भरा.'; }
            else {
                $pdo->prepare("UPDATE bill_recon_months SET {$dept}_by = ?, {$dept}_name = ?, {$dept}_at = NOW(), updated_at = NOW() WHERE id = ?")->execute([$user['id'], $uName, $mid]);
                billReconLog($pdo, $orgId, $mid, 'approved', $dn . ' — ' . $row[$dept . '_count'] . ' बिलं', $user['id']);
                billReconNotifyOthers($pdo, $orgId, $dept, '🧾 बिल ताळमेळ — ' . $mName, $dn . ': ' . $row[$dept . '_count'] . ' बिलं (डिजिटल मान्यता — ' . $uName . ')', $link, $user['id']);
                logActivity('बिल ताळमेळ — डिजिटल मान्यता', 'bill_recon', $mid, $mName . ' · ' . $dn);
                $flash = '✍️ ' . $dn . ' — डिजिटल मान्यता दिली.';
            }
        } else {
            $pdo->prepare("UPDATE bill_recon_months SET {$dept}_by = NULL, {$dept}_name = NULL, {$dept}_at = NULL, updated_at = NOW() WHERE id = ?")->execute([$mid]);
            billReconLog($pdo, $orgId, $mid, 'unapproved', $dn, $user['id']);
            $flash = '↺ ' . $dn . ' — मान्यता काढली.';
        }
    } elseif ($act === 'add_issue' && billReconCanAny()) {
        $reason = mb_substr(trim((string)($_POST['reason'] ?? '')), 0, 500);
        $held = isset($depts[$_POST['held_at'] ?? '']) ? $_POST['held_at'] : 'purchase';
        if (mb_strlen($reason) < 3) $flash = '⚠️ फरकाचं कारण लिहा.';
        else {
            $party = mb_substr(trim((string)($_POST['party'] ?? '')), 0, 150);
            $bc = max(1, min(100000, (int)($_POST['bill_count'] ?? 1)));
            $pdo->prepare("INSERT INTO bill_recon_issues (month_id, organization_id, party, bill_count, held_at, reason, status, created_by, created_at) VALUES (?,?,?,?,?,?,'open',?,NOW())")
                ->execute([$mid, $orgId, $party ?: null, $bc, $held, $reason, $user['id']]);
            billReconLog($pdo, $orgId, $mid, 'issue', ($party ? $party . ' — ' : '') . $bc . ' बिलं — ' . $reason, $user['id']);
            billReconNotifyOthers($pdo, $orgId, '', '⚠️ बिल ताळमेळ फरक — ' . $mName, ($party ? $party . ': ' : '') . $bc . ' बिलं — ' . $reason, $link, $user['id']);
            $flash = '✓ फरकाचं कारण नोंदलं.';
        }
    } elseif (in_array($act, ['resolve_issue', 'reopen_issue'], true) && billReconCanAny()) {
        $iid = (int)($_POST['issue_id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM bill_recon_issues WHERE id = ? AND month_id = ? AND organization_id = ?"); $st->execute([$iid, $mid, $orgId]); $iss = $st->fetch();
        if ($iss && $act === 'resolve_issue') {
            $rn = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 255);
            $pdo->prepare("UPDATE bill_recon_issues SET status = 'resolved', resolved_note = ?, resolved_by = ?, resolved_at = NOW() WHERE id = ?")->execute([$rn ?: null, $user['id'], $iid]);
            billReconLog($pdo, $orgId, $mid, 'resolved', ($iss['party'] ? $iss['party'] . ' — ' : '') . $iss['bill_count'] . ' बिलं' . ($rn ? ' · ' . $rn : ''), $user['id']);
            $flash = '✅ फरक मिटला म्हणून नोंदलं.';
        } elseif ($iss) {
            $pdo->prepare("UPDATE bill_recon_issues SET status = 'open', resolved_note = NULL, resolved_by = NULL, resolved_at = NULL WHERE id = ?")->execute([$iid]);
            billReconLog($pdo, $orgId, $mid, 'reopened', ($iss['party'] ?: '') . ' — ' . $iss['bill_count'] . ' बिलं', $user['id']);
            $flash = '↺ फरक पुन्हा उघडला.';
        }
    } elseif ($act === 'save_head_remark' && billReconCanHead()) {
        $hr = mb_substr(trim(str_replace("\r", '', (string)($_POST['head_remark'] ?? ''))), 0, 2000);
        $pdo->prepare("UPDATE bill_recon_months SET head_remark = ?, head_by = ?, head_name = ?, head_at = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$hr ?: null, $hr ? $user['id'] : null, $hr ? $uName : null, $hr ? date('Y-m-d H:i:s') : null, $mid]);
        billReconLog($pdo, $orgId, $mid, 'head', $hr ?: '(काढला)', $user['id']);
        if ($hr) billReconNotifyOthers($pdo, $orgId, '', '🏛️ बिल ताळमेळ — ' . $mName, 'मा. कार्याध्यक्ष शेरा: ' . mb_strimwidth($hr, 0, 120, '…'), $link, $user['id']);
        $flash = '✓ शेरा जतन झाला.';
    }
    if ($flash) $_SESSION['flash'] = $flash;
    header('Location: ' . $self); exit;
}

$r = $row ?: ['id' => 0, 'period' => $p, 'store_count' => null, 'purchase_count' => null, 'accounts_count' => null, 'store_at' => null, 'purchase_at' => null, 'accounts_at' => null,
    'store_note' => null, 'purchase_note' => null, 'accounts_note' => null, 'store_name' => null, 'purchase_name' => null, 'accounts_name' => null, 'store_by' => null, 'purchase_by' => null, 'accounts_by' => null,
    'head_remark' => null, 'head_name' => null, 'head_at' => null];
[$sk, $sl, $tone] = billReconStatus($r);
$issues = $events = [];
if ($row) {
    $st = $pdo->prepare("SELECT * FROM bill_recon_issues WHERE month_id = ? ORDER BY status = 'open' DESC, id"); $st->execute([$row['id']]); $issues = $st->fetchAll();
    $st = $pdo->prepare("SELECT e.*, u.name uname FROM bill_recon_events e LEFT JOIN users u ON u.id = e.user_id WHERE e.month_id = ? ORDER BY e.id DESC LIMIT 60"); $st->execute([$row['id']]); $events = $st->fetchAll();
}
$hints = billReconHints($pdo, $orgId, $p);
$openBills = array_sum(array_map(fn($i) => $i['status'] === 'open' ? (int)$i['bill_count'] : 0, $issues));
$prev = date('Y-m', strtotime($p . '-01 -1 month')); $next = date('Y-m', strtotime($p . '-01 +1 month'));
$colors = ['store' => '#0891B2', 'purchase' => '#D97706', 'accounts' => '#7C3AED'];
$evL = billReconEventLabels();

$pageTitle = 'बिल ताळमेळ — ' . billReconMonthName($p);
$rootPath = '../';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
  .bm-nav{display:flex;gap:6px;align-items:center;flex-wrap:wrap;}
  .bm-nav a{border:1px solid var(--line);background:var(--surface);color:var(--text);border-radius:10px;font-size:12.5px;font-weight:600;padding:7px 11px;text-decoration:none;}
  .bm-ban{border-radius:16px;padding:14px 16px;margin:8px 0 14px;display:flex;gap:14px;align-items:center;flex-wrap:wrap;}
  .bm-ban.ok{background:linear-gradient(90deg,#DCFCE7,#F0FDF4);color:#14532D;} .bm-ban.warn{background:linear-gradient(90deg,#FEF3C7,#FFFBEB);color:#78350F;} .bm-ban.bad{background:linear-gradient(90deg,#FEE2E2,#FEF2F2);color:#7F1D1D;}
  .bm-ban b{font-size:17px;} .bm-ban .dd{font-size:12.5px;}
  .bm-cards{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;}
  .bm-card{border:1px solid var(--line);border-radius:16px;background:var(--surface);overflow:hidden;display:flex;flex-direction:column;}
  .bm-card .top{padding:12px 14px;border-top:4px solid;}
  .bm-card .nm{font-weight:800;font-size:14px;} .bm-card .big{font-size:34px;font-weight:800;line-height:1.1;margin-top:4px;} .bm-card .big small{font-size:12px;color:var(--text-dim);font-weight:600;}
  .bm-card .hint{font-size:11.5px;color:var(--text-dim);margin-top:2px;}
  .bm-card form{padding:0 14px 12px;}
  .bm-sign{margin:auto 14px 14px;border:1.5px dashed #16A34A;border-radius:12px;padding:9px 11px;background:color-mix(in srgb,#16A34A 6%,var(--surface));font-size:12.5px;}
  .bm-sign b{color:#166534;} .bm-sign .code{font-family:ui-monospace,monospace;font-size:11px;color:var(--text-dim);}
  .bm-sign.pend{border-color:#D97706;background:color-mix(in srgb,#D97706 6%,var(--surface));}
  .bm-cmp{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0;font-size:12.5px;} .bm-cmp span{border:1px solid var(--line);border-radius:10px;padding:6px 10px;background:var(--surface);}
  .bm-cmp .neg{color:#991B1B;font-weight:700;} .bm-cmp .zero{color:#166534;font-weight:700;}
  .bm-iss{border:1px solid var(--line);border-left:4px solid #DC2626;border-radius:12px;padding:10px 12px;margin-bottom:8px;background:var(--surface);}
  .bm-iss.res{border-left-color:#16A34A;} .bm-iss.res .rs{text-decoration:line-through;color:var(--text-dim);}
  .bm-ev{font-size:12.5px;border-left:2px solid var(--line);padding:2px 0 8px 12px;position:relative;} .bm-ev:before{content:'';position:absolute;left:-5px;top:6px;width:8px;height:8px;border-radius:50%;background:#0891B2;}
  @media (max-width:860px){ .bm-cards{grid-template-columns:1fr;} }
</style>

<div class="page-eyebrow">🧾 मासिक बिल जमा / ताळमेळ</div>
<div class="d-flex align-items-center justify-content-between flex-wrap gap-2">
  <div class="page-title mb-0"><?= h(billReconMonthName($p)) ?></div>
  <div class="bm-nav">
    <a href="month.php?p=<?= h($prev) ?>">← <?= h(billReconMonthName($prev, false)) ?></a>
    <?php if ($next <= date('Y-m')): ?><a href="month.php?p=<?= h($next) ?>"><?= h(billReconMonthName($next, false)) ?> →</a><?php endif; ?>
    <a href="index.php?fy=<?= h(billReconFy($p)) ?>">📋 आ.व. <?= h(billReconFyLabel(billReconFy($p))) ?></a>
    <a href="report.php?fy=<?= h(billReconFy($p)) ?>&from=<?= h($p) ?>&to=<?= h($p) ?>" target="_blank">📄 Print</a>
  </div>
</div>

<div class="bm-ban <?= $tone ?>">
  <b><?= h($sl) ?></b>
  <div class="dd">
    <?php if ($sk === 'diff'): ?>फरक <b style="font-size:14px;"><?= billReconDiff($r) ?></b> बिलं<?= $openBills ? ' · कारणं नोंदलेली: ' . $openBills . ' बिलं' : ' · <b>फरकाचं कारण अजून नोंदलेलं नाही</b>' ?>
    <?php elseif ($sk === 'entry'): ?>तिन्ही विभागांनी या महिन्याची बिल संख्या भरायची आहे.
    <?php elseif ($sk === 'match_wait'): ?>संख्या जुळल्या — बाकी विभागांची डिजिटल मान्यता हवी.
    <?php else: ?>तिन्ही विभागांची संख्या जुळली व डिजिटल मान्यता झाली.<?php endif; ?>
  </div>
</div>

<div class="bm-cards">
<?php foreach ($depts as $k => $d): $c = $r[$k . '_count']; $can = billReconCan($k); ?>
  <div class="bm-card">
    <div class="top" style="border-top-color:<?= $colors[$k] ?>;">
      <div class="nm"><?= $d[1] ?> <?= h($d[0]) ?></div>
      <div class="big" style="color:<?= $colors[$k] ?>;"><?= $c === null ? '—' : h(number_format((int)$c)) ?> <small>प्राप्त बिलं</small></div>
      <?php if ($r[$k . '_note']): ?><div class="hint">📝 <?= h($r[$k . '_note']) ?></div><?php endif; ?>
      <?php if (isset($hints[$k])): ?><div class="hint">🔗 <?= h($hints[$k][1]) ?>: <b><?= (int)$hints[$k][0] ?></b></div><?php endif; ?>
    </div>
    <?php if ($can): ?>
    <form method="POST" data-no-smart-filter>
      <input type="hidden" name="action" value="save_count"><input type="hidden" name="dept" value="<?= $k ?>">
      <div class="d-flex gap-2"><input type="number" name="count" class="form-control-custom" min="0" max="100000" value="<?= $c === null ? '' : (int)$c ?>" placeholder="बिलांची संख्या" inputmode="numeric" style="max-width:130px;">
        <input type="text" name="note" class="form-control-custom" maxlength="255" value="<?= h($r[$k . '_note']) ?>" placeholder="टीप (ऐच्छिक)"></div>
      <?php if (!$r[$k . '_at']): ?><label style="font-size:12px;margin-top:6px;display:flex;gap:6px;align-items:flex-start;"><input type="checkbox" name="approve_now" value="1" style="margin-top:3px;"> <span>✍️ संख्या खात्री करून बरोबर आहे — <b>डिजिटल मान्यता</b> द्या</span></label><?php endif; ?>
      <button type="submit" class="btn btn-sm mt-2" style="background:<?= $colors[$k] ?>;color:#fff;border-radius:9px;font-weight:700;">✓ जतन</button>
    </form>
    <?php endif; ?>
    <?php if ($r[$k . '_at']): $paper = !$r[$k . '_by']; ?>
      <div class="bm-sign">
        <b>✍️ <?= $paper ? 'कागदी सही' : 'डिजिटल मान्यता' ?></b><br>
        वरील आकडेवारी <b><?= h($r[$k . '_name'] ?: '—') ?></b> यांनी खात्री करून दिली असून बरोबर आहे.
        <div class="code"><?= h(date('d.m.Y h:i A', strtotime($r[$k . '_at']))) ?><?= $paper ? '' : ' · पडताळणी कोड ' . h(billReconSignCode($r, $k)) ?></div>
        <?php if ($can): ?><form method="POST" class="m-0 p-0 mt-1" onsubmit="return confirm('<?= h($d[4]) ?> मान्यता काढायची?');" data-no-smart-filter><input type="hidden" name="action" value="withdraw_approval"><input type="hidden" name="dept" value="<?= $k ?>"><button type="submit" style="border:0;background:none;padding:0;font-size:11.5px;color:var(--text-dim);">↺ मान्यता काढा</button></form><?php endif; ?>
      </div>
    <?php elseif ($c !== null): ?>
      <div class="bm-sign pend">⏳ <?= h($d[4]) ?> डिजिटल मान्यता बाकी
        <?php if ($can): ?><form method="POST" class="m-0 p-0 mt-1" onsubmit="return confirm('<?= (int)$c ?> बिलं — खात्री करून डिजिटल मान्यता द्यायची?');" data-no-smart-filter><input type="hidden" name="action" value="approve_dept"><input type="hidden" name="dept" value="<?= $k ?>"><button type="submit" class="btn btn-sm" style="background:#16A34A;color:#fff;border-radius:8px;font-weight:700;">✍️ मान्यता द्या</button></form><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
</div>

<?php if ($r['store_count'] !== null || $r['purchase_count'] !== null || $r['accounts_count'] !== null):
  $cmp = [['खरेदी − भांडार', 'purchase', 'store'], ['लेखा − खरेदी', 'accounts', 'purchase'], ['लेखा − भांडार', 'accounts', 'store']]; ?>
<div class="bm-cmp">
  <?php foreach ($cmp as [$l, $a, $b]): if ($r[$a . '_count'] === null || $r[$b . '_count'] === null) continue; $dv = (int)$r[$a . '_count'] - (int)$r[$b . '_count']; ?>
    <span><?= h($l) ?> = <b class="<?= $dv ? 'neg' : 'zero' ?>"><?= $dv > 0 ? '+' : '' ?><?= $dv ?></b></span>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="row g-3 mt-1">
  <div class="col-lg-7">
    <div class="panel" style="padding:14px 16px;">
      <div style="font-weight:800;margin-bottom:8px;">⚠️ कारणे मिमांसा — ताळमेळ का नाही</div>
      <?php if (!$issues): ?><div style="font-size:13px;color:var(--text-dim);margin-bottom:8px;"><?= $sk === 'diff' ? 'फरक आहे — खाली कारण नोंदवा (कोणत्या पुरवठादाराची किती बिलं, कुठे अडकली, का).' : 'फरक नाही.' ?></div><?php endif; ?>
      <?php foreach ($issues as $n => $i): ?>
        <div class="bm-iss<?= $i['status'] === 'resolved' ? ' res' : '' ?>">
          <div class="rs"><b><?= $n + 1 ?>) <?= h($i['party'] ?: 'बिलं') ?></b> — <?= (int)$i['bill_count'] ?> बिलं · <?= h($i['reason']) ?></div>
          <div style="font-size:12px;margin-top:3px;"><?= $i['status'] === 'open' ? '📍 ' . h($depts[$i['held_at']][3] ?? '') . ' जमा / अडकली' : '✅ मिटले ' . ($i['resolved_at'] ? date('d.m.Y', strtotime($i['resolved_at'])) : '') . ($i['resolved_note'] ? ' — ' . h($i['resolved_note']) : '') ?></div>
          <?php if (billReconCanAny()): ?>
            <?php if ($i['status'] === 'open'): ?>
              <form method="POST" class="d-flex gap-2 mt-2" data-no-smart-filter><input type="hidden" name="action" value="resolve_issue"><input type="hidden" name="issue_id" value="<?= (int)$i['id'] ?>">
                <input type="text" name="note" class="form-control-custom" maxlength="255" placeholder="कसा मिटला (उदा. कोटेशन मिळाले, बिल लेखा विभागाला दिले)"><button type="submit" class="btn btn-sm" style="background:#16A34A;color:#fff;border-radius:8px;white-space:nowrap;font-weight:700;">✓ मिटले</button></form>
            <?php else: ?>
              <form method="POST" class="mt-1" data-no-smart-filter><input type="hidden" name="action" value="reopen_issue"><input type="hidden" name="issue_id" value="<?= (int)$i['id'] ?>"><button type="submit" style="border:0;background:none;padding:0;font-size:12px;color:var(--text-dim);">↺ पुन्हा उघडा</button></form>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
      <?php if (billReconCanAny()): ?>
        <form method="POST" class="mt-2" style="border-top:1px dashed var(--line);padding-top:10px;" data-no-smart-filter>
          <input type="hidden" name="action" value="add_issue">
          <div class="row g-2">
            <div class="col-md-5"><input type="text" name="party" class="form-control-custom" maxlength="150" placeholder="पुरवठादार / बिल (उदा. CMK ट्रेडर्स)" list="brParties"></div>
            <div class="col-md-2 col-4"><input type="number" name="bill_count" class="form-control-custom" min="1" value="<?= max(1, billReconDiff($r) - $openBills) ?>" title="बिलं"></div>
            <div class="col-md-5 col-8"><select name="held_at" class="form-select-custom"><?php foreach ($depts as $k => $d): ?><option value="<?= $k ?>" <?= $k === 'purchase' ? 'selected' : '' ?>>📍 <?= h($d[3]) ?></option><?php endforeach; ?></select></div>
            <div class="col-12"><input type="text" name="reason" class="form-control-custom" maxlength="500" required placeholder="कारण (उदा. तीन कोटेशन अभावी जमा नाही / मूळ बिले प्राप्त न झाल्याने)" list="brReasons"></div>
          </div>
          <button type="submit" class="btn btn-sm mt-2" style="background:#DC2626;color:#fff;border-radius:9px;font-weight:700;">＋ कारण नोंदवा</button>
        </form>
        <datalist id="brReasons"><option value="तीन कोटेशन अभावी बिल जमा नाही"><option value="मूळ बिले प्राप्त न झाल्याने जमा करणे बाकी"><option value="बिलावर विभाग प्रमुखांची सही बाकी"><option value="माल आवक नोंद (GRN) बाकी"><option value="बिल लेखा विभागाकडे पाठवणे बाकी"><option value="बिलात दुरुस्ती आवश्यक"></datalist>
        <?php $st = $pdo->prepare("SELECT DISTINCT party FROM bill_recon_issues WHERE organization_id = ? AND party IS NOT NULL ORDER BY party LIMIT 100"); $st->execute([$orgId]); ?>
        <datalist id="brParties"><?php foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $pt): ?><option value="<?= h($pt) ?>"><?php endforeach; ?></datalist>
      <?php endif; ?>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="panel" style="padding:14px 16px;">
      <div style="font-weight:800;margin-bottom:6px;">🏛️ माननीय कार्याध्यक्ष साहेब यांचा शेरा</div>
      <?php if ($r['head_remark']): ?>
        <div style="white-space:pre-line;font-size:13.5px;border-left:3px solid #7C3AED;padding:4px 10px;background:color-mix(in srgb,#7C3AED 6%,transparent);border-radius:0 8px 8px 0;"><?= h($r['head_remark']) ?></div>
        <div style="font-size:11.5px;color:var(--text-dim);margin-top:4px;">✍️ <?= h($r['head_name']) ?> · <?= h(date('d.m.Y h:i A', strtotime($r['head_at']))) ?></div>
      <?php elseif (!billReconCanHead()): ?><div style="font-size:13px;color:var(--text-dim);">अजून शेरा नाही.</div><?php endif; ?>
      <?php if (billReconCanHead() && $row): ?>
        <form method="POST" class="mt-2" data-no-smart-filter><input type="hidden" name="action" value="save_head_remark">
          <textarea name="head_remark" class="form-control-custom" rows="3" maxlength="2000" placeholder="उदा. फरकाच्या बिलांची पूर्तता करून पुढील महिन्यात सादर करावे."><?= h($r['head_remark']) ?></textarea>
          <button type="submit" class="btn btn-sm mt-2" style="background:#7C3AED;color:#fff;border-radius:9px;font-weight:700;">✓ शेरा जतन</button></form>
      <?php endif; ?>
    </div>
    <?php if ($events): ?>
    <div class="panel mt-3" style="padding:14px 16px;">
      <div style="font-weight:800;margin-bottom:8px;">🕘 इतिहास</div>
      <?php foreach ($events as $e): ?><div class="bm-ev"><b><?= h($evL[$e['event']] ?? $e['event']) ?></b><?= $e['note'] ? ' — ' . h($e['note']) : '' ?><div style="font-size:11px;color:var(--text-dim);"><?= h($e['uname'] ?: '') ?> · <?= h(date('d.m.Y h:i A', strtotime($e['created_at']))) ?></div></div><?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
