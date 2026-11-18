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

/** महिन्यांची पट्टी — शैक्षणिक वर्ष (जून → मे); पास ज्या महिन्यांत valid ते रंगीत. Conductor ला एका नजरेत कळतं. */
function busPassMonths($from, $till) {
    $f = strtotime($from); $t = strtotime($till);
    $y = (int)date('Y', $f); if ((int)date('n', $f) < 6) $y--;
    $cells = [];
    for ($i = 0; $i < 12; $i++) {
        $ms = mktime(0, 0, 0, 6 + $i, 1, $y); $me = mktime(0, 0, 0, 7 + $i, 0, $y);
        $cells[] = ['m' => date('M', $ms), 'on' => $me >= $f && $ms <= $t, 'now' => date('Y-m') === date('Y-m', $ms)];
    }
    return $cells;
}

/** एक बस पास (Card) — $p = pass + student fields (name, class_name, division, course, admission_no, photo_path, campus_name, parent_mobile, mobile) */
function busPassCard(array $p, array $set, $rootPath = '../') {
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    $state = busPassState($p);
    $cls = trim(($p['class_name'] ?? '') . (($p['division'] ?? '') !== '' ? ' - ' . $p['division'] : '') . (($p['course'] ?? '') !== '' ? ' · ' . $p['course'] : ''));
    $photo = (!empty($p['photo_path']) && eowmsPhotoExistsSafe($p['photo_path'])) ? $p['photo_path'] : '';
    $initials = mb_strtoupper(mb_substr(trim($p['name']), 0, 1) . (preg_match('/\s(\S)/u', trim($p['name']), $m) ? $m[1] : ''));
    $d = fn($x) => date('d.m.Y', strtotime($x));
    $phone = ($p['parent_mobile'] ?? '') ?: ($p['mobile'] ?? '');
    ob_start(); ?>
<div class="bpc bpc-<?= $h($state) ?>" style="--sc:<?= $h($set['color']) ?>;" data-pid="<?= (int)$p['id'] ?>">
  <?php if ($set['logo']): ?><div class="bpc-wm"><img src="<?= $h($rootPath . $set['logo']) ?>" alt=""></div><?php endif; ?>
  <div class="bpc-head">
    <div class="bpc-logo"><?php if ($set['logo']): ?><img src="<?= $h($rootPath . $set['logo']) ?>" alt=""><?php else: ?><span><?= $h(mb_substr($set['title'], 0, 2)) ?></span><?php endif; ?></div>
    <div class="bpc-org">
      <?php if ($set['top_line'] !== ''): ?><div class="bpc-top"><?= $h($set['top_line']) ?></div><?php endif; ?>
      <div class="bpc-title" data-fit="2"><?= $h($set['title']) ?></div>
      <?php if (($set['subtitle'] ?? '') !== ''): ?><div class="bpc-subt"><?= $h($set['subtitle']) ?></div><?php endif; ?>
    </div>
    <div class="bpc-tag"><div class="bpc-tag-t"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 16c0 .9.4 1.7 1 2.2V20a1 1 0 0 0 1 1h1a1 1 0 0 0 1-1v-1h8v1a1 1 0 0 0 1 1h1a1 1 0 0 0 1-1v-1.8c.6-.5 1-1.3 1-2.2V6c0-3.5-3.6-4-8-4S4 2.5 4 6v10zm3.5 1A1.5 1.5 0 1 1 9 15.5 1.5 1.5 0 0 1 7.5 17zm9 0a1.5 1.5 0 1 1 1.5-1.5 1.5 1.5 0 0 1-1.5 1.5zM18 11H6V6h12v5z"/></svg>BUS PASS</div><b><?= $h($p['pass_no']) ?></b></div>
  </div>
  <div class="bpc-body">
    <div class="bpc-left">
      <div class="bpc-photo"><?php if ($photo): ?><img src="<?= $h($rootPath . $photo) ?>" alt=""><?php else: ?><span><?= $h($initials) ?></span><?php endif; ?></div>
      <div class="bpc-gr">Gr. <?= $h($p['admission_no']) ?></div>
    </div>
    <div class="bpc-main">
      <div class="bpc-name" data-fit="1"><?= $h($p['name']) ?></div>
      <?php if ($cls !== ''): ?><div class="bpc-sub" data-fit="1"><?= $h($cls) ?></div><?php endif; ?>
      <div class="bpc-route">
        <div class="bpc-stop"><i class="dot"></i><span>FROM</span><b data-fit="2"><?= $h($p['from_place']) ?></b></div>
        <div class="bpc-stop bpc-to"><i class="dot"></i><span>TO</span><b data-fit="2"><?= $h($p['to_place']) ?></b></div>
      </div>
      <div class="bpc-months"><?php foreach (busPassMonths($p['valid_from'], $p['valid_till']) as $c): ?><i class="<?= $c['on'] ? 'on' : '' ?><?= $c['now'] ? ' now' : '' ?>"><?= $h($c['m']) ?></i><?php endforeach; ?></div>
      <div class="bpc-valid"><span>VALID</span><b><?= $h($d($p['valid_from'])) ?></b><span>→</span><b class="till"><?= $h($d($p['valid_till'])) ?></b></div>
    </div>
    <div class="bpc-side">
      <div class="bpc-qr"><?= idCardQrSvg(busPassVerifyUrl($p['organization_id'], $p['id'], $p['valid_till'])) ?><div>SCAN TO VERIFY</div></div>
      <div class="bpc-sign"><?php if ($set['sign']): ?><img src="<?= $h($rootPath . $set['sign']) ?>" alt=""><?php endif; ?><div><?= $h($set['sign_label']) ?></div></div>
    </div>
  </div>
  <div class="bpc-foot">
    <span data-fit="1"><?= ($p['route_name'] ?? '') !== '' ? '🛣️ ' . $h($p['route_name']) : '🛣️ ' . $h($p['from_place']) . ' – ' . $h($p['to_place']) ?></span>
    <?php if ($phone !== ''): ?><span class="ph">☎ Parent <?= $h($phone) ?></span><?php endif; ?>
  </div>
  <?php if (in_array($state, ['expired', 'cancelled'], true)): ?><div class="bpc-stamp"><?= $state === 'expired' ? 'EXPIRED' : 'CANCELLED' ?></div><?php endif; ?>
</div>
<?php return ob_get_clean();
}
