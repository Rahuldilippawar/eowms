<?php
/**
 * 📊 खरेदी विभाग — तुलनात्मक तक्ता (Comparison Chart).
 * एका खरेदीसाठी 2–6 पुरवठादारांची दरपत्रकं: प्रत्येक वस्तूचा दर × नग = रक्कम, GST / वाहतूक / इतर / सूट,
 * एकूण → L1 (सर्वात कमी), L2…; प्रत्येक वस्तूचा सर्वात कमी दर; निवड व मान्यता; अधिकृत Print.
 * जुने Excel तक्ते (एका Workbook मधील अनेक Sheets) थेट आयात करता येतात.
 */
require_once __DIR__ . '/app-settings.php';
require_once __DIR__ . '/purchase.php';

function purchaseCmpReady(PDO $pdo) {
    static $ok = null;
    if ($ok !== null) return $ok;
    try { $pdo->query("SELECT 1 FROM purchase_comparisons LIMIT 0"); return $ok = true; } catch (PDOException $e) { return $ok = false; }
}

/** बनवणे / बदल / Print — Super Admin, "तुलनात्मक तक्ता" परवानगी, खरेदी विभाग परवानगी, किंवा खरेदी विभागाचे (PUR) कर्मचारी */
function purchaseCmpCanEdit() {
    return hasRole('super_admin') || hasPermission('purchase_compare') || hasPermission('manage_purchase')
        || (function_exists('moduleUserDeptCode') && moduleUserDeptCode() === 'PUR');
}
/** पुरवठादार निवड मान्यता (Approve) — Super Admin किंवा "तुलनात्मक तक्ता — मान्यता" परवानगी */
function purchaseCmpCanApprove() { return hasRole('super_admin') || hasPermission('purchase_compare_approve'); }
/** पाहणे / Print */
function purchaseCmpCanView() { return purchaseCmpCanEdit() || purchaseCmpCanApprove() || hasPermission('purchase_compare_view') || isManagementOrAbove(); }

function purchaseCmpStatuses() {
    return ['draft' => ['📝 तयार होत आहे', '#64748B', '#F1F5F9'], 'final' => ['⏳ मान्यतेसाठी', '#B45309', '#FEF3C7'],
            'approved' => ['✅ मंजूर', '#15803D', '#DCFCE7'], 'cancelled' => ['✕ रद्द', '#991B1B', '#FEE2E2']];
}

/** सही करणारे (default) — प्रत्येक ओळ एक */
function purchaseCmpDefaultSigns() { return "तयार करणारे\nविभाग प्रमुख\nखरेदी अधिकारी\nखरेदी समिती"; }

/** ₹ 1,23,456.50 (पैसे असतील तरच) */
function purchaseCmpMoney($n, $sym = true) {
    if ($n === null || $n === '') return '—';
    $n = round((float)$n, 2); $neg = $n < 0; $n = abs($n);
    $int = (string)floor($n); $dec = round(($n - floor($n)) * 100);
    $l3 = substr($int, -3); $rest = substr($int, 0, -3);
    if ($rest !== '') $l3 = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest) . ',' . $l3;
    return ($neg ? '-' : '') . ($sym ? '₹' : '') . $l3 . ($dec ? '.' . str_pad((string)$dec, 2, '0', STR_PAD_LEFT) : '');
}
/** दर — दशांश असतील तसे (कमाल 4) */
function purchaseCmpNum($n) {
    if ($n === null || $n === '') return '—';
    $s = rtrim(rtrim(number_format((float)$n, 4, '.', ''), '0'), '.');
    return $s === '-0' ? '0' : $s;
}

/** संपूर्ण तक्ता (vendors, items, rates) */
function purchaseCmpLoad(PDO $pdo, $orgId, $id) {
    $st = $pdo->prepare("SELECT * FROM purchase_comparisons WHERE id = ? AND organization_id = ?");
    $st->execute([(int)$id, (int)$orgId]);
    $c = $st->fetch();
    if (!$c) return null;
    $st = $pdo->prepare("SELECT * FROM purchase_comparison_vendors WHERE comparison_id = ? ORDER BY sort_order, id"); $st->execute([$c['id']]); $c['vendors'] = $st->fetchAll();
    $st = $pdo->prepare("SELECT * FROM purchase_comparison_items WHERE comparison_id = ? ORDER BY sort_order, id"); $st->execute([$c['id']]); $c['items'] = $st->fetchAll();
    $c['rates'] = [];
    $st = $pdo->prepare("SELECT r.* FROM purchase_comparison_rates r JOIN purchase_comparison_items i ON i.id = r.item_id WHERE i.comparison_id = ?"); $st->execute([$c['id']]);
    $c['amounts'] = [];
    foreach ($st->fetchAll() as $r) {
        $c['rates'][$r['item_id']][$r['vendor_id']] = $r['rate'] === null ? null : (float)$r['rate'];
        if ($r['amount'] !== null) $c['amounts'][$r['item_id']][$r['vendor_id']] = (float)$r['amount'];
    }
    return $c;
}

/**
 * हिशोब — vendors[i] => subtotal, gst, transport, other, discount, total, quoted, missing, rank;
 * items[i] => min rate व तो कोणाचा; L1; प्रत्येक वस्तू सर्वात कमी दराने घेतल्यास (mix)
 */
function purchaseCmpCompute(array $c) {
    $V = []; $nItems = count($c['items']);
    foreach ($c['vendors'] as $v) {
        $sub = 0; $quoted = 0;
        foreach ($c['items'] as $it) {
            $r = $c['rates'][$it['id']][$v['id']] ?? null;
            if ($r !== null) { $sub += $c['amounts'][$it['id']][$v['id']] ?? $r * (float)($it['qty'] ?? 1); $quoted++; }
        }
        $tr = $v['transport'] !== null ? (float)$v['transport'] : 0;
        $other = $v['other_amount'] !== null ? (float)$v['other_amount'] : 0;
        if ((int)$v['gst_incl']) $gst = 0;
        elseif ($v['gst_amount'] !== null) $gst = (float)$v['gst_amount'];
        elseif ($v['gst_pct'] !== null) $gst = round(($sub + ((int)$v['gst_on_transport'] ? $tr : 0)) * (float)$v['gst_pct'] / 100, 2);
        else $gst = 0;
        $disc = $v['discount'] !== null ? abs((float)$v['discount']) : 0;
        $total = round($sub + $gst + $tr + $other - $disc, 2);
        $V[$v['id']] = ['subtotal' => round($sub, 2), 'gst' => $gst, 'transport' => $tr, 'other' => $other, 'discount' => $disc, 'total' => $total,
            'quoted' => $quoted, 'missing' => $nItems - $quoted, 'rank' => null];
    }
    // रँक — फक्त ज्यांनी सगळ्या वस्तूंचा दर दिला त्यांच्यात (अपूर्ण दरपत्रक मागे)
    $order = array_keys($V);
    usort($order, fn($a, $b) => [$V[$a]['missing'] > 0, $V[$a]['total']] <=> [$V[$b]['missing'] > 0, $V[$b]['total']]);
    $k = 0; foreach ($order as $vid) { if ($V[$vid]['quoted'] > 0) $V[$vid]['rank'] = ++$k; }
    $I = []; $mix = 0; $mixOk = true;
    foreach ($c['items'] as $it) {
        $min = null; $who = [];
        foreach ($c['vendors'] as $v) {
            $r = $c['rates'][$it['id']][$v['id']] ?? null;
            if ($r === null) continue;
            if ($min === null || $r < $min - 1e-9) { $min = $r; $who = [$v['id']]; }
            elseif (abs($r - $min) < 1e-9) $who[] = $v['id'];
        }
        $I[$it['id']] = ['min' => $min, 'who' => $who];
        if ($min === null) $mixOk = false; else $mix += $c['amounts'][$it['id']][$who[0]] ?? $min * (float)($it['qty'] ?? 1);
    }
    $l1 = $order[0] ?? null;
    $totals = array_column(array_filter($V, fn($x) => $x['quoted'] > 0), 'total');
    return ['vendors' => $V, 'items' => $I, 'order' => $order, 'l1' => $l1, 'mix' => $mixOk ? round($mix, 2) : null,
        'max' => $totals ? max($totals) : 0, 'l2' => isset($order[1]) ? $V[$order[1]]['total'] : null];
}

/** पुढचा क्रमांक (आर्थिक वर्षात) */
function purchaseCmpNextNo(PDO $pdo, $orgId, $fy) {
    $st = $pdo->prepare("SELECT COALESCE(MAX(cmp_no), 0) + 1 FROM purchase_comparisons WHERE organization_id = ? AND fy = ?");
    $st->execute([(int)$orgId, $fy]);
    return (int)$st->fetchColumn();
}

/**
 * तक्ता जतन — $d = ['title','cmp_date','department','request_id','notes','signatories','status',
 *   'vendors' => [[name,gst_pct,gst_amount,gst_incl,gst_on_transport,transport,transport_incl,other_label,other_amount,discount,remarks], …],
 *   'items' => [[particular, qty, unit, rates => [vendorIndex => rate|null]], …], 'source']
 * परत: id
 */
function purchaseCmpSave(PDO $pdo, $orgId, $userId, array $d, $id = 0) {
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
        $date = $d['cmp_date'] ?: date('Y-m-d');
        $fy = purchaseFy($date);
        if ($id) {
            $pdo->prepare("UPDATE purchase_comparisons SET title=?, cmp_date=?, fy=?, department=?, request_id=?, notes=?, signatories=?, status=?, updated_by=?, updated_at=NOW() WHERE id=? AND organization_id=?")
                ->execute([$d['title'], $date, $fy, $d['department'] ?: null, $d['request_id'] ?: null, $d['notes'] ?: null, $d['signatories'] ?: null, $d['status'], $userId, $id, $orgId]);
            $old = $pdo->prepare("SELECT id FROM purchase_comparison_items WHERE comparison_id = ?"); $old->execute([$id]);
            $ids = $old->fetchAll(PDO::FETCH_COLUMN);
            if ($ids) $pdo->exec("DELETE FROM purchase_comparison_rates WHERE item_id IN (" . implode(',', array_map('intval', $ids)) . ")");
            $pdo->prepare("DELETE FROM purchase_comparison_items WHERE comparison_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM purchase_comparison_vendors WHERE comparison_id = ?")->execute([$id]);
        } else {
            $pdo->prepare("INSERT INTO purchase_comparisons (organization_id, fy, cmp_no, title, cmp_date, department, request_id, notes, signatories, status, source, created_by, created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
                ->execute([$orgId, $fy, purchaseCmpNextNo($pdo, $orgId, $fy), $d['title'], $date, $d['department'] ?: null, $d['request_id'] ?: null, $d['notes'] ?: null,
                    $d['signatories'] ?: null, $d['status'] ?? 'draft', $d['source'] ?? null, $userId]);
            $id = (int)$pdo->lastInsertId();
        }
        $vIns = $pdo->prepare("INSERT INTO purchase_comparison_vendors (comparison_id, sort_order, name, gst_pct, gst_amount, gst_incl, gst_on_transport, transport, transport_incl, other_label, other_amount, discount, remarks)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $vIds = [];
        foreach (array_values($d['vendors']) as $i => $v) {
            $vIns->execute([$id, $i, mb_substr(trim($v['name']), 0, 200), $v['gst_pct'], $v['gst_amount'], (int)!empty($v['gst_incl']), (int)!empty($v['gst_on_transport']),
                $v['transport'], (int)!empty($v['transport_incl']), ($v['other_label'] ?? '') !== '' ? mb_substr($v['other_label'], 0, 100) : null, $v['other_amount'], $v['discount'],
                ($v['remarks'] ?? '') !== '' ? mb_substr($v['remarks'], 0, 255) : null]);
            $vIds[$i] = (int)$pdo->lastInsertId();
        }
        $iIns = $pdo->prepare("INSERT INTO purchase_comparison_items (comparison_id, sort_order, particular, qty, unit) VALUES (?,?,?,?,?)");
        $rIns = $pdo->prepare("INSERT INTO purchase_comparison_rates (item_id, vendor_id, rate, amount) VALUES (?,?,?,?)");
        foreach (array_values($d['items']) as $i => $it) {
            $iIns->execute([$id, $i, mb_substr(trim($it['particular']), 0, 500), $it['qty'], ($it['unit'] ?? '') !== '' ? mb_substr($it['unit'], 0, 30) : null]);
            $iid = (int)$pdo->lastInsertId();
            foreach ($vIds as $vi => $vid) { $rt = $it['rates'][$vi] ?? null; $rIns->execute([$iid, $vid, $rt, $rt === null ? null : ($it['amounts'][$vi] ?? null)]); }
        }
        if ($own) $pdo->commit();
    } catch (Throwable $e) { if ($own && $pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return $id;
}

/* ======================= Excel (.xlsx) आयात ======================= */

function purchaseCmpCellNum($v) {
    if (is_float($v) || is_int($v)) return (float)$v;
    $s = trim(str_replace([',', '₹', ' '], '', (string)$v));
    return is_numeric($s) ? (float)$s : null;
}
function purchaseCmpIsIncl($v) { return (bool)preg_match('/incl|समाविष्ट/i', (string)$v); }

/** Excel तारीख (serial / "Date : 22.06.2026") → Y-m-d */
function purchaseCmpParseDate($v) {
    if (is_float($v) && $v > 30000 && $v < 80000) return date('Y-m-d', (int)round(($v - 25569) * 86400));
    if (preg_match('/(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{4})/', (string)$v, $m) && checkdate((int)$m[2], (int)$m[1], (int)$m[3])) return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    return null;
}

/**
 * एका Sheet मधून तक्ता शोधतो — "Particular" ओळ = शीर्षक; खालची "Rate | Amount" ओळ; 2-2 रकाने = एक पुरवठादार.
 * Excel मधली रक्कम (Amount) जशीच्या तशी ठेवतो (दर × नग पेक्षा वेगळी असली तरी).
 * परत: null (तक्ता नाही) किंवा purchaseCmpSave() साठी $d (+ 'excel_total' तपासणीसाठी)
 */
function purchaseCmpParseSheet(array $rows, $sheetName) {
    $hdr = null;
    foreach ($rows as $ri => $r) {
        foreach ([1, 2] as $ci) if (preg_match('/^\s*(particular|तपशील)/iu', (string)($r[$ci] ?? ''))) { $hdr = $ri; $pc = $ci; break 2; }
    }
    if ($hdr === null) return null;
    $h = $rows[$hdr]; $sub = $rows[$hdr + 1] ?? [];
    $qc = null; $uc = null;
    foreach ($h as $ci => $v) {
        $t = strtolower(trim((string)$v));
        if ($qc === null && preg_match('/^(quantity|qty|नग)/u', $t)) $qc = $ci;
        elseif ($uc === null && preg_match('/^(units?)$/u', $t)) $uc = $ci;
    }
    if ($qc === null) return null;
    // पुरवठादार = उप-शीर्षकातील प्रत्येक "Rate" रकाना
    $vendors = [];
    foreach ($sub as $ci => $v) {
        if (!preg_match('/^\s*(rate|दर)/iu', (string)$v)) continue;
        $name = trim(preg_replace('/\s+/u', ' ', (string)($h[$ci] ?? '')));
        if ($name === '') continue;
        $vendors[] = ['col' => $ci, 'name' => $name, 'gst_pct' => null, 'gst_amount' => null, 'gst_incl' => 0, 'gst_on_transport' => 0, 'transport' => null, 'transport_incl' => 0,
            'other_label' => '', 'other_amount' => null, 'discount' => null, 'remarks' => ''];
    }
    if (!$vendors) return null;
    $date = null; $heads = [];
    for ($ri = 0; $ri < $hdr; $ri++) {
        $txt = trim(preg_replace('/\s+/u', ' ', implode(' ', array_filter(array_map(fn($x) => trim((string)$x), $rows[$ri] ?? []), 'strlen'))));
        if ($txt === '') continue;
        if (stripos($txt, 'date') !== false && ($dt = purchaseCmpParseDate($txt))) { $date = $dt; continue; }
        if (!preg_match('/compar/i', $txt)) $heads[] = $txt;   // संस्थेचं शीर्षक (!! आत्मा मालिक !!, ट्रस्ट, पत्ता)
    }
    $items = []; $notes = []; $signs = []; $excelTotal = []; $section = ''; $afterTotal = false;
    $sum = array_fill(0, count($vendors), 0.0); $gstSo = array_fill(0, count($vendors), 0.0); $trSo = array_fill(0, count($vendors), 0.0);
    $isTotalLike = function ($vi, $n) use (&$sum, &$gstSo, &$trSo) {
        foreach ([$sum[$vi], $sum[$vi] + $gstSo[$vi], $sum[$vi] + $gstSo[$vi] + $trSo[$vi]] as $t) if ($t > 0 && abs($n - $t) <= max(1, $t * 0.001)) return true;
        return false;
    };
    $extraRe = '/^(total|grand|sub\s*total|एकूण|gst|g\.s\.t|cgst|sgst|igst|tax|discount|सूट|transport|transpor|courier|freight|वाहतूक|unlo|loading|hamali|हमाली|packing|round\s*off|payment|delivery|warranty|गॅरंटी)/iu';
    for ($ri = $hdr + 2; $ri < count($rows); $ri++) {
        $r = $rows[$ri];
        if (!$r) continue;
        $a = trim((string)($r[0] ?? '')); $b = trim(preg_replace('/\s+/u', ' ', (string)($r[$pc] ?? '')));
        $label = $b !== '' ? $b : $a;
        $cells = [];
        foreach ($vendors as $vi => $v) $cells[$vi] = ['rate' => $r[$v['col']] ?? '', 'amt' => $r[$v['col'] + 1] ?? ''];
        $anyVal = (bool)array_filter($cells, fn($x) => trim((string)$x['rate']) !== '' || trim((string)$x['amt']) !== '');
        $rowTxt = trim(implode(' ', array_filter(array_map(fn($x) => trim((string)$x), $r), 'strlen')));
        if (!$anyVal) {
            if ($rowTxt === '') continue;
            if (preg_match('/^(टीप|टिप|note|remark)/iu', $rowTxt)) { $notes[] = trim(preg_replace('/^(टीप|टिप|note|remarks?)\s*[:：-]?\s*/iu', '', $rowTxt)); continue; }
            if (preg_match('/(तयार|prepared|विभाग प्रमुख|खरेदी अधिकारी|खरेदी\s+समिती|purchase\s+committee|manager|secretary|सचिव|अधिकारी|समिती|committee)/iu', $rowTxt)) {
                foreach (preg_split('/\s{3,}/u', $rowTxt) as $sg) { $sg = trim($sg); if ($sg !== '' && mb_strlen($sg) < 60 && !preg_match('/(शाखा|branch|ट्रस्ट|trust|mohili|मोहिली|dist\.|thane)/iu', $sg)) $signs[] = $sg; }
                continue;
            }
            if (preg_match('/(शाखा|branch|ट्रस्ट|trust|mohili|मोहिली|dist\.|thane|ठाणे)/iu', $rowTxt)) continue;
            if ($notes) { $notes[count($notes) - 1] .= ' ' . $rowTxt; continue; }
            // वस्तूंचा गट (उदा. "Ceiling fan") — पुढच्या वस्तूंच्या नावात जोडतो
            if ($b !== '' && trim((string)($r[$qc] ?? '')) === '' && !$afterTotal) { $section = $b; }
            continue;
        }
        if (preg_match('/^\s*rate/i', trim((string)($r[$vendors[0]['col']] ?? '')))) continue;
        $qtyRaw = $r[$qc] ?? ''; $qty = purchaseCmpCellNum($qtyRaw);
        $isExtra = $label === '' || preg_match($extraRe, $label) || ($afterTotal && $qty === null)
            || preg_match('/(discount|सूट|buy\s*back)/iu', $label) || ($b === '' && $a !== '' && !is_numeric($a));
        if ($isExtra) {
            $l = mb_strtolower($label);
            $isTot = $label === '' || preg_match('/^(total|grand|sub\s*total|एकूण)/iu', $label);
            foreach ($vendors as $vi => &$v) {
                $rawA = $cells[$vi]['amt']; $rawR = $cells[$vi]['rate'];
                $raw = trim((string)$rawA) !== '' ? $rawA : $rawR;
                $n = purchaseCmpCellNum($raw);
                $txt = trim((string)$raw);
                $incl = purchaseCmpIsIncl($rawA) || purchaseCmpIsIncl($rawR);
                if ($isTot) { if ($n !== null) $excelTotal[$vi] = $n; continue; }
                if (preg_match('/^(gst|g\.s\.t|tax|cgst|sgst|igst)/i', $l)) {
                    if (preg_match('/(\d+(?:\.\d+)?)\s*%/', $label, $pm) && !preg_match('/&|,|\//', $label)) $v['gst_pct'] = (float)$pm[1];
                    if ($incl) $v['gst_incl'] = 1;
                    elseif ($n !== null && !$isTotalLike($vi, $n)) { $v['gst_amount'] = ($v['gst_amount'] ?? 0) + $n; $gstSo[$vi] += $n; }
                    else { $v['gst_amount'] = $v['gst_amount'] ?? 0; if (preg_match('/extra|अतिरिक्त/i', $txt)) $v['remarks'] = trim($v['remarks'] . ' GST अतिरिक्त (रक्कम दिलेली नाही).'); }
                    if ($trSo[$vi] > 0) $v['gst_on_transport'] = 1;
                } elseif (preg_match('/(discount|सूट|buy\s*back)/iu', $l)) { if ($n !== null && abs($n) > 0) $v['discount'] = ($v['discount'] ?? 0) + abs($n); if (!preg_match('/^discount/i', $label)) $v['remarks'] = trim($v['remarks'] . ' ' . $label . ' — वजा.'); }
                elseif (preg_match('/^(transport|transpor|courier|freight|वाहतूक)/iu', $l)) {
                    if ($incl) $v['transport_incl'] = 1;
                    elseif ($n !== null && $n > 0 && !$isTotalLike($vi, $n)) { $v['transport'] = ($v['transport'] ?? 0) + $n; $trSo[$vi] += $n; }
                    elseif ($n === null && $txt !== '' && $txt !== '-') $v['remarks'] = trim($v['remarks'] . ' वाहतूक: ' . $txt . '.');
                } elseif (preg_match('/^(payment|delivery|warranty|गॅरंटी)/iu', $l)) {
                    if ($txt !== '' && $txt !== '-') $v['remarks'] = trim($v['remarks'] . ' ' . $label . ': ' . $txt . '.');
                } else {
                    if ($incl) $v['remarks'] = trim($v['remarks'] . ' ' . $label . ': समाविष्ट.');
                    elseif ($n !== null && abs($n) > 0 && !$isTotalLike($vi, $n)) {
                        if (preg_match('/(old|जुन|buy\s*back|exchange|less|वजा)/iu', $label)) { $v['discount'] = ($v['discount'] ?? 0) + abs($n); $v['remarks'] = trim($v['remarks'] . ' ' . mb_strimwidth($label, 0, 60, '…') . ' — वजा.'); }
                        else { $v['other_label'] = mb_substr($label, 0, 100); $v['other_amount'] = ($v['other_amount'] ?? 0) + $n; $trSo[$vi] += $n; }
                    } elseif ($n === null && $txt !== '' && $txt !== '-') $v['remarks'] = trim($v['remarks'] . ' ' . $label . ': ' . $txt . '.');
                }
            }
            unset($v);
            if ($isTot) $afterTotal = true;
            continue;
        }
        if ($b === '') continue;
        // वस्तू — दर व Excel मधील रक्कम
        $unit = $uc !== null ? trim((string)($r[$uc] ?? '')) : '';
        $rates = []; $amts = [];
        foreach ($vendors as $vi => $v) {
            $rate = purchaseCmpCellNum($cells[$vi]['rate']); $amt = purchaseCmpCellNum($cells[$vi]['amt']);
            if (($rate === null || $rate == 0) && ($amt === null || $amt == 0)) { $rates[$vi] = null; $amts[$vi] = null; continue; }
            $rates[$vi] = $rate ?? ($amt !== null && $qty ? $amt / $qty : $amt);
            $amts[$vi] = $amt;
        }
        if ($qty === null) {   // उदा. "5gm (10)" — नग = रक्कम ÷ दर
            foreach ($vendors as $vi => $_) if ($rates[$vi] && $amts[$vi]) { $qty = round($amts[$vi] / $rates[$vi], 3); break; }
            $qty = $qty ?: 1;
            if (trim((string)$qtyRaw) !== '') $unit = trim($unit . ' ' . trim((string)$qtyRaw));
        }
        $am = [];
        foreach ($vendors as $vi => $_) {
            $am[$vi] = ($amts[$vi] !== null && $rates[$vi] !== null && abs($amts[$vi] - $rates[$vi] * $qty) > 0.5) ? $amts[$vi] : null;
            if ($rates[$vi] !== null) $sum[$vi] += $am[$vi] ?? $rates[$vi] * $qty;
        }
        $items[] = ['particular' => ($section !== '' ? $section . ' — ' : '') . $b, 'qty' => $qty, 'unit' => $unit, 'rates' => $rates, 'amounts' => $am];
    }
    if (!$items) return null;
    foreach ($vendors as &$v) unset($v['col']);
    unset($v);
    $sn = trim($sheetName);
    $title = (preg_match('/^(sheet\s*\d+|[\d.\s]+)$/i', $sn) || $sn === '') ? mb_strimwidth($items[0]['particular'], 0, 80, '…') . (count($items) > 1 ? ' इ. (' . count($items) . ' वस्तू)' : '') : $sn;
    return ['title' => $title, 'cmp_date' => $date, 'department' => '', 'request_id' => null, 'notes' => implode("\n", $notes), 'signatories' => $signs ? implode("\n", array_unique($signs)) : purchaseCmpDefaultSigns(),
        'status' => 'final', 'source' => 'Excel: ' . $sn, 'vendors' => $vendors, 'items' => $items, 'excel_total' => $excelTotal, 'head' => implode("\n", array_slice($heads, 0, 4))];
}

/** संपूर्ण Workbook — परत: [['sheet' => नाव, 'chart' => $d|null], …] */
function purchaseCmpParseWorkbook($path) {
    require_once __DIR__ . '/sheet-reader.php';
    $names = eowmsReadXlsx($path, 'names');
    $out = [];
    foreach ($names as $i => $n) {
        try { $rows = eowmsReadXlsx($path, $i); } catch (Throwable $e) { $rows = []; }
        $out[] = ['sheet' => $n, 'chart' => $rows ? purchaseCmpParseSheet($rows, $n) : null];
    }
    return $out;
}

/** आयात केलेल्या $d चा हिशोब (DB शिवाय) — preview साठी */
function purchaseCmpComputeDraft(array $d) {
    $c = ['vendors' => [], 'items' => [], 'rates' => [], 'amounts' => []];
    foreach (array_values($d['vendors']) as $i => $v) $c['vendors'][] = $v + ['id' => $i];
    foreach (array_values($d['items']) as $j => $it) {
        $c['items'][] = ['id' => $j, 'qty' => $it['qty'], 'particular' => $it['particular']];
        foreach ($it['rates'] as $vi => $r) { $c['rates'][$j][$vi] = $r; if (($it['amounts'][$vi] ?? null) !== null) $c['amounts'][$j][$vi] = $it['amounts'][$vi]; }
    }
    return purchaseCmpCompute($c);
}
