<?php
// गौशाळा — सामायिक Helper Functions
function gsCategoryLabels() {
    return ['Cow' => '🐄 गाय', 'Calf' => '🐮 वासरू', 'Young Bull' => '🐂 गोऱ्हा', 'Bull/Ox' => '🐂 बैल'];
}
function gsHasTag($tag) { return $tag !== null && $tag !== ''; }

// Batch असेल तर तो; नसेल तर नाव किंवा "बिना Batch #ID"
function gsCattleTitle($tag, $name, $id) {
    if (gsHasTag($tag)) { return $tag . ($name ? ' — ' . $name : ''); }
    return $name ? $name . ' (बिना Batch #' . $id . ')' : 'बिना Batch #' . $id;
}

// वय (महिन्यांत): जन्म-दिनांक असेल तर त्यावरून; नसेल तर अंदाजे वयावरून (नोंदीपासून आपोआप पुढे सरकतं)
function gsAgeMonths($dob, $approxMonths, $createdAt) {
    try {
        if (!empty($dob)) {
            $diff = (new DateTime($dob))->diff(new DateTime());
            return $diff->invert ? 0 : ($diff->y * 12 + $diff->m);
        }
        if ($approxMonths !== null && $approxMonths !== '') {
            $diff = (new DateTime($createdAt ?: 'now'))->diff(new DateTime());
            return (int)$approxMonths + ($diff->invert ? 0 : ($diff->y * 12 + $diff->m));
        }
    } catch (Exception $e) { /* चुकीची तारीख */ }
    return null;
}
function gsAgeText($months) {
    if ($months === null) { return '—'; }
    if ($months < 12) { return $months . ' महिने'; }
    $y = intdiv($months, 12);
    $m = $months % 12;
    return $y . ' वर्ष' . ($m ? ' ' . $m . ' महिने' : '');
}

// जात + रंगानुसार गट: संख्या आणि सरासरी वय
function gsBreakdown($rows) {
    $out = [];
    foreach ($rows as $r) {
        $breed = !empty($r['breed']) ? $r['breed'] : 'अज्ञात';
        $color = !empty($r['color']) ? $r['color'] : 'अज्ञात';
        $key = $breed . '|' . $color;
        if (!isset($out[$key])) { $out[$key] = ['breed' => $breed, 'color' => $color, 'count' => 0, 'age_sum' => 0, 'age_n' => 0]; }
        $out[$key]['count']++;
        if (isset($r['age_months']) && $r['age_months'] !== null) { $out[$key]['age_sum'] += $r['age_months']; $out[$key]['age_n']++; }
    }
    foreach ($out as &$o) { $o['avg_months'] = $o['age_n'] > 0 ? (int)round($o['age_sum'] / $o['age_n']) : null; }
    unset($o);
    uasort($out, function ($a, $b) { return $b['count'] <=> $a['count']; });
    return array_values($out);
}
