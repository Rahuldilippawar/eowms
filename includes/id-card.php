<?php
/**
 * 🪪 कर्मचारी ID Card — एकच Renderer (एकट्याचं Card आणि Bulk दोन्हीसाठी).
 *  - Front: संस्था, फोटो, नाव, पद, विभाग, Employee ID, सुरक्षित QR
 *  - Back: Emergency Contact, संस्थेचा पत्ता, नियम, Barcode (Gate Scanner), मोठा QR
 *  - QR मध्ये Signature (HMAC) — बनावट/बदललेलं Card Verify पानावर लगेच ओळखता येतं
 *  - QR व Barcode Server वरच तयार होतात (बाहेरची Website नाही)
 */
require_once __DIR__ . '/app-settings.php';

/** Verify-link चं Signature — config/app-secret.php वरून; Code बदलला तर जुळत नाही */
function idCardSig($orgId, $code) {
    return substr(hash_hmac('sha256', 'idcard|' . (int)$orgId . '|' . (string)$code, appSecret()), 0, 12);
}

function idCardVerifyUrl($orgId, $code) {
    $base = siteBaseUrl();
    if (!empty($_SERVER['HTTP_HOST'])) { // चालू Site (Local/Live) चाच पत्ता
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        $base = ($https ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . rtrim(dirname(dirname($_SERVER['SCRIPT_NAME'])), '/') . '/';
    }
    return $base . 'staff/verify-id.php?org=' . (int)$orgId . '&code=' . rawurlencode((string)$code) . '&s=' . idCardSig($orgId, $code);
}

/** QR (SVG) — lib/ मधली php-qrcode */
function idCardQrSvg($text) {
    require_once __DIR__ . '/../lib/vendor/autoload.php';
    $o = new \chillerlan\QRCode\QROptions([
        'outputType' => \chillerlan\QRCode\Output\QROutputInterface::MARKUP_SVG,
        'outputBase64' => false, 'eccLevel' => \chillerlan\QRCode\Common\EccLevel::M,
        'addQuietzone' => false, 'drawLightModules' => false, 'svgViewBoxSize' => null,
    ]);
    return (new \chillerlan\QRCode\QRCode($o))->render($text);
}

/** Code 39 Barcode (SVG) — Gate वरचे साधे Barcode Scanners सुद्धा वाचतात */
function idCardBarcodeSvg($text) {
    $map = ['0' => 'nnnwwnwnn', '1' => 'wnnwnnnnw', '2' => 'nnwwnnnnw', '3' => 'wnwwnnnnn', '4' => 'nnnwwnnnw', '5' => 'wnnwwnnnn', '6' => 'nnwwwnnnn', '7' => 'nnnwnnwnw', '8' => 'wnnwnnwnn', '9' => 'nnwwnnwnn',
        'A' => 'wnnnnwnnw', 'B' => 'nnwnnwnnw', 'C' => 'wnwnnwnnn', 'D' => 'nnnnwwnnw', 'E' => 'wnnnwwnnn', 'F' => 'nnwnwwnnn', 'G' => 'nnnnnwwnw', 'H' => 'wnnnnwwnn', 'I' => 'nnwnnwwnn', 'J' => 'nnnnwwwnn',
        'K' => 'wnnnnnnww', 'L' => 'nnwnnnnww', 'M' => 'wnwnnnnwn', 'N' => 'nnnnwnnww', 'O' => 'wnnnwnnwn', 'P' => 'nnwnwnnwn', 'Q' => 'nnnnnnwww', 'R' => 'wnnnnnwwn', 'S' => 'nnwnnnwwn', 'T' => 'nnnnwnwwn',
        'U' => 'wwnnnnnnw', 'V' => 'nwwnnnnnw', 'W' => 'wwwnnnnnn', 'X' => 'nwnnwnnnw', 'Y' => 'wwnnwnnnn', 'Z' => 'nwwnwnnnn', '-' => 'nwnnnnwnw', '.' => 'wwnnnnwnn', ' ' => 'nwwnnnwnn', '*' => 'nwnnwnwnn',
        '$' => 'nwnwnwnnn', '/' => 'nwnwnnnwn', '+' => 'nwnnnwnwn', '%' => 'nnnwnwnwn'];
    $text = strtoupper(preg_replace('/[^A-Za-z0-9\-. $\/+%]/', '', (string)$text));
    if ($text === '') { return ''; }
    $x = 0; $bars = '';
    foreach (str_split('*' . $text . '*') as $ch) {
        foreach (str_split($map[$ch]) as $i => $w) {
            $width = $w === 'w' ? 3 : 1;
            if ($i % 2 === 0) { $bars .= '<rect x="' . $x . '" y="0" width="' . $width . '" height="40"/>'; }
            $x += $width;
        }
        $x += 1; // अक्षरांमधली जागा
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $x . ' 40" preserveAspectRatio="none" class="idc-barcode-svg">' . $bars . '</svg>';
}

/** Card साठी लागणारी माहिती एकत्र — $emp = users + dept_name, campus_name, campus_address, campus_city */
function idCardData(array $emp, array $org) {
    $code = $emp['employee_code'] ?: ('ID-' . $emp['id']);
    return [
        'id' => (int)$emp['id'],
        'code' => $code,
        'name' => $emp['name'],
        'designation' => $emp['designation'] ?: '—',
        'dept' => $emp['dept_name'] ?: '—',
        'campus' => $emp['campus_name'] ?: ($org['name'] ?? ''),
        'branch' => trim(implode(', ', array_filter([$emp['campus_address'] ?? null, $emp['campus_city'] ?? null]))),
        'joining' => !empty($emp['joining_date']) ? date('d.m.Y', strtotime($emp['joining_date'])) : '—',
        'mobile' => $emp['mobile'] ?: '—',
        'dob' => !empty($emp['date_of_birth']) ? date('d.m.Y', strtotime($emp['date_of_birth'])) : '—',
        'issued' => date('d.m.Y'),
        'photo' => $emp['photo_path'] ?? '',
        'emName' => $emp['emergency_contact_name'] ?? '',
        'emRel' => $emp['emergency_contact_relation'] ?? '',
        'emMobile' => $emp['emergency_contact_mobile'] ?? '',
        'active' => (int)($emp['is_active'] ?? 1) === 1,
        'org' => $org['name'] ?? '',
        'orgLogo' => $org['logo_path'] ?? '',
        'orgPhone' => $org['contact_phone'] ?? '',
        'orgEmail' => $org['contact_email'] ?? '',
        'verify' => idCardVerifyUrl($org['id'] ?? 0, $code),
    ];
}

/** एका Card ची Front बाजू */
function idCardFront(array $c, $rootPath = '../') {
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    $initials = mb_strtoupper(mb_substr(trim($c['name']), 0, 1) . (preg_match('/\s(\S)/u', trim($c['name']), $m) ? $m[1] : ''));
    ob_start(); ?>
<div class="idc idc-front<?= $c['active'] ? '' : ' idc-inactive' ?>">
  <div class="idc-head">
    <div class="idc-slot"></div>
    <div class="idc-logo"><?php if ($c['orgLogo']): ?><img src="<?= $h($rootPath . $c['orgLogo']) ?>" alt=""><?php else: ?><span><?= $h(mb_substr($c['org'], 0, 2)) ?></span><?php endif; ?></div>
    <div class="idc-mantra">॥ आत्मा मालिक ॥</div>
    <div class="idc-org"><?= $h($c['org']) ?></div>
    <div class="idc-campus"><?= $h($c['campus']) ?></div>
    <?php if ($c['branch']): ?><div class="idc-branch"><?= $h($c['branch']) ?></div><?php endif; ?>
  </div>
  <div class="idc-photo">
    <?php if ($c['photo']): ?><img src="<?= $h($rootPath . $c['photo']) ?>" alt=""><?php else: ?><span><?= $h($initials) ?></span><?php endif; ?>
  </div>
  <div class="idc-name"><?= $h($c['name']) ?></div>
  <div class="idc-desig"><?= $h($c['designation']) ?></div>
  <div class="idc-info">
    <div class="idc-rows">
      <div><span>Dept.</span><b><?= $h($c['dept']) ?></b></div>
      <div><span>Emp. ID</span><b class="idc-mono"><?= $h($c['code']) ?></b></div>
      <div><span>Joining</span><b><?= $h($c['joining']) ?></b></div>
      <div><span>Mobile</span><b><?= $h($c['mobile']) ?></b></div>
    </div>
    <div class="idc-qr" title="Scan to verify"><?= idCardQrSvg($c['verify']) ?><div>SCAN · VERIFY</div></div>
  </div>
  <div class="idc-sign"><div class="idc-sign-line"></div>Authorized Signatory</div>
  <div class="idc-band"><i></i><i></i><i></i></div>
</div>
<?php return ob_get_clean();
}

/** एका Card ची Back बाजू */
function idCardBack(array $c, $rootPath = '../') {
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    ob_start(); ?>
<div class="idc idc-back">
  <div class="idc-slot idc-slot-dark"></div>
  <div class="idc-back-head">
    <div class="idc-logo idc-logo-sm"><?php if ($c['orgLogo']): ?><img src="<?= $h($rootPath . $c['orgLogo']) ?>" alt=""><?php else: ?><span><?= $h(mb_substr($c['org'], 0, 2)) ?></span><?php endif; ?></div>
    <div><div class="idc-back-org"><?= $h($c['org']) ?></div><div class="idc-back-sub">Employee Identity Card</div></div>
  </div>
  <div class="idc-box">
    <div class="idc-box-t">🚑 Emergency Contact</div>
    <?php if ($c['emName'] || $c['emMobile']): ?>
      <div class="idc-box-v"><b><?= $h($c['emName'] ?: '—') ?></b><?= $c['emRel'] ? ' <span>(' . $h($c['emRel']) . ')</span>' : '' ?></div>
      <div class="idc-box-v idc-mono"><?= $h($c['emMobile'] ?: '—') ?></div>
    <?php else: ?>
      <div class="idc-box-v idc-blank">Name: ______________________</div>
      <div class="idc-box-v idc-blank">Mobile: _____________________</div>
    <?php endif; ?>
  </div>
  <div class="idc-meta">
    <div><span>Date of Birth</span><b><?= $h($c['dob']) ?></b></div>
    <div><span>Issued On</span><b><?= $h($c['issued']) ?></b></div>
  </div>
  <ul class="idc-rules">
    <li>हे ओळखपत्र नेहमी दर्शनी भागात परिधान करा.</li>
    <li>हे Card हस्तांतरणीय नाही — फक्त धारकासाठी.</li>
    <li>हरवल्यास त्वरित कार्यालयाला कळवा.</li>
    <li>सापडल्यास कृपया खालील पत्त्यावर परत करा.</li>
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
