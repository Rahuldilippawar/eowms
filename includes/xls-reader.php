<?php
/**
 * 📄 जुनी Excel (.xls, BIFF8 — Excel 97–2003) File वाचणारा — कोणतीही बाहेरची Library नाही.
 * जुन्या Software मधून (उदा. Crystal Reports "Export → Excel") येणाऱ्या Files अशाच असतात.
 * पहिली Sheet वाचतो: परत ओळींची यादी, प्रत्येक ओळ = cells (मजकूर string, अंक float).
 */

function eowmsReadXls($path) {
    $data = @file_get_contents($path);
    if ($data === false || strlen($data) < 512 || substr($data, 0, 8) !== "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
        throw new RuntimeException('ही .xls File वाचता आली नाही (जुना Excel प्रकार नाही / बिघडलेली).');
    }
    $wb = xlsCfbStream($data, ['Workbook', 'Book']);
    if ($wb === null) { throw new RuntimeException('.xls File मध्ये Workbook सापडलं नाही.'); }
    return xlsParseBiff($wb);
}

/** OLE / Compound File मधून नावाने Stream काढा */
function xlsCfbStream($d, array $names) {
    $u16 = fn($o) => unpack('v', substr($d, $o, 2))[1];
    $u32 = fn($o) => unpack('V', substr($d, $o, 4))[1];
    $secSize = 1 << $u16(0x1E);
    $miniSize = 1 << $u16(0x20);
    $numFat = $u32(0x2C);
    $dirStart = $u32(0x30);
    $miniCutoff = $u32(0x38);
    $miniFatStart = $u32(0x3C);
    $difatStart = $u32(0x44);
    $numDifat = $u32(0x48);
    $sec = fn($n) => substr($d, ($n + 1) * $secSize, $secSize);

    // FAT sectors list (DIFAT)
    $fatSecs = [];
    for ($i = 0; $i < 109 && count($fatSecs) < $numFat; $i++) { $fatSecs[] = $u32(0x4C + $i * 4); }
    $next = $difatStart;
    for ($k = 0; $k < $numDifat && count($fatSecs) < $numFat && $next < 0xFFFFFFFA; $k++) {
        $b = $sec($next); $per = $secSize / 4 - 1;
        for ($i = 0; $i < $per && count($fatSecs) < $numFat; $i++) { $fatSecs[] = unpack('V', substr($b, $i * 4, 4))[1]; }
        $next = unpack('V', substr($b, $per * 4, 4))[1];
    }
    $fat = [];
    foreach ($fatSecs as $fs) { $fat = array_merge($fat, array_values(unpack('V*', $sec($fs)))); }
    $chain = function ($start) use ($fat, $sec) {
        $out = ''; $guard = 0;
        for ($s = $start; $s < 0xFFFFFFFA && isset($fat[$s]) && $guard++ < 2000000; $s = $fat[$s]) { $out .= $sec($s); }
        return $out;
    };

    // Directory
    $dir = $chain($dirStart);
    $entries = [];
    for ($o = 0; $o + 128 <= strlen($dir); $o += 128) {
        $nl = unpack('v', substr($dir, $o + 64, 2))[1];
        $name = $nl >= 2 ? mb_convert_encoding(substr($dir, $o, $nl - 2), 'UTF-8', 'UTF-16LE') : '';
        $entries[] = ['name' => $name, 'type' => ord($dir[$o + 66]), 'start' => unpack('V', substr($dir, $o + 116, 4))[1], 'size' => unpack('V', substr($dir, $o + 120, 4))[1]];
    }
    $root = $entries[0] ?? null;
    foreach ($entries as $e) {
        if ($e['type'] !== 2 || !in_array(strtolower($e['name']), array_map('strtolower', $names), true)) continue;
        if ($e['size'] >= $miniCutoff || !$root) { return substr($chain($e['start']), 0, $e['size']); }
        // लहान Stream — Mini FAT मधून
        $miniStream = $chain($root['start']);
        $miniFat = array_values(unpack('V*', $chain($miniFatStart)));
        $out = ''; $guard = 0;
        for ($s = $e['start']; $s < 0xFFFFFFFA && isset($miniFat[$s]) && $guard++ < 1000000; $s = $miniFat[$s]) { $out .= substr($miniStream, $s * $miniSize, $miniSize); }
        return substr($out, 0, $e['size']);
    }
    return null;
}

/** BIFF8 records → पहिल्या Sheet च्या ओळी */
function xlsParseBiff($wb) {
    $len = strlen($wb); $pos = 0;
    $recs = [];
    while ($pos + 4 <= $len) {
        $h = unpack('vtype/vsize', substr($wb, $pos, 4));
        $recs[] = [$h['type'], substr($wb, $pos + 4, $h['size'])];
        $pos += 4 + $h['size'];
    }
    // SST (+CONTINUE) — Shared strings
    $sst = []; $sheetStart = null; $bofCount = 0;
    for ($i = 0; $i < count($recs); $i++) {
        [$t, $b] = $recs[$i];
        if ($t === 0x00FC) {
            $parts = [$b];
            while (isset($recs[$i + 1]) && $recs[$i + 1][0] === 0x003C) { $parts[] = $recs[++$i][1]; }
            $sst = xlsReadSst($parts);
        } elseif ($t === 0x0809) {
            $bofCount++;
            if ($bofCount === 2 && $sheetStart === null) { $sheetStart = $i; } // पहिली Worksheet
        }
    }
    if ($sheetStart === null) return [];
    $cells = []; $maxR = -1; $maxC = -1;
    $set = function ($r, $c, $v) use (&$cells, &$maxR, &$maxC) { $cells[$r][$c] = $v; $maxR = max($maxR, $r); $maxC = max($maxC, $c); };
    for ($i = $sheetStart + 1; $i < count($recs); $i++) {
        [$t, $b] = $recs[$i];
        if ($t === 0x000A) break; // EOF
        switch ($t) {
            case 0x00FD: // LABELSST
                $r = unpack('vr/vc/vx/Vi', $b); $set($r['r'], $r['c'], $sst[$r['i']] ?? ''); break;
            case 0x0203: // NUMBER
                $r = unpack('vr/vc/vx', $b); $set($r['r'], $r['c'], unpack('e', substr($b, 6, 8))[1]); break;
            case 0x027E: // RK
                $r = unpack('vr/vc/vx/Vrk', $b); $set($r['r'], $r['c'], xlsRk($r['rk'])); break;
            case 0x00BD: // MULRK
                $r = unpack('vr/vc', $b); $n = (strlen($b) - 6) / 6;
                for ($k = 0; $k < $n; $k++) { $rk = unpack('V', substr($b, 4 + $k * 6 + 2, 4))[1]; $set($r['r'], $r['c'] + $k, xlsRk($rk)); }
                break;
            case 0x0204: // LABEL (जुना)
                $r = unpack('vr/vc/vx/vlen', $b); $f = ord($b[8] ?? "\0");
                $set($r['r'], $r['c'], ($f & 1) ? mb_convert_encoding(substr($b, 9, $r['len'] * 2), 'UTF-8', 'UTF-16LE') : mb_convert_encoding(substr($b, 9, $r['len']), 'UTF-8', 'ISO-8859-1'));
                break;
            case 0x0006: // FORMULA — Cached अंक असेल तर
                $r = unpack('vr/vc', $b);
                if (substr($b, 12, 2) !== "\xFF\xFF") { $set($r['r'], $r['c'], unpack('e', substr($b, 6, 8))[1]); }
                break;
        }
    }
    $rows = [];
    for ($r = 0; $r <= $maxR; $r++) {
        $row = [];
        for ($c = 0; $c <= $maxC; $c++) { $row[] = $cells[$r][$c] ?? ''; }
        $rows[] = $row;
    }
    return $rows;
}

function xlsRk($rk) {
    if ($rk & 2) { $v = $rk >> 2; if ($v & 0x20000000) { $v -= 0x40000000; } }
    else { $v = unpack('e', pack('V2', 0, $rk & 0xFFFFFFFC))[1]; }
    return ($rk & 1) ? $v / 100 : (float)$v;
}

/** SST — CONTINUE मध्ये तुटलेले strings सांभाळून */
function xlsReadSst(array $parts) {
    $p = 0; $buf = $parts[0]; $bi = 0;
    $total = unpack('V', substr($buf, 4, 4))[1]; $off = 8;
    $out = [];
    $next = function () use (&$buf, &$off, &$bi, $parts) { $bi++; if (!isset($parts[$bi])) return false; $buf = $parts[$bi]; $off = 0; return true; };
    for ($n = 0; $n < $total; $n++) {
        if ($off >= strlen($buf) && !$next()) break;
        $cch = unpack('v', substr($buf, $off, 2))[1]; $flags = ord($buf[$off + 2]); $off += 3;
        $rich = 0; $ext = 0;
        if ($flags & 0x08) { $rich = unpack('v', substr($buf, $off, 2))[1]; $off += 2; }
        if ($flags & 0x04) { $ext = unpack('V', substr($buf, $off, 4))[1]; $off += 4; }
        $wide = (bool)($flags & 1); $s = ''; $left = $cch;
        while ($left > 0) {
            $avail = strlen($buf) - $off;
            $bytesPer = $wide ? 2 : 1;
            $take = min($left, intdiv($avail, $bytesPer));
            $chunk = substr($buf, $off, $take * $bytesPer);
            $s .= $wide ? mb_convert_encoding($chunk, 'UTF-8', 'UTF-16LE') : mb_convert_encoding($chunk, 'UTF-8', 'ISO-8859-1');
            $off += $take * $bytesPer; $left -= $take;
            if ($left > 0) { if (!$next()) break; $wide = (bool)(ord($buf[0]) & 1); $off = 1; }
        }
        // Formatting runs + phonetic — वगळा (CONTINUE ओलांडू शकतात)
        $skip = $rich * 4 + $ext;
        while ($skip > 0) {
            $avail = strlen($buf) - $off;
            if ($skip <= $avail) { $off += $skip; $skip = 0; } else { $skip -= $avail; if (!$next()) break; }
        }
        $out[] = $s;
    }
    return $out;
}
