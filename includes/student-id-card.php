<?php
/**
 * 🎓🪪 विद्यार्थी ID Card — कर्मचारी Card चंच Design (assets/css/id-card.css) + विद्यार्थ्यांसाठी माहिती.
 *  - Front: शाळा/कॉलेज, फोटो, नाव, इयत्ता-तुकडी, प्रवेश क्र., Roll, जन्मतारीख, रक्तगट, सुरक्षित QR
 *  - Back: पालकांचे नाव व मोबाईल, पत्ता, वैधता (शैक्षणिक वर्ष), नियम, Barcode, मोठा QR
 *  - QR → students/verify.php — Signature (HMAC) जुळलं तरच माहिती दिसते (अल्पवयीन मुलांची माहिती सुरक्षित)
 */
require_once __DIR__ . '/id-card.php';

function studentCardSig($orgId, $id, $admissionNo) {
    return substr(hash_hmac('sha256', 'stucard|' . (int)$orgId . '|' . (int)$id . '|' . (string)$admissionNo, appSecret()), 0, 12);
}

function studentCardVerifyUrl($orgId, $id, $admissionNo) {
    $base = siteBaseUrl();
    if (!empty($_SERVER['HTTP_HOST'])) {
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $base = ($https ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';
    }
    return $base . 'students/verify.php?org=' . (int)$orgId . '&id=' . (int)$id . '&s=' . studentCardSig($orgId, $id, $admissionNo);
}

/** वैधता — शैक्षणिक वर्ष "2026-27" → 31.05.2027 */
function studentCardValidTill($academicYear) {
    if (preg_match('/^(\d{4})\s*[-\/]\s*(\d{2,4})$/', trim((string)$academicYear), $m)) {
        $end = strlen($m[2]) === 2 ? (int)(substr($m[1], 0, 2) . $m[2]) : (int)$m[2];
        if ($end > (int)$m[1] && $end - (int)$m[1] <= 2) return '31.05.' . $end;
    }
    return '—';
}

/** $s = students + campus_name, campus_address, campus_city, dept_name */
function studentCardData(array $s, array $org, array $set = []) {
    $class = trim(($s['class_name'] ?? '') . (($s['division'] ?? '') !== '' ? ' - ' . $s['division'] : ''));
    if ($class === '' && !empty($s['course'])) $class = $s['course'];
    return [
        'id' => (int)$s['id'],
        'code' => (string)$s['admission_no'],
        'name' => $s['name'],
        'class' => $class !== '' ? $class : '—',
        'rawClass' => (string)($s['class_name'] ?? ''),
        'division' => (string)($s['division'] ?? ''),
        'course' => (string)($s['course'] ?? ''),
        'roll' => ($s['roll_no'] ?? '') !== '' ? $s['roll_no'] : '—',
        'dob' => !empty($s['date_of_birth']) ? date('d.m.Y', strtotime($s['date_of_birth'])) : '—',
        'blood' => ($s['blood_group'] ?? '') !== '' ? $s['blood_group'] : '—',
        'year' => $s['academic_year'] ?? '',
        'valid' => studentCardValidTill($s['academic_year'] ?? ''),
        'father' => $s['father_name'] ?? '',
        'mother' => $s['mother_name'] ?? '',
        'pMobile' => $s['parent_mobile'] ?: ($s['mobile'] ?? ''),
        'address' => (string)($s['address'] ?? ''),
        'photo' => (!empty($s['photo_path']) && eowmsPhotoExistsSafe($s['photo_path'])) ? $s['photo_path'] : '',
        'active' => (int)($s['is_active'] ?? 1) === 1,
        'campus' => $s['campus_name'] ?: ($org['name'] ?? ''),
        'branch' => trim(implode(', ', array_filter([$s['campus_address'] ?? null, $s['campus_city'] ?? null]))),
        'org' => $org['name'] ?? '',
        'orgLogo' => $org['logo_path'] ?? '',
        'orgPhone' => $org['contact_phone'] ?? '',
        'orgEmail' => $org['contact_email'] ?? '',
        'issued' => date('d.m.Y'),
        'verify' => studentCardVerifyUrl($org['id'] ?? 0, $s['id'], $s['admission_no']),
        'contact' => $s['parent_mobile'] ?: ($s['mobile'] ?? ''),
        'set' => $set ?: studentCardDefaults(['name' => $s['campus_name'] ?? ''], $org),
    ];
}

/** College/School नुसार Card Design — जतन केलेलं + रिकाम्याला मूळ (Default) */
function studentCardDefaults(array $campus, array $org, array $saved = []) {
    $logo = $saved['logo_path'] ?? '';
    if (!$logo || !eowmsPhotoExistsSafe($logo)) $logo = (!empty($campus['letterhead_logo_path']) && eowmsPhotoExistsSafe($campus['letterhead_logo_path'])) ? $campus['letterhead_logo_path'] : ($org['logo_path'] ?? '');
    $sign = $saved['signature_path'] ?? '';
    $color = $saved['color'] ?? '';
    return [
        'top_line' => ($saved['top_line'] ?? '') !== '' ? $saved['top_line'] : '|| Atma Malik ||',
        'title' => ($saved['title'] ?? '') !== '' ? $saved['title'] : mb_strtoupper((string)($campus['name'] ?? ($org['name'] ?? ''))),
        'subtitle' => $saved['subtitle'] ?? '',
        'logo' => $logo,
        'sign' => $sign && eowmsPhotoExistsSafe($sign) ? $sign : '',
        'sign_label' => ($saved['sign_label'] ?? '') !== '' ? $saved['sign_label'] : 'PRINCIPAL',
        'color' => preg_match('/^#[0-9a-f]{6}$/i', $color) ? $color : '#3769AC',
        'show_qr' => !isset($saved['show_qr']) || (int)$saved['show_qr'] === 1,
        'show_blood' => !isset($saved['show_blood']) || (int)$saved['show_blood'] === 1,
    ];
}

/** [campus_id => design] — सगळ्या College/School चे */
function studentCardSettingsAll(PDO $pdo, $orgId, array $org) {
    $saved = [];
    try {
        $st = $pdo->prepare("SELECT * FROM student_card_settings WHERE organization_id = ?");
        $st->execute([$orgId]);
        foreach ($st->fetchAll() as $r) $saved[(int)$r['campus_id']] = $r;
    } catch (PDOException $e) {}
    $st = $pdo->prepare("SELECT * FROM campuses WHERE organization_id = ?");
    $st->execute([$orgId]);
    $out = [];
    foreach ($st->fetchAll() as $c) $out[(int)$c['id']] = studentCardDefaults($c, $org, $saved[(int)$c['id']] ?? []);
    return $out;
}

/** 🎨 नमुन्याप्रमाणे Design (एकाच बाजूचं) — निळी चौकट, संस्थेचं नाव, मोठा Logo, फोटो, Class/Branch/DOB/Gr.No., पत्ता, Contact, Principal सही */
function studentCardClassic(array $c, $rootPath = '../') {
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    $set = $c['set'];
    $initials = mb_strtoupper(mb_substr(trim($c['name']), 0, 1) . (preg_match('/\s(\S)/u', trim($c['name']), $m) ? $m[1] : ''));
    $cls = trim(($c['rawClass'] ?? '') !== '' ? $c['rawClass'] : $c['class']);
    ob_start(); ?>
<div class="sic<?= $c['active'] ? '' : ' idc-inactive' ?><?= $set['logo'] ? '' : ' sic-nologo' ?>" style="--sc:<?= $h($set['color']) ?>;" data-sid="<?= (int)$c['id'] ?>">
  <div class="sic-head">
    <?php if ($set['top_line'] !== ''): ?><div class="sic-top"><?= $h($set['top_line']) ?></div><?php endif; ?>
    <div class="sic-title" data-fit="3"><?= $h($set['title']) ?></div>
    <?php if ($set['subtitle'] !== ''): ?><div class="sic-sub"><?= $h($set['subtitle']) ?></div><?php endif; ?>
  </div>
  <?php if ($set['logo']): ?><div class="sic-logo"><img src="<?= $h($rootPath . $set['logo']) ?>" alt=""></div><?php endif; ?>
  <div class="sic-mid">
    <div class="sic-side"><?php if ($set['show_blood'] && $c['blood'] !== '—'): ?><div class="sic-blood" title="Blood Group"><svg viewBox="0 0 20 26" aria-hidden="true"><path d="M10 0C10 0 0 11 0 17a10 9 0 0 0 20 0C20 11 10 0 10 0z"/></svg><b><?= $h($c['blood']) ?></b></div><?php endif; ?></div>
    <div class="sic-photo"><?php if ($c['photo']): ?><img src="<?= $h($rootPath . $c['photo']) ?>" alt=""><?php else: ?><span><?= $h($initials) ?></span><?php endif; ?></div>
    <div class="sic-side"><?php if ($set['show_qr']): ?><div class="sic-qr"><?= idCardQrSvg($c['verify']) ?><div>SCAN</div></div><?php endif; ?></div>
  </div>
  <div class="sic-name" data-fit="2"><?= $h($c['name']) ?></div>
  <div class="sic-grid">
    <div><span>Class :</span><b data-fit="1"><?= $h($cls) ?></b></div>
    <?php if ($c['course'] !== '' || $c['division'] === ''): ?><div><span>Branch :</span><b data-fit="1"><?= $h($c['course'] !== '' ? $c['course'] : '—') ?></b></div>
    <?php else: ?><div><span>Div. :</span><b><?= $h($c['division']) ?></b></div><?php endif; ?>
    <div><span>DOB :</span><b><?= $h($c['dob']) ?></b></div>
    <div><span>Gr.No.:</span><b><?= $h($c['code']) ?></b></div>
  </div>
  <div class="sic-addr">
    <svg viewBox="0 0 24 32" aria-hidden="true"><path d="M12 0C5.4 0 0 5.2 0 11.7 0 20.5 12 32 12 32s12-11.5 12-20.3C24 5.2 18.6 0 12 0z" fill="#E8503A"/><circle cx="12" cy="11.5" r="4.6" fill="#fff"/><ellipse cx="12" cy="30" rx="8" ry="2" fill="#3FA34D" opacity=".85"/></svg>
    <div data-fit="2"><?= $h($c['address'] !== '' ? $c['address'] : '—') ?></div>
  </div>
  <div class="sic-foot">
    <div class="sic-contact"><span>Contact No.:</span> <b data-fit="1"><?= $h($c['contact'] !== '' ? $c['contact'] : '—') ?></b></div>
    <div class="sic-sign"><?php if ($set['sign']): ?><img src="<?= $h($rootPath . $set['sign']) ?>" alt=""><?php endif; ?><div><?= $h($set['sign_label']) ?></div></div>
  </div>
</div>
<?php return ob_get_clean();
}

function eowmsPhotoExistsSafe($path) {
    if (!function_exists('eowmsPhotoExists')) require_once __DIR__ . '/photo-helpers.php';
    return eowmsPhotoExists($path);
}

function studentCardFront(array $c, $rootPath = '../') {
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    $initials = mb_strtoupper(mb_substr(trim($c['name']), 0, 1) . (preg_match('/\s(\S)/u', trim($c['name']), $m) ? $m[1] : ''));
    ob_start(); ?>
<div class="idc idc-front idc-stu<?= $c['active'] ? '' : ' idc-inactive' ?>">
  <div class="idc-head">
    <div class="idc-slot"></div>
    <div class="idc-logo"><?php if ($c['orgLogo']): ?><img src="<?= $h($rootPath . $c['orgLogo']) ?>" alt=""><?php else: ?><span><?= $h(mb_substr($c['org'], 0, 2)) ?></span><?php endif; ?></div>
    <div class="idc-mantra">॥ आत्मा मालिक ॥</div>
    <div class="idc-org" data-fit="2" data-fit-min="0.7"><?= $h($c['org']) ?></div>
    <div class="idc-campus" data-fit="2" data-fit-min="0.55"><?= $h($c['campus']) ?></div>
    <?php if ($c['branch']): ?><div class="idc-branch"><?= $h($c['branch']) ?></div><?php endif; ?>
  </div>
  <div class="idc-photo">
    <?php if ($c['photo']): ?><img src="<?= $h($rootPath . $c['photo']) ?>" alt=""><?php else: ?><span><?= $h($initials) ?></span><?php endif; ?>
  </div>
  <div class="idc-name"><?= $h($c['name']) ?></div>
  <div class="idc-desig">STUDENT · <?= $h($c['class']) ?></div>
  <div class="idc-info">
    <div class="idc-rows">
      <div><span>Adm. No</span><b class="idc-mono"><?= $h($c['code']) ?></b></div>
      <div><span>Roll No</span><b><?= $h($c['roll']) ?></b></div>
      <div><span>D.O.B.</span><b><?= $h($c['dob']) ?></b></div>
      <div><span>Blood</span><b><?= $h($c['blood']) ?></b></div>
    </div>
    <div class="idc-qr" title="Scan to verify"><?= idCardQrSvg($c['verify']) ?><div>SCAN · VERIFY</div></div>
  </div>
  <div class="idc-sign"><div class="idc-sign-line"></div>Principal</div>
  <div class="idc-year"><?= $h($c['year']) ?></div>
  <div class="idc-band"><i></i><i></i><i></i></div>
</div>
<?php return ob_get_clean();
}

function studentCardBack(array $c, $rootPath = '../') {
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    ob_start(); ?>
<div class="idc idc-back idc-stu">
  <div class="idc-slot idc-slot-dark"></div>
  <div class="idc-back-head">
    <div class="idc-logo idc-logo-sm"><?php if ($c['orgLogo']): ?><img src="<?= $h($rootPath . $c['orgLogo']) ?>" alt=""><?php else: ?><span><?= $h(mb_substr($c['org'], 0, 2)) ?></span><?php endif; ?></div>
    <div><div class="idc-back-org"><?= $h($c['campus']) ?></div><div class="idc-back-sub">Student Identity Card</div></div>
  </div>
  <div class="idc-box">
    <div class="idc-box-t">👪 Parent / Guardian</div>
    <?php if ($c['father'] || $c['mother'] || $c['pMobile']): ?>
      <div class="idc-box-v"><b><?= $h($c['father'] ?: $c['mother'] ?: '—') ?></b></div>
      <div class="idc-box-v idc-mono">☎ <?= $h($c['pMobile'] ?: '—') ?></div>
    <?php else: ?>
      <div class="idc-box-v idc-blank">Name: ______________________</div>
      <div class="idc-box-v idc-blank">Mobile: _____________________</div>
    <?php endif; ?>
  </div>
  <?php if ($c['address']): ?><div class="idc-addr"><b>Address:</b> <?= $h($c['address']) ?></div><?php endif; ?>
  <div class="idc-meta">
    <div><span>Valid Till</span><b><?= $h($c['valid']) ?></b></div>
    <div><span>Issued On</span><b><?= $h($c['issued']) ?></b></div>
  </div>
  <ul class="idc-rules">
    <li>शाळा/कॉलेजमध्ये हे ओळखपत्र नेहमी परिधान करा.</li>
    <li>हे Card हस्तांतरणीय नाही.</li>
    <li>हरवल्यास त्वरित कार्यालयाला कळवा.</li>
  </ul>
  <div class="idc-qr-big"><?= idCardQrSvg($c['verify']) ?><div>SCAN TO VERIFY</div></div>
  <div class="idc-barcode"><?= idCardBarcodeSvg($c['code']) ?><div class="idc-mono"><?= $h($c['code']) ?></div></div>
  <div class="idc-return">
    <b><?= $h($c['campus']) ?></b>
    <?php if ($c['branch']): ?><div><?= $h($c['branch']) ?></div><?php endif; ?>
    <?php if ($c['orgPhone'] || $c['orgEmail']): ?><div><?= $h(implode(' · ', array_filter([$c['orgPhone'] ? '☎ ' . $c['orgPhone'] : '', $c['orgEmail']]))) ?></div><?php endif; ?>
  </div>
  <div class="idc-band"><i></i><i></i><i></i></div>
</div>
<?php return ob_get_clean();
}
