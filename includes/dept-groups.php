<?php
/**
 * 🏛️ विभाग → संस्था / College / School गट
 * संपूर्ण App मधल्या प्रत्येक "विभाग निवडा" Dropdown मध्ये विभाग त्यांच्या Campus खाली गटवार दिसावेत:
 *   🏛️ संस्था-स्तरीय (Trust HQ / Campus नसलेले) → 🎓 महाविद्यालयं → 🏫 शाळा → 🏢 इतर
 * footer.php हा नकाशा (id → गट) पानावर देतो; assets/js/dept-groups.js प्रत्येक विभाग-Dropdown आपोआप गटवार करतो.
 */

/** Campus प्रकारावरून चिन्ह + क्रम (संस्था → College → School → इतर) */
function deptGroupKind($collegeType) {
    $t = (string)$collegeType;
    if ($t === '' ) { return ['🏢', 3]; }
    if ($t === 'Trust HQ') { return ['🏛️', 0]; }
    if (stripos($t, 'School') !== false) { return ['🏫', 2]; }
    return ['🎓', 1];   // Junior College, Arts, Science, Engineering, Polytechnic …
}

/**
 * [deptId => ['g' => गटाचं नाव, 'o' => क्रम]] — सक्रिय संस्थेचे सगळे विभाग (निष्क्रिय सुद्धा — जुन्या नोंदींच्या Dropdown साठी)
 */
function deptGroupMap(PDO $pdo, $orgId) {
    static $cache = [];
    if (isset($cache[$orgId])) { return $cache[$orgId]; }
    $orgName = '';
    try {
        $o = $pdo->prepare("SELECT name FROM organizations WHERE id = ?");
        $o->execute([(int)$orgId]);
        $orgName = (string)$o->fetchColumn();
        $st = $pdo->prepare("SELECT d.id, c.id AS campus_id, c.name AS campus_name, c.college_type FROM departments d LEFT JOIN campuses c ON c.id = d.campus_id WHERE d.organization_id = ?");
        $st->execute([(int)$orgId]);
        $rows = $st->fetchAll();
    } catch (PDOException $e) { return $cache[$orgId] = []; }
    $trustLabel = '🏛️ ' . ($orgName !== '' ? $orgName . ' — ' : '') . 'संस्था-स्तरीय';
    $out = [];
    foreach ($rows as $r) {
        if (!$r['campus_id'] || $r['college_type'] === 'Trust HQ') { $out[(int)$r['id']] = ['g' => $trustLabel, 'o' => '0']; continue; }
        [$icon, $rank] = deptGroupKind($r['college_type']);
        $out[(int)$r['id']] = ['g' => $icon . ' ' . $r['campus_name'], 'o' => $rank . '|' . mb_strtolower((string)$r['campus_name'])];
    }
    return $cache[$orgId] = $out;
}

/** पानावर देण्यासाठी — <script> मधला नकाशा */
function deptGroupsScript(PDO $pdo, $orgId) {
    $map = deptGroupMap($pdo, $orgId);
    if (!$map) { return ''; }
    return '<script>window.EOWMS_DEPT_GROUPS=' . json_encode($map, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script>';
}

/** PDF (mPDF) मध्ये Emoji दिसत नाहीत — शीर्षकातलं सुरुवातीचं चिन्ह काढतो */
function deptGroupPlain($label) { return trim(preg_replace('/^[^\p{L}\p{N}]+/u', '', (string)$label)); }

/**
 * 📊 अहवालातल्या विभाग-तक्त्यांसाठी — ओळी Campus नुसार गटवार (संस्था-स्तरीय → College → School → इतर), प्रत्येक गटाला शीर्षक-ओळ.
 * गटातला मूळ क्रम (उदा. जास्त नोंदी आधी / कमकुवत आधी) तसाच राहतो.
 * $rows — प्रत्येक ओळीत विभागाचा id ($idKey); $rowHtml(row) — त्या ओळीचं <tr>…</tr>; $cols — तक्त्यातले Columns
 */
function deptGroupRowsHtml(PDO $pdo, $orgId, array $rows, $cols, callable $rowHtml, $idKey = 'id', $pdf = true) {
    if (!$rows) { return ''; }
    $map = deptGroupMap($pdo, $orgId);
    $buckets = [];
    foreach ($rows as $r) {
        $g = $map[(int)($r[$idKey] ?? 0)] ?? ['g' => '🏢 इतर', 'o' => '9'];
        $buckets[$g['g']] ??= ['o' => $g['o'], 'rows' => []];
        $buckets[$g['g']]['rows'][] = $r;
    }
    uasort($buckets, fn($a, $b) => strcmp($a['o'], $b['o']));
    $out = '';
    foreach ($buckets as $label => $b) {
        $txt = $pdf ? deptGroupPlain($label) : $label;
        $style = $pdf ? 'background:#EEF0F8;color:#2B2F6B;font-weight:bold;padding:5px 6px;border-bottom:1px solid #D5D9EC;'
            : 'background:color-mix(in srgb,var(--rail,#D9530F) 9%,var(--surface,#fff));color:inherit;font-weight:800;font-size:12.5px;padding:7px 10px;border-left:3px solid var(--rail,#D9530F);';
        $out .= '<tr class="dept-grp"><td colspan="' . (int)$cols . '" style="' . $style . '">'
            . htmlspecialchars($txt, ENT_QUOTES, 'UTF-8') . ' <span style="font-weight:normal;opacity:.7;">(' . count($b['rows']) . ' विभाग)</span></td></tr>';
        foreach ($b['rows'] as $r) { $out .= $rowHtml($r); }
    }
    return $out;
}
