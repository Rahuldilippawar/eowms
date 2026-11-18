<?php
/**
 * 🚌 विद्यार्थी बस पास — आडवं Card (86 × 54 mm), ID Card च्याच Design-सेटिंगमधून (Logo, रंग, Principal सही).
 *  - कुठून → कुठपर्यंत (मोठ्या अक्षरात, बसच्या मार्गासह), कधीपासून → कधीपर्यंत valid
 *  - QR → students/verify-pass.php — Signature जुळलं तरच; पास चालू / संपला / रद्द लगेच दिसतं
 *  - नूतनीकरण = नवीन नोंद, जुना पास इतिहासात राहतो
 */
require_once __DIR__ . '/student-id-card.php';

function busPassReady(PDO $pdo) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { $pdo->query("SELECT 1 FROM student_bus_passes LIMIT 0"); return $ok = true; } catch (PDOException $e) { return $ok = false; }
}

function busPassSig($orgId, $id, $validTill) {
    return substr(hash_hmac('sha256', 'buspass|' . (int)$orgId . '|' . (int)$id . '|' . (string)$validTill, appSecret()), 0, 12);
}

function busPassVerifyUrl($orgId, $id, $validTill) {
    $base = siteBaseUrl();
    if (!empty($_SERVER['HTTP_HOST'])) {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $base = ($https ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';
    }
    return $base . 'students/verify-pass.php?org=' . (int)$orgId . '&id=' . (int)$id . '&s=' . busPassSig($orgId, $id, $validTill);
}

/** पासची स्थिती: active / expiring (7 दिवसांत संपतो) / upcoming / expired / cancelled */
function busPassState(array $p, $today = null) {
    $today = $today ?: date('Y-m-d');
    if (($p['status'] ?? 'active') === 'cancelled') return 'cancelled';
    if ($p['valid_till'] < $today) return 'expired';
    if ($p['valid_from'] > $today) return 'upcoming';
    if ($p['valid_till'] <= date('Y-m-d', strtotime($today . ' +7 days'))) return 'expiring';
    return 'active';
}

function busPassStateLabel($state) {
    return ['active' => 'चालू', 'expiring' => 'लवकरच संपतो', 'upcoming' => 'पुढून सुरू', 'expired' => 'संपला', 'cancelled' => 'रद्द'][$state] ?? $state;
}

/** पुढचा पास क्रमांक: BP-2026-00001 */
function busPassNextNo(PDO $pdo, $orgId) {
    $prefix = 'BP-' . date('Y') . '-';
    $st = $pdo->prepare("SELECT pass_no FROM student_bus_passes WHERE organization_id = ? AND pass_no LIKE ? ORDER BY id DESC LIMIT 1");
    $st->execute([$orgId, $prefix . '%']);
    $last = (int)substr((string)$st->fetchColumn(), strlen($prefix));
    return $prefix . str_pad((string)($last + 1), 5, '0', STR_PAD_LEFT);
}

/** शैक्षणिक वर्षाची मूळ वैधता: आज → 31 मे (जूनपासून नवीन वर्ष) */
function busPassDefaultDates() {
    $y = (int)date('Y'); if ((int)date('n') >= 6) $y++;
    return [date('Y-m-d'), $y . '-05-31'];
}

/** नवीन पास (नूतनीकरणही) — त्या विद्यार्थ्याचे आधीचे चालू पास, तारखा एकमेकांवर येत असतील तर, रद्द */
function busPassIssue(PDO $pdo, $orgId, $studentId, array $d, $userId) {
    $pdo->prepare("UPDATE student_bus_passes SET status = 'cancelled', cancelled_at = NOW() WHERE organization_id = ? AND student_id = ? AND status = 'active' AND valid_till >= ? AND valid_from <= ?")
        ->execute([$orgId, $studentId, $d['valid_from'], $d['valid_till']]);
    $no = busPassNextNo($pdo, $orgId);
    $pdo->prepare("INSERT INTO student_bus_passes (organization_id, student_id, pass_no, from_place, to_place, route_name, valid_from, valid_till, fee, remarks, issued_by, issued_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())")
        ->execute([$orgId, $studentId, $no, $d['from_place'], $d['to_place'], $d['route_name'] ?: null, $d['valid_from'], $d['valid_till'],
            ($d['fee'] ?? '') !== '' ? (float)$d['fee'] : null, $d['remarks'] ?? null, $userId]);
    return (int)$pdo->lastInsertId();
}

/** एक बस पास (Card) — $p = pass + student fields (name, class_name, division, course, admission_no, photo_path, campus_name) */
function busPassCard(array $p, array $set, $rootPath = '../') {
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    $state = busPassState($p);
    $cls = trim(($p['class_name'] ?? '') . (($p['division'] ?? '') !== '' ? ' - ' . $p['division'] : '') . (($p['course'] ?? '') !== '' ? ' · ' . $p['course'] : ''));
    $photo = (!empty($p['photo_path']) && eowmsPhotoExistsSafe($p['photo_path'])) ? $p['photo_path'] : '';
    $initials = mb_strtoupper(mb_substr(trim($p['name']), 0, 1) . (preg_match('/\s(\S)/u', trim($p['name']), $m) ? $m[1] : ''));
    $d = fn($x) => date('d.m.Y', strtotime($x));
    ob_start(); ?>
<div class="bpc bpc-<?= $h($state) ?>" style="--sc:<?= $h($set['color']) ?>;" data-pid="<?= (int)$p['id'] ?>">
  <div class="bpc-head">
    <?php if ($set['logo']): ?><div class="bpc-logo"><img src="<?= $h($rootPath . $set['logo']) ?>" alt=""></div><?php endif; ?>
    <div class="bpc-org">
      <?php if ($set['top_line'] !== ''): ?><div class="bpc-top"><?= $h($set['top_line']) ?></div><?php endif; ?>
      <div class="bpc-title" data-fit="2"><?= $h($set['title']) ?></div>
    </div>
    <div class="bpc-tag">🚌 BUS PASS<b><?= $h($p['pass_no']) ?></b></div>
  </div>
  <div class="bpc-body">
    <div class="bpc-photo"><?php if ($photo): ?><img src="<?= $h($rootPath . $photo) ?>" alt=""><?php else: ?><span><?= $h($initials) ?></span><?php endif; ?></div>
    <div class="bpc-main">
      <div class="bpc-name" data-fit="1"><?= $h($p['name']) ?></div>
      <div class="bpc-sub" data-fit="1"><?= $cls !== '' ? $h($cls) . ' · ' : '' ?>Gr.No. <?= $h($p['admission_no']) ?></div>
      <div class="bpc-route">
        <div class="bpc-stop"><span>FROM</span><b data-fit="2"><?= $h($p['from_place']) ?></b></div>
        <div class="bpc-line"><em>🚌</em></div>
        <div class="bpc-stop bpc-to"><span>TO</span><b data-fit="2"><?= $h($p['to_place']) ?></b></div>
      </div>
      <?php if (($p['route_name'] ?? '') !== ''): ?><div class="bpc-rt" data-fit="1">Route: <?= $h($p['route_name']) ?></div><?php endif; ?>
      <div class="bpc-valid"><span>VALID</span><b><?= $h($d($p['valid_from'])) ?></b><span>TO</span><b class="till"><?= $h($d($p['valid_till'])) ?></b></div>
    </div>
    <div class="bpc-side">
      <div class="bpc-qr"><?= idCardQrSvg(busPassVerifyUrl($p['organization_id'], $p['id'], $p['valid_till'])) ?><div>SCAN</div></div>
      <div class="bpc-sign"><?php if ($set['sign']): ?><img src="<?= $h($rootPath . $set['sign']) ?>" alt=""><?php endif; ?><div><?= $h($set['sign_label']) ?></div></div>
    </div>
  </div>
  <?php if (in_array($state, ['expired', 'cancelled'], true)): ?><div class="bpc-stamp"><?= $state === 'expired' ? 'EXPIRED' : 'CANCELLED' ?></div><?php endif; ?>
</div>
<?php return ob_get_clean();
}
