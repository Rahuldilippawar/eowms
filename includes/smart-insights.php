<?php
// 🧠 Module-पानांसाठी एकसमान Smart आढावा — आकडे (Tiles), "लक्ष द्या" सूचना आणि
// Bar-यादी. प्रत्येक Module चा Data includes/insights/<module>.php मध्ये काढला जातो.

/** $tiles: [['label' => .., 'value' => .., 'sub' => .., 'tone' => 'ok|warn|bad', 'href' => ..], …] */
function smartTiles(array $tiles) {
    if (!$tiles) return;
    echo '<div class="hi-totals mt-3 mb-3" style="grid-template-columns:repeat(' . min(5, count($tiles)) . ',minmax(0,1fr));">';
    foreach ($tiles as $t) {
        $tone = ['ok' => 'c-ok', 'warn' => 'c-amber', 'bad' => 'c-rose'][$t['tone'] ?? ''] ?? '';
        $tag = !empty($t['href']) ? 'a href="' . h($t['href']) . '" style="text-decoration:none;color:inherit;"' : 'div';
        echo '<' . $tag . '><span>' . h($t['label']) . '</span><b class="' . $tone . '">' . h((string)$t['value']) . '</b>'
            . (isset($t['sub']) && $t['sub'] !== '' ? '<small>' . h($t['sub']) . '</small>' : '') . '</' . (!empty($t['href']) ? 'a' : 'div') . '>';
    }
    echo '</div>';
}

/** $alerts: [['bad|warn|info', 'मजकूर', 'link'], …] — रिकामं असेल तर काहीच दाखवत नाही */
function smartAlerts(array $alerts) {
    if (!$alerts) return;
    echo '<div class="sm-attn mb-3"><div class="sm-attn-title"><i class="fa-solid fa-bell"></i> लक्ष द्या</div><div class="sm-attn-list">';
    foreach ($alerts as [$sev, $text, $href]) {
        echo '<a class="sm-attn-item ' . h($sev) . '" href="' . h($href ?: '#') . '"><span>' . h($text) . '</span><i class="fa-solid fa-chevron-right sm-go"></i></a>';
    }
    echo '</div></div>';
}

/** Bar-यादी Panel — $rows: [['name' => .., 'value' => n, 'label' => 'दाखवायचा मजकूर'], …] */
function smartBars($title, $sub, array $rows, $color = 'var(--rail)', $empty = 'नोंदी नाहीत') {
    echo '<div class="panel h-100"><div class="panel-head"><div class="title">' . h($title) . '</div>' . ($sub ? '<div class="sub">' . h($sub) . '</div>' : '') . '</div><div style="padding:12px 20px;">';
    if (!$rows) { echo '<div class="sm-empty">' . h($empty) . '</div>'; }
    $max = $rows ? max(1, max(array_map(fn($r) => (float)$r['value'], $rows))) : 1;
    foreach ($rows as $r) {
        echo '<div class="wl-row"><div class="wl-name" title="' . h($r['name']) . '">' . h($r['name']) . '</div>'
            . '<div class="wl-bar"><i style="width:' . round((float)$r['value'] * 100 / $max) . '%;background:' . ($r['color'] ?? $color) . ';"></i></div>'
            . '<div class="wl-num font-mono">' . h($r['label'] ?? (string)$r['value']) . '</div></div>';
    }
    echo '</div></div>';
}
