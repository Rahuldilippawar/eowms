<?php
/**
 * 📄 अहवाल PDF — Smart भर (दैनिक, साप्ताहिक, E-Campus — सगळे mgmtReportPdf() मधून)
 *  - पहिल्या पानावर "या अहवालात" अनुक्रमणिका (Click → तो विभाग) + PDF Bookmarks (बाजूची यादी)
 *  - पान 2 पासून वर संस्था · अहवाल · दिनांक; खाली "गोपनीय" + पान क्रमांक
 *  - संस्थेचा Logo आणि Online अहवाल उघडणारा QR Code
 *  - "कोणाकडे काय प्रलंबित" — व्यक्तीनिहाय जबाबदारी तक्ता
 */
require_once __DIR__ . '/app-settings.php';

/** Online अहवालाचा पूर्ण पत्ता (Cron मधूनही) — पत्ता माहीत नसेल तर '' */
function mgmtReportLink($path) {
    $base = function_exists('siteBaseUrl') ? siteBaseUrl() : '';
    return $base ? $base . ltrim($path, '/') : '';
}

/** QR (SVG → <img>) */
function mgmtReportQrImg($url, $mm = 19) {
    if (!$url) { return ''; }
    try {
        require_once __DIR__ . '/../lib/vendor/autoload.php';
        $o = new \chillerlan\QRCode\QROptions([
            'outputType' => \chillerlan\QRCode\Output\QROutputInterface::MARKUP_SVG,
            'outputBase64' => false, 'eccLevel' => \chillerlan\QRCode\Common\EccLevel::M,
            'addQuietzone' => true, 'quietzoneSize' => 1, 'drawLightModules' => false, 'svgViewBoxSize' => null,
        ]);
        $svg = (new \chillerlan\QRCode\QRCode($o))->render($url);
        return '<img src="data:image/svg+xml;base64,' . base64_encode($svg) . '" style="width:' . $mm . 'mm;height:' . $mm . 'mm;background:#fff;">';
    } catch (Throwable $e) { return ''; }
}

/** संस्थेचा Logo (data URI) — नसेल / वाचता आला नाही तर '' */
function mgmtReportLogo(PDO $pdo, $orgId) {
    try {
        $st = $pdo->prepare("SELECT logo_path FROM organizations WHERE id = ?");
        $st->execute([(int)$orgId]);
        $p = (string)$st->fetchColumn();
        if ($p === '') { return ''; }
        $file = realpath(__DIR__ . '/../' . ltrim($p, '/'));
        $root = realpath(__DIR__ . '/../assets');
        if (!$file || !$root || strpos($file, $root . DIRECTORY_SEPARATOR) !== 0 || filesize($file) > 1500000) { return ''; }
        $mime = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif'][strtolower(pathinfo($file, PATHINFO_EXTENSION))] ?? '';
        if (!$mime || $mime === 'image/webp') { return ''; }   // mPDF ला WebP नीट जमत नाही
        return 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($file));
    } catch (Throwable $e) { return ''; }
}

/**
 * HTML ला Smart भर: <h2> ना IDs, पहिल्या पानावर अनुक्रमणिका, Logo + QR (Header Block च्या उजवीकडे).
 * @param array $o ['link' => Online पत्ता, 'logo' => data URI, 'index' => bool]
 */
function mgmtReportDecorate($html, array $o = []) {
    // 1) विभाग शीर्षकांना IDs + अनुक्रमणिकेची यादी
    $toc = [];
    $i = 0;
    $html = preg_replace_callback('/<h2(\s[^>]*)?>(.*?)<\/h2>/su', function ($m) use (&$toc, &$i) {
        $i++;
        $text = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags(preg_replace('/<span class="h2s">.*?<\/span>/su', '', $m[2])), ENT_QUOTES, 'UTF-8')));
        $text = preg_replace('/^\d+\s+/u', '', $text);
        $toc[] = [$i, $text];
        return '<a name="sec' . $i . '"></a><h2' . ($m[1] ?? '') . '>' . $m[2] . '</h2>';
    }, $html);

    $hdrEnd = null;
    if (preg_match('/<div class="hdr">(?:\s*<div[^>]*>.*?<\/div>)*\s*<\/div>/su', $html, $hm, PREG_OFFSET_CAPTURE)) {
        $hdrEnd = $hm[0][1] + strlen($hm[0][0]);
    }

    // 2) Logo + QR — Header च्या उजव्या कोपऱ्यात (फक्त पहिलं पान)
    $side = '';
    $mm = (int)($o['size'] ?? 19);
    if (!empty($o['logo'])) { $side .= '<img src="' . $o['logo'] . '" style="height:' . max(10, $mm - 4) . 'mm;max-width:30mm;background:#fff;padding:1mm;margin-right:2mm;">'; }
    $qr = mgmtReportQrImg($o['link'] ?? '', $mm);
    if ($qr) { $side .= $qr; }
    if ($side && $hdrEnd !== null) {
        $box = '<div style="position:absolute;top:' . ($mm >= 16 ? 11 : 10.6) . 'mm;right:13mm;text-align:right;">' . $side
            . ($qr && $mm >= 16 ? '<div style="font-size:6.5pt;color:#C9CCE0;text-align:right;margin-top:0.5mm;">Scan → Online अहवाल</div>' : '') . '</div>';
        $html = substr($html, 0, $hdrEnd) . $box . substr($html, $hdrEnd);
        $hdrEnd += strlen($box);
    }

    // 3) अनुक्रमणिका
    if (($o['index'] ?? true) && count($toc) >= 3 && $hdrEnd !== null) {
        $chips = [];
        foreach ($toc as [$n, $t]) {
            $chips[] = '<a href="#sec' . $n . '" style="color:#191C3A;text-decoration:none;"><span style="color:#D9530F;font-weight:bold;">›</span> ' . htmlspecialchars(mb_strimwidth($t, 0, 38, '…'), ENT_QUOTES, 'UTF-8') . '</a>';
        }
        $idx = '<div style="margin-top:5px;padding:4px 8px;background:#FBF8F4;border:1px solid #EAE5DF;font-size:7.8pt;color:#64708A;">'
            . '<b style="color:#191C3A;">या अहवालात:</b> &nbsp;' . implode(' &nbsp;&nbsp; ', $chips) . '</div>';
        $html = substr($html, 0, $hdrEnd) . $idx . substr($html, $hdrEnd);
    }
    return $html;
}

/* ======================= 👤 कोणाकडे काय प्रलंबित ======================= */

/**
 * व्यक्तीनिहाय प्रलंबित — मुदतबाह्य/उघडे Tasks, हातात असलेली पत्रं (किती दिवस), संपलेली नूतनीकरणं.
 * परत: [['name','dept','overdue','open','letters','letterDays','renewals','score'], …] — सर्वात जास्त जबाबदारी आधी
 */
function mgmtAccountability(PDO $pdo, $orgId, $asOf = null, $limit = 10) {
    $d = $asOf ?: date('Y-m-d');
    $rows = [];
    $add = function ($uid, $name, $dept, $k, $v) use (&$rows) {
        if (!$uid || !$v) { return; }
        $rows[$uid] = $rows[$uid] ?? ['name' => $name, 'dept' => $dept, 'overdue' => 0, 'open' => 0, 'letters' => 0, 'letterDays' => 0, 'renewals' => 0];
        if ($k === 'letterDays') { $rows[$uid][$k] = max($rows[$uid][$k], (int)$v); } else { $rows[$uid][$k] += (int)$v; }
    };
    $q = function ($sql, $p) use ($pdo) { try { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); } catch (PDOException $e) { return []; } };

    foreach ($q("SELECT u.id, u.name, d.name_local dept, SUM(t.target_date < ?) od, COUNT(*) op
        FROM tasks t JOIN users u ON u.id = t.assigned_to LEFT JOIN departments d ON d.id = u.department_id
        WHERE t.organization_id = ? AND t.status IN ('pending','in_progress') AND u.is_active = 1 GROUP BY u.id", [$d, $orgId]) as $r) {
        $add($r['id'], $r['name'], $r['dept'], 'overdue', $r['od']); $add($r['id'], $r['name'], $r['dept'], 'open', $r['op']);
    }
    foreach ($q("SELECT u.id, u.name, d.name_local dept, COUNT(*) c, MAX(DATEDIFF(?, COALESCE(l.updated_at, l.created_at))) days
        FROM letters l JOIN users u ON u.id = l.current_holder_id LEFT JOIN departments d ON d.id = u.department_id
        WHERE l.organization_id = ? AND l.status = 'in_progress' AND u.is_active = 1 GROUP BY u.id", [$d, $orgId]) as $r) {
        $add($r['id'], $r['name'], $r['dept'], 'letters', $r['c']); $add($r['id'], $r['name'], $r['dept'], 'letterDays', $r['days']);
    }
    foreach ($q("SELECT u.id, u.name, d.name_local dept, COUNT(*) c
        FROM renewal_items r JOIN users u ON u.id = r.responsible_user_id LEFT JOIN departments d ON d.id = u.department_id
        WHERE r.organization_id = ? AND r.status IN ('active','due_soon','expired') AND r.expiry_date < ? AND u.is_active = 1 GROUP BY u.id", [$orgId, $d]) as $r) {
        $add($r['id'], $r['name'], $r['dept'], 'renewals', $r['c']);
    }
    foreach ($rows as &$r) { $r['score'] = $r['overdue'] * 3 + $r['letters'] * 2 + min(30, $r['letterDays']) / 3 + $r['renewals'] * 2 + $r['open'] * 0.5; }
    unset($r);
    $rows = array_filter($rows, fn($r) => $r['overdue'] || $r['letters'] || $r['renewals']);
    usort($rows, fn($a, $b) => $b['score'] <=> $a['score']);
    return array_slice($rows, 0, $limit);
}

/** तक्ता (PDF) — $title व रिकामी असल्यास काहीच नाही */
function mgmtAccountabilityHtml(array $rows, $title = 'कोणाकडे काय प्रलंबित') {
    if (!$rows) { return ''; }
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    $red = fn($v) => $v ? '<b style="color:#B42318;">' . (int)$v . '</b>' : '<span style="color:#B8BCCB;">0</span>';
    $out = '<h2>' . $title . ' <span class="h2s">मुदतबाह्य कामं, हातात अडकलेली पत्रं व संपलेली नूतनीकरणं — सर्वात जास्त आधी</span></h2>'
        . '<table class="g"><thead><tr><th style="width:26%;">व्यक्ती</th><th style="width:20%;">विभाग</th><th class="num-r">मुदतबाह्य Tasks</th><th class="num-r">उघडे Tasks</th>'
        . '<th class="num-r">हातातील पत्रं</th><th class="num-r">सर्वात जुने पत्र</th><th class="num-r">संपलेली नूतनीकरणं</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $out .= '<tr><td><b>' . $h($r['name']) . '</b></td><td>' . $h($r['dept'] ?: '—') . '</td><td class="num-r">' . $red($r['overdue']) . '</td><td class="num-r">' . (int)$r['open'] . '</td>'
            . '<td class="num-r">' . ($r['letters'] ? '<b>' . (int)$r['letters'] . '</b>' : '<span style="color:#B8BCCB;">0</span>') . '</td>'
            . '<td class="num-r">' . ($r['letters'] ? '<span style="color:' . ($r['letterDays'] > 7 ? '#B42318' : '#B45309') . ';">' . (int)$r['letterDays'] . ' दिवस</span>' : '—') . '</td>'
            . '<td class="num-r">' . $red($r['renewals']) . '</td></tr>';
    }
    return $out . '</tbody></table>';
}
