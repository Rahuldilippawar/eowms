<?php
/**
 * भांडार Material-Master — Helper Functions
 * (Purchase/Store चं Formal Workflow काढून टाकलं — फक्त Daily-Work च्या
 * "वापरलेलं साहित्य" साठी लागणारी Material-Name यादी इथे ठेवली जाते.)
 */
function requireStoreAccess($pdo, $user, $rootPath = '../') {
    $deptCheck = $pdo->prepare("SELECT code FROM departments WHERE id = ?");
    $deptCheck->execute([$user['department_id'] ?? 0]);
    $isStoreStaff = ($deptCheck->fetch()['code'] ?? '') === 'STR';
    $canManage = $isStoreStaff || hasPermission('manage_store') || isManagementOrAbove();
    if (!$canManage) {
        http_response_code(403);
        die('<div style="font-family:sans-serif;padding:40px;">तुम्हाला ही यादी पाहण्याची परवानगी नाही. <a href="' . htmlspecialchars($rootPath) . 'index.php">Dashboard var परत जा</a></div>');
    }
    return $canManage;
}

// ── 📦 Stock (आवक / जावक) ──
// move_type: opening (सुरुवातीची शिल्लक, +), purchase (खरेदी/आवक, +), return (परत आलं, +),
//            issue (विभागाला दिलं, −), work (कामात वापर — दैनंदिन नोंदीतून आपोआप, −), adjust (दुरुस्ती, ±)
function storeMoveTypes() {
    return [
        'opening'  => ['🟦 सुरुवातीची शिल्लक', 1],
        'purchase' => ['🟢 खरेदी / आवक', 1],
        'return'   => ['↩️ परत आलं', 1],
        'issue'    => ['🟠 वाटप / जावक', -1],
        'work'     => ['🛠️ कामात वापर', -1],
        'adjust'   => ['⚖️ दुरुस्ती', 0],
    ];
}

function storeStockReady($pdo) {
    static $ok = null;
    if ($ok === null) {
        try { $pdo->query("SELECT 1 FROM store_stock_moves LIMIT 1"); $ok = true; } catch (PDOException $e) { $ok = false; }
    }
    return $ok;
}

/** प्रत्येक वस्तूची शिल्लक, शेवटचा खरेदी दर, मूल्य → [item_id => [...]] */
function storeBalances($pdo, $orgId) {
    $out = [];
    if (!storeStockReady($pdo)) return $out;
    $q = $pdo->prepare("SELECT m.item_id, SUM(m.quantity) bal,
            SUM(CASE WHEN m.quantity > 0 AND m.move_type <> 'adjust' THEN m.quantity ELSE 0 END) qin,
            SUM(CASE WHEN m.quantity < 0 AND m.move_type <> 'adjust' THEN -m.quantity ELSE 0 END) qout,
            (SELECT m2.rate FROM store_stock_moves m2 WHERE m2.item_id = m.item_id AND m2.rate IS NOT NULL AND m2.rate > 0 ORDER BY m2.move_date DESC, m2.id DESC LIMIT 1) last_rate,
            MAX(m.move_date) last_move, COUNT(*) moves
        FROM store_stock_moves m WHERE m.organization_id = ? GROUP BY m.item_id");
    $q->execute([$orgId]);
    foreach ($q->fetchAll() as $r) {
        $r['bal'] = (float)$r['bal'];
        $r['value'] = $r['last_rate'] !== null ? max(0, $r['bal']) * (float)$r['last_rate'] : 0;
        $out[(int)$r['item_id']] = $r;
    }
    return $out;
}

function storeQty($n) { return rtrim(rtrim(number_format((float)$n, 2, '.', ''), '0'), '.'); }

/** भांडारच्या पानांवरची वरची Tab-पट्टी */
function storeNav($active) {
    $tabs = ['index' => ['📊', 'Dashboard'], 'stock' => ['📦', 'शिल्लक'], 'move' => ['➕', 'आवक / जावक'], 'ledger' => ['📒', 'नोंदवही'], 'vendors' => ['🏪', 'Vendor'], 'items' => ['📋', 'वस्तू यादी']];
    $h = '<div class="st-nav">';
    foreach ($tabs as $k => [$ic, $label]) $h .= '<a href="' . $k . '.php" class="' . ($k === $active ? 'on' : '') . '">' . $ic . ' ' . htmlspecialchars($label) . '</a>';
    return $h . '</div><style>.st-nav{display:flex;gap:6px;overflow-x:auto;margin:6px 0 16px;padding-bottom:2px;}.st-nav a{white-space:nowrap;padding:7px 14px;border-radius:20px;border:1px solid var(--line);font-size:12.5px;font-weight:600;color:var(--text);text-decoration:none;background:var(--panel,#fff);}.st-nav a.on{background:var(--rail);border-color:var(--rail);color:#fff;}</style>';
}

/** दैनंदिन कामात वापरलेलं साहित्य → Stock मधून आपोआप वजा */
function storeRecordWorkUsage($pdo, $orgId, $workRecordId, $itemId, $qty, $workDate, $departmentId, $userId) {
    if (!storeStockReady($pdo) || $qty <= 0) return;
    try {
        $pdo->prepare("INSERT INTO store_stock_moves (organization_id, item_id, move_type, quantity, move_date, department_id, work_record_id, notes, created_by) VALUES (?, ?, 'work', ?, ?, ?, ?, 'दैनंदिन कामाची नोंद', ?)")
            ->execute([$orgId, $itemId, -$qty, $workDate ?: date('Y-m-d'), $departmentId ?: null, $workRecordId, $userId]);
    } catch (PDOException $e) {}
}

function storeStyles() {
    return '<style>
.st-chips{display:flex;flex-wrap:wrap;gap:8px;align-items:center;margin-bottom:14px;}
.st-chips button{border:1px solid var(--line);background:var(--panel,#fff);border-radius:20px;padding:6px 13px;font-size:12.5px;font-weight:600;color:var(--text);}
.st-chips button b{margin-left:4px;font-family:var(--font-mono,monospace);}
.st-chips button.on{background:var(--rail);border-color:var(--rail);color:#fff;}
.st-b{display:inline-block;padding:2px 9px;border-radius:20px;font-size:11px;font-weight:700;}
.st-b.ok{background:#DCFCE7;color:#166534;}.st-b.low{background:#FEF3C7;color:#92400E;}.st-b.out{background:#FEE2E2;color:#991B1B;}.st-b.none{background:#F1F5F9;color:#64748B;}
.st-b.in{background:#DCFCE7;color:#166534;}.st-b.outm{background:#FFEDD5;color:#9A3412;}.st-b.adj{background:#E0E7FF;color:#3730A3;}
.st-a{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;border-radius:7px;border:1px solid var(--line);text-decoration:none;color:var(--text);font-weight:700;margin-right:2px;}
.st-a:hover{background:var(--rail);color:#fff;border-color:var(--rail);}
@media print{.no-print,.st-nav,.st-chips,.sidebar,.topbar{display:none !important;}}
</style>';
}
