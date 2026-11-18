<?php
/**
 * 📄 साधी PDF Table वाचक (बाहेरची Library नाही) — Report / Register च्या PDF मधून ओळी काढतो.
 *  - FlateDecode Streams, (literal) / <hex> Strings, Tm / Td / TD / T* / Tf / Tj / TJ / ' / "
 *  - Simple (1-byte) fonts — ToUnicode bfchar / bfrange वापरतो (असेल तर)
 *  - परत: प्रत्येक पानासाठी Cells [x, y, size, text] — y वरून ओळी, x वरून Columns
 *  Scanned (फोटो) PDF किंवा Type0/CID fonts वाचता येत नाहीत — तेव्हा रिकामं परत.
 */

/** PDF मधले सगळे objects: [num => body] */
function pdfObjects($data) {
    $objs = [];
    if (preg_match_all('/(\d+)\s+(\d+)\s+obj\b(.*?)\bendobj/s', $data, $m, PREG_SET_ORDER)) {
        foreach ($m as $o) $objs[(int)$o[1]] = $o[3];
    }
    return $objs;
}

/** Object मधला stream (Flate असेल तर उघडून) */
function pdfStream($body) {
    if (!preg_match('/stream\r?\n/s', $body, $m, PREG_OFFSET_CAPTURE)) return null;
    $start = $m[0][1] + strlen($m[0][0]);
    $end = strrpos($body, 'endstream');
    if ($end === false || $end < $start) return null;
    $raw = rtrim(substr($body, $start, $end - $start), "\r\n");
    $dict = substr($body, 0, $m[0][1]);
    if (strpos($dict, '/FlateDecode') !== false) {
        $out = @gzuncompress($raw);
        if ($out === false) $out = @gzinflate(substr($raw, 2));
        return $out === false ? null : $out;
    }
    if (preg_match('#/Filter\s*/#', $dict)) return null; // इतर Filter (DCT…) — text नाही
    return $raw;
}

/** ToUnicode CMap → [code => utf8] */
function pdfCMap($cmap) {
    $map = [];
    $u = function ($hex) { $s = ''; foreach (str_split($hex, 4) as $c) { $s .= mb_chr(hexdec($c), 'UTF-8'); } return $s; };
    if (preg_match_all('/beginbfchar(.*?)endbfchar/s', $cmap, $bl)) {
        foreach ($bl[1] as $b) { if (preg_match_all('/<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>/', $b, $p, PREG_SET_ORDER)) foreach ($p as $x) $map[hexdec($x[1])] = $u($x[2]); }
    }
    if (preg_match_all('/beginbfrange(.*?)endbfrange/s', $cmap, $bl)) {
        foreach ($bl[1] as $b) {
            if (preg_match_all('/<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>\s*<([0-9a-fA-F]+)>/', $b, $p, PREG_SET_ORDER)) {
                foreach ($p as $x) { $lo = hexdec($x[1]); $hi = hexdec($x[2]); $st = hexdec($x[3]); for ($c = $lo; $c <= $hi && $c - $lo < 512; $c++) $map[$c] = mb_chr($st + $c - $lo, 'UTF-8'); }
            }
        }
    }
    return $map;
}

/** PDF literal string ( ... ) — escapes उघडून bytes */
function pdfLiteral($s) {
    return preg_replace_callback('/\\\\([nrtbf()\\\\]|[0-7]{1,3}|\r?\n)/', function ($m) {
        $c = $m[1];
        $map = ['n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0c", '(' => '(', ')' => ')', '\\' => '\\'];
        if (isset($map[$c])) return $map[$c];
        if ($c[0] === "\r" || $c[0] === "\n") return '';
        return chr(octdec($c) & 0xFF);
    }, $s);
}

/**
 * PDF → पानं → Cells [['x'=>, 'y'=>, 'size'=>, 'text'=>], …] (y खाली वाढतो — वरचं पान वर)
 */
function pdfTextCells($data) {
    $objs = pdfObjects($data);
    if (!$objs) return [];
    $ref = function ($s) { return preg_match('/(\d+)\s+\d+\s+R/', (string)$s, $m) ? (int)$m[1] : 0; };
    $pages = [];
    foreach ($objs as $num => $body) {
        if (!preg_match('#/Type\s*/Page\b(?!s)#', $body)) continue;
        // Fonts: /Resources (थेट किंवा reference) → /Font << /a 5 0 R … >>
        $res = $body;
        if (preg_match('#/Resources\s+(\d+)\s+\d+\s+R#', $body, $rm) && isset($objs[(int)$rm[1]])) $res = $objs[(int)$rm[1]];
        $fonts = [];
        $fontDict = '';
        if (preg_match('#/Font\s*<<(.*?)>>#s', $res, $fm)) $fontDict = $fm[1];
        elseif (preg_match('#/Font\s+(\d+)\s+\d+\s+R#', $res, $fr) && isset($objs[(int)$fr[1]])) $fontDict = $objs[(int)$fr[1]];
        if (preg_match_all('#/([^\s/<>\[\]()]+)\s+(\d+)\s+\d+\s+R#', $fontDict, $fl, PREG_SET_ORDER)) {
            foreach ($fl as $f) {
                $fo = $objs[(int)$f[2]] ?? '';
                $tu = preg_match('#/ToUnicode\s+(\d+)\s+\d+\s+R#', $fo, $t) && isset($objs[(int)$t[1]]) ? pdfCMap((string)pdfStream($objs[(int)$t[1]])) : [];
                $fonts[$f[1]] = ['map' => $tu, 'cid' => strpos($fo, '/Type0') !== false];
            }
        }
        // Contents: एक किंवा अनेक streams
        $content = '';
        if (preg_match('#/Contents\s*\[(.*?)\]#s', $body, $cm)) { preg_match_all('/(\d+)\s+\d+\s+R/', $cm[1], $cr); foreach ($cr[1] as $c) $content .= pdfStream($objs[(int)$c] ?? '') . "\n"; }
        elseif ($c = $ref(preg_match('#/Contents\s+(\d+\s+\d+\s+R)#', $body, $cm) ? $cm[1] : '')) $content = (string)pdfStream($objs[$c] ?? '');
        $pages[] = pdfContentCells($content, $fonts);
    }
    return $pages;
}

/** Content stream → Cells */
function pdfContentCells($c, array $fonts) {
    $cells = [];
    $tm = [1, 0, 0, 1, 0, 0]; $lm = $tm; $font = null; $size = 10; $lead = 0; $moved = true;
    $decode = function ($bytes) use (&$font, $fonts) {
        $f = $fonts[$font] ?? ['map' => [], 'cid' => false];
        $out = '';
        if ($f['cid']) { foreach (str_split($bytes, 2) as $p) { $code = strlen($p) === 2 ? (ord($p[0]) << 8) | ord($p[1]) : ord($p); $out .= $f['map'][$code] ?? ''; } return $out; }
        $len = strlen($bytes);
        for ($i = 0; $i < $len; $i++) { $code = ord($bytes[$i]); $out .= $f['map'][$code] ?? ($code >= 32 && $code < 127 ? chr($code) : ''); }
        return $out;
    };
    // जागा न बदलता लगेच पुढचा Tj = त्याच शब्दाचा पुढचा भाग (kerning मुळे तुटलेला) — मागच्याला जोडतो
    $emit = function ($text) use (&$cells, &$tm, &$size, &$moved) {
        if (!$moved && $cells) { $cells[count($cells) - 1]['text'] .= $text; return; }
        $moved = false;
        if (trim($text) === '') { $moved = true; return; }
        $cells[] = ['x' => $tm[4], 'y' => $tm[5], 'size' => abs($size * ($tm[3] ?: 1)), 'text' => $text];
    };
    // Tokens: strings, hex strings, arrays, names, numbers, operators
    preg_match_all('/\((?:\\\\.|[^\\\\()]|\((?:\\\\.|[^\\\\()])*\))*\)|<[0-9a-fA-F\s]*>|\[|\]|\/[^\s\/\[\]()<>]+|-?\d*\.?\d+|[A-Za-z\'"*]+/s', $c, $m);
    $stack = []; $arr = null;
    foreach ($m[0] as $tok) {
        if ($tok === '[') { $arr = []; continue; }
        if ($tok === ']') { $stack[] = $arr ?? []; $arr = null; continue; }
        $isStr = $tok[0] === '(' || ($tok[0] === '<' && $tok !== '<<');
        $val = $tok;
        if ($tok[0] === '(') $val = ['s' => pdfLiteral(substr($tok, 1, -1))];
        elseif ($tok[0] === '<') $val = ['s' => (string)@hex2bin(preg_replace('/\s+/', '', substr($tok, 1, -1)) . (strlen(preg_replace('/\s+/', '', substr($tok, 1, -1))) % 2 ? '0' : ''))];
        if ($arr !== null) { $arr[] = $val; continue; }
        if ($isStr || is_numeric($tok) || $tok[0] === '/') { $stack[] = $val; continue; }
        $n = function ($i) use (&$stack) { return (float)($stack[count($stack) - $i] ?? 0); };
        switch ($tok) {
            case 'BT': $tm = [1, 0, 0, 1, 0, 0]; $lm = $tm; $moved = true; break;
            case 'Tf': $size = $n(1); $font = ltrim((string)($stack[count($stack) - 2] ?? ''), '/'); break;
            case 'TL': $lead = $n(1); break;
            case 'Tm': $tm = [$n(6), $n(5), $n(4), $n(3), $n(2), $n(1)]; $lm = $tm; $moved = true; break;
            case 'Td': case 'TD':
                $tx = $n(2); $ty = $n(1); if ($tok === 'TD') $lead = -$ty;
                $lm = [$lm[0], $lm[1], $lm[2], $lm[3], $lm[4] + $tx * $lm[0] + $ty * $lm[2], $lm[5] + $tx * $lm[1] + $ty * $lm[3]]; $tm = $lm; $moved = true; break;
            case 'T*': $lm[5] -= $lead * ($lm[3] ?: 1); $tm = $lm; $moved = true; break;
            case 'Tj': case "'": case '"':
                if ($tok !== 'Tj') { $lm[5] -= $lead * ($lm[3] ?: 1); $tm = $lm; $moved = true; }
                $s = end($stack); if (is_array($s) && isset($s['s'])) $emit($decode($s['s'])); break;
            case 'TJ':
                $a = end($stack); $t = '';
                if (is_array($a)) foreach ($a as $p) { if (is_array($p) && isset($p['s'])) $t .= $decode($p['s']); elseif (is_numeric($p) && (float)$p < -250) $t .= ' '; }
                $emit($t); break;
        }
        $stack = [];
    }
    return $cells;
}

/** Cells → ओळी (y जवळचे एकत्र), प्रत्येक ओळ x नुसार क्रमाने */
function pdfCellLines(array $cells) {
    usort($cells, fn($a, $b) => $a['y'] <=> $b['y'] ?: $a['x'] <=> $b['x']);
    $lines = [];
    foreach ($cells as $c) {
        $tol = max(2, $c['size'] * 0.45);
        $k = count($lines) - 1;
        if ($k >= 0 && abs($lines[$k]['y'] - $c['y']) <= $tol) $lines[$k]['cells'][] = $c;
        else $lines[] = ['y' => $c['y'], 'cells' => [$c]];
    }
    foreach ($lines as &$l) usort($l['cells'], fn($a, $b) => $a['x'] <=> $b['x']);
    return $lines;
}

/**
 * 🚌 "Bus Fee Register" PDF (Sr, Regis No, NAME, Class, Division, Stop Name, TOTAL / PAID / BALANCE FEE)
 * → Import साठी ओळी: [Header, ओळ, …]; College = शीर्षकातली दुसरी ओळ. ओळखता आलं नाही तर [].
 */
function pdfBusFeeRegister($data) {
    $pages = pdfTextCells($data);
    $college = '';
    $records = [];
    foreach ($pages as $cells) {
        if (!$cells) continue;
        $lines = pdfCellLines($cells);
        // Header Columns ची x स्थानं
        $hx = [];
        foreach ($cells as $c) {
            $t = strtoupper(trim($c['text']));
            if ($t === 'NAME') $hx['name'] = $c['x'];
            elseif ($t === 'CLASS') $hx['class'] = $c['x'];
            elseif (strpos($t, 'DIVISI') === 0) $hx['div'] = $c['x'];
            elseif (strpos($t, 'STOP') === 0) $hx['stop'] = $c['x'];
            elseif (strpos($t, 'TOTAL') === 0) $hx['total'] = $c['x'];
        }
        if (!$college) {
            foreach ($lines as $l) { $t = trim(implode(' ', array_column($l['cells'], 'text'))); if (preg_match('/institute|college|school|vidyalaya|mahavidyalaya/i', $t) && !preg_match('/trust/i', $t)) { $college = preg_replace('/\s+/', ' ', $t); break; } }
        }
        // मुख्य ओळ: सुरुवातीला Sr (अंक) + Regis No (अंक), शेवटी 3 रकमा
        $mains = [];
        foreach ($lines as $i => $l) {
            $t = array_map(fn($c) => trim($c['text']), $l['cells']);
            $t = array_values(array_filter($t, fn($x) => $x !== ''));
            if (count($t) >= 5 && preg_match('/^\d{1,12}$/', $t[0]) && preg_match('/^\d{1,12}$/', $t[1]) && max(strlen($t[0]), strlen($t[1])) >= 3) $mains[$i] = $l;
            elseif (count($t) >= 4 && preg_match('/^(\d{1,4})\s+(\d{3,12})$/', $t[0], $sm)) { array_splice($l['cells'], 0, 1, [['x' => $l['cells'][0]['x'], 'y' => $l['y'], 'size' => 1, 'text' => $sm[1]], ['x' => $l['cells'][0]['x'] + 1, 'y' => $l['y'], 'size' => 1, 'text' => $sm[2]]]); $mains[$i] = $l; }
        }
        if (!$mains) continue;
        $mainIdx = array_keys($mains);
        $nameX0 = $hx['name'] ?? null; $classX = $hx['class'] ?? null;
        $names = array_fill_keys($mainIdx, []);
        foreach ($lines as $i => $l) {
            if (isset($mains[$i])) continue;
            // नावाच्या Column मधले तुकडे — जवळच्या मुख्य ओळीला
            $parts = array_filter($l['cells'], fn($c) => ($classX === null || $c['x'] < $classX - 5) && ($nameX0 === null || $c['x'] > $nameX0 - 900) && !preg_match('/^(sr\.?|no\.?|regis|name|class|divisi|on)$/i', trim($c['text'])));
            if (!$parts) continue;
            $best = null; $bd = INF;
            foreach ($mainIdx as $mi) { $d = abs($lines[$mi]['y'] - $l['y']); if ($d < $bd) { $bd = $d; $best = $mi; } }
            if ($best !== null && $bd < 4 * max(1, $l['cells'][0]['size'] ?: 10)) $names[$best][] = [$l['y'], implode(' ', array_map(fn($c) => trim($c['text']), $parts))];
        }
        foreach ($mains as $mi => $l) {
            $cs = array_values(array_filter($l['cells'], fn($c) => trim($c['text']) !== ''));
            // Sr आणि Regis No — PDF मध्ये क्रम उलटा असू शकतो: लहान अंक = Sr
            [$a1, $a2] = [trim($cs[0]['text']), trim($cs[1]['text'])];
            [$sr, $reg] = strlen($a1) <= strlen($a2) ? [$a1, $a2] : [$a2, $a1];
            $rest = array_slice($cs, 2);
            $nums = []; while ($rest && preg_match('/^[\d,.]+$/', trim(end($rest)['text'])) && count($nums) < 3) { array_unshift($nums, (float)str_replace(',', '', trim(array_pop($rest)['text']))); }
            $mid = [];
            foreach ($rest as $c) {
                if ($classX !== null && $c['x'] < $classX - 5) { $names[$mi][] = [$l['y'], trim($c['text'])]; continue; }
                $mid[] = $c;
            }
            // Class / Division / Stop — Header च्या x नुसार
            $col = ['class' => '', 'div' => '', 'stop' => ''];
            if (count($mid) === 3) { [$col['class'], $col['div'], $col['stop']] = array_map(fn($c) => trim($c['text']), $mid); $mid = []; }
            foreach ($mid as $c) {
                $k = 'class';
                if (isset($hx['stop']) && $c['x'] >= $hx['stop'] - 60) $k = 'stop';
                elseif (isset($hx['div']) && $c['x'] >= $hx['div'] - 60) $k = 'div';
                if (!isset($hx['div'])) { $k = $col['class'] === '' ? 'class' : ($col['div'] === '' ? 'div' : 'stop'); }
                $col[$k] = trim($col[$k] . ' ' . trim($c['text']));
            }
            usort($names[$mi], fn($a, $b) => $a[0] <=> $b[0]);
            $name = trim(preg_replace('/\s+/', ' ', implode(' ', array_column($names[$mi], 1))));
            [$tot, $paid, $bal] = array_pad($nums, 3, null);
            $records[] = [(int)$sr, $reg, $name, $col['class'], $col['div'], $col['stop'], $tot, $paid, $bal, $college];
        }
    }
    if (!$records) return [];
    return array_merge([['Sr No', 'Reg No', 'Student Name', 'Class', 'Division', 'Destination (Stop)', 'Total Fee', 'Paid Fee', 'Balance Fee', 'College']], $records);
}
