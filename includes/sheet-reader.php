<?php
/**
 * 📄 CSV किंवा Excel (.xlsx) — दोन्ही एकाच प्रकारे वाचतो (कोणतीही बाहेरची Library नाही).
 * परत: ओळींची यादी; प्रत्येक ओळ = cells ची यादी. Excel मधला अंक (तारीख/संख्या) float म्हणून,
 * मजकूर string म्हणून — म्हणजे Excel-तारीख (Serial) आणि हाताने लिहिलेली तारीख वेगळी ओळखता येते.
 */
function eowmsReadSheet($path, $originalName) {
    $ext = strtolower(pathinfo((string)$originalName, PATHINFO_EXTENSION));
    $head = (string)@file_get_contents($path, false, null, 0, 4);
    if ($ext === 'xlsx' || $head === "PK\x03\x04") { return eowmsReadXlsx($path); }
    if ($ext === 'xls' || $head === "\xD0\xCF\x11\xE0") { require_once __DIR__ . '/xls-reader.php'; return eowmsReadXls($path); } // जुनी Excel 97–2003
    $rows = [];
    $fh = fopen($path, 'r');
    if (!$fh) { throw new RuntimeException('File उघडता आली नाही.'); }
    $bom = fread($fh, 3);
    if ($bom !== "\xEF\xBB\xBF") { rewind($fh); }
    while (($r = fgetcsv($fh)) !== false) { $rows[] = $r; }
    fclose($fh);
    return $rows;
}

function eowmsReadXlsx($path) {
    if (!class_exists('ZipArchive')) { throw new RuntimeException('Server वर PHP Zip Extension नाही — CSV वापरा.'); }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) { throw new RuntimeException('Excel File उघडता आली नाही (बिघडलेली?).'); }
    $xml = function ($name) use ($zip) {
        $s = $zip->getFromName($name);
        if ($s === false) { return null; }
        $prev = libxml_use_internal_errors(true);
        $x = simplexml_load_string($s, 'SimpleXMLElement', LIBXML_NONET | LIBXML_COMPACT);
        libxml_use_internal_errors($prev);
        return $x ?: null;
    };

    // Shared strings
    $shared = [];
    if ($ss = $xml('xl/sharedStrings.xml')) {
        foreach ($ss->si as $si) {
            if (isset($si->t)) { $shared[] = (string)$si->t; continue; }
            $t = '';
            foreach ($si->r as $run) { $t .= (string)$run->t; }
            $shared[] = $t;
        }
    }

    // पहिली Sheet — workbook rels वरून खरा मार्ग
    $sheetPath = 'xl/worksheets/sheet1.xml';
    $wb = $xml('xl/workbook.xml');
    $rels = $xml('xl/_rels/workbook.xml.rels');
    if ($wb && $rels && isset($wb->sheets->sheet[0])) {
        $rid = (string)$wb->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
        foreach ($rels->Relationship as $rel) {
            if ((string)$rel['Id'] === $rid) {
                $target = ltrim((string)$rel['Target'], '/');
                $sheetPath = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
            }
        }
    }
    $sheet = $xml($sheetPath);
    $zip->close();
    if (!$sheet) { throw new RuntimeException('Excel मध्ये Sheet सापडली नाही.'); }

    $colIdx = function ($ref) {
        $letters = preg_replace('/\d+/', '', $ref);
        $n = 0;
        foreach (str_split($letters) as $ch) { $n = $n * 26 + (ord($ch) - 64); }
        return $n - 1;
    };
    $rows = [];
    foreach ($sheet->sheetData->row as $row) {
        $cells = [];
        foreach ($row->c as $c) {
            $i = $colIdx((string)$c['r']);
            $t = (string)$c['t'];
            if ($t === 's') { $v = $shared[(int)$c->v] ?? ''; }
            elseif ($t === 'inlineStr') { $v = (string)$c->is->t; }
            elseif ($t === 'str' || $t === 'b' || $t === 'e') { $v = (string)$c->v; }
            else { $v = isset($c->v) && (string)$c->v !== '' ? (float)(string)$c->v : ''; }
            $cells[$i] = $v;
        }
        if (!$cells) { $rows[] = []; continue; }
        $max = max(array_keys($cells));
        $out = [];
        for ($i = 0; $i <= $max; $i++) { $out[] = $cells[$i] ?? ''; }
        $rows[] = $out;
    }
    return $rows;
}
