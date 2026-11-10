<?php
// 📦 Dead Stock — Smart आढावा (deadstock/index.php मधून; $items त्याच पानाच्या Scope प्रमाणे)
// स्थितीनुसार (चांगली/दुरुस्तीयोग्य/खराब/भंगार) संख्या व किंमत, जुन्या वस्तू,
// या वर्षीची खरेदी व प्रकारनिहाय किंमत.
require_once __DIR__ . '/../smart-insights.php';
if ($items) {
    $cond = ['चांगली' => [0, 0.0], 'दुरुस्तीयोग्य' => [0, 0.0], 'खराब' => [0, 0.0], 'भंगार' => [0, 0.0]];
    $byCat = []; $byDept = []; $byYear = []; $old = 0; $thisYear = 0; $thisYearVal = 0.0;
    foreach ($items as $it) {
        $c = $it['condition'] ?? 'चांगली';
        if (!isset($cond[$c])) $cond[$c] = [0, 0.0];
        $cond[$c][0] += (int)$it['quantity']; $cond[$c][1] += (float)$it['purchase_cost'];
        $dn = trim((string)($it['dept_name'] ?? '')) ?: 'सामायिक';
        $byDept[$dn] = ($byDept[$dn] ?? 0) + (float)$it['purchase_cost'];
        if (!empty($it['purchase_date'])) { $yy = substr($it['purchase_date'], 0, 4); $byYear[$yy] = ($byYear[$yy] ?? 0) + (float)$it['purchase_cost']; }
        $cat = trim((string)$it['category']) ?: 'इतर';
        $byCat[$cat] = ($byCat[$cat] ?? 0) + (float)$it['purchase_cost'];
        if (!empty($it['purchase_date'])) {
            if (strtotime($it['purchase_date']) < strtotime('-10 years')) $old++;
            if (substr($it['purchase_date'], 0, 4) === date('Y')) { $thisYear++; $thisYearVal += (float)$it['purchase_cost']; }
        }
    }
    arsort($byCat);
    $needAction = $cond['दुरुस्तीयोग्य'][0] + $cond['खराब'][0] + $cond['भंगार'][0];
    smartTiles([
        ['label' => '✅ चांगल्या स्थितीत', 'value' => $cond['चांगली'][0], 'tone' => 'ok', 'sub' => '₹' . number_format($cond['चांगली'][1])],
        ['label' => '🔧 दुरुस्तीयोग्य', 'value' => $cond['दुरुस्तीयोग्य'][0], 'tone' => $cond['दुरुस्तीयोग्य'][0] ? 'warn' : '', 'sub' => '₹' . number_format($cond['दुरुस्तीयोग्य'][1])],
        ['label' => '❌ खराब / भंगार', 'value' => $cond['खराब'][0] + $cond['भंगार'][0], 'tone' => ($cond['खराब'][0] + $cond['भंगार'][0]) ? 'bad' : '', 'sub' => '₹' . number_format($cond['खराब'][1] + $cond['भंगार'][1])],
        ['label' => 'या वर्षी खरेदी', 'value' => $thisYear, 'sub' => '₹' . number_format($thisYearVal)],
        ['label' => '१० वर्षांपेक्षा जुन्या', 'value' => $old],
    ]);
    $al = [];
    if ($cond['दुरुस्तीयोग्य'][0]) $al[] = ['warn', $cond['दुरुस्तीयोग्य'][0] . ' वस्तू दुरुस्त केल्यास पुन्हा वापरात येतील (₹' . number_format($cond['दुरुस्तीयोग्य'][1]) . ')', ''];
    if ($cond['भंगार'][0]) $al[] = ['info', $cond['भंगार'][0] . ' भंगार वस्तू — विल्हेवाट/लिलावाचा विचार करा', ''];
    smartAlerts($al);
    arsort($byDept); ksort($byYear);
    if (count($byCat) > 1 || count($byDept) > 1) {
        echo '<div class="row g-3 mb-3"><div class="col-lg-4">';
        smartBars('💰 प्रकारनिहाय किंमत', 'खरेदी-किमतीनुसार', array_map(fn($k, $v) => ['name' => $k, 'value' => $v, 'label' => '₹' . number_format($v)], array_slice(array_keys($byCat), 0, 8), array_slice(array_values($byCat), 0, 8)));
        echo '</div><div class="col-lg-4">';
        smartBars('🏢 विभागनिहाय किंमत', '', array_map(fn($k, $v) => ['name' => $k, 'value' => $v, 'label' => '₹' . number_format($v)], array_slice(array_keys($byDept), 0, 8), array_slice(array_values($byDept), 0, 8)), 'var(--amber)');
        echo '</div><div class="col-lg-4">';
        $yrs = array_slice($byYear, -8, null, true);
        smartBars('📅 वर्षनिहाय खरेदी', 'मागची 8 वर्षं', array_map(fn($k, $v) => ['name' => (string)$k, 'value' => $v, 'label' => '₹' . number_format($v)], array_keys($yrs), array_values($yrs)), '#3B6FC9', 'खरेदी-दिनांक नोंदवलेले नाहीत');
        echo '</div></div>';
    }
}
