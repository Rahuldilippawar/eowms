<?php
// 🖥️ IT — "लक्ष द्या" सूचना: जुनी दुरुस्ती-Tickets, संपणारी Warranty व License.
require_once __DIR__ . '/../smart-insights.php';
try {
    $st = $pdo->prepare("SELECT
            (SELECT COUNT(*) FROM it_repair_tickets WHERE organization_id = ? AND status IN ('pending','in_progress') AND created_at < DATE_SUB(NOW(), INTERVAL 3 DAY)) old_tickets,
            (SELECT COUNT(*) FROM it_assets WHERE organization_id = ? AND warranty_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)) warranty_soon,
            (SELECT COUNT(*) FROM it_assets WHERE organization_id = ? AND license_expiry < CURDATE()) license_expired,
            (SELECT COUNT(*) FROM it_assets WHERE organization_id = ? AND license_expiry BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)) license_soon");
    $st->execute([$orgId, $orgId, $orgId, $orgId]);
    $ic = array_map('intval', $st->fetch() ?: []);
    $al = [];
    if (!empty($ic['old_tickets'])) $al[] = ['bad', '🔧 ' . $ic['old_tickets'] . ' दुरुस्ती-Tickets ३ दिवसांपेक्षा जास्त प्रलंबित', 'index.php'];
    if (!empty($ic['license_expired'])) $al[] = ['bad', '🔑 ' . $ic['license_expired'] . ' Software License ची मुदत संपली', ''];
    if (!empty($ic['license_soon'])) $al[] = ['warn', '🔑 ' . $ic['license_soon'] . ' License ३० दिवसांत संपणार', ''];
    if (!empty($ic['warranty_soon'])) $al[] = ['info', '🛡️ ' . $ic['warranty_soon'] . ' Assets ची Warranty ३० दिवसांत संपणार', ''];
    $tq = $pdo->prepare("SELECT
            (SELECT COUNT(*) FROM it_assets WHERE organization_id = ?) assets,
            (SELECT COALESCE(SUM(purchase_cost), 0) FROM it_assets WHERE organization_id = ?) value,
            (SELECT COUNT(*) FROM it_repair_tickets WHERE organization_id = ? AND status IN ('pending','in_progress')) open_t,
            (SELECT COUNT(*) FROM it_repair_tickets WHERE organization_id = ? AND status = 'resolved' AND resolved_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)) done30,
            (SELECT AVG(TIMESTAMPDIFF(HOUR, created_at, resolved_at)) FROM it_repair_tickets WHERE organization_id = ? AND status = 'resolved' AND resolved_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)) avg_h,
            (SELECT COUNT(*) FROM it_assets WHERE organization_id = ? AND purchase_date IS NOT NULL AND purchase_date < DATE_SUB(CURDATE(), INTERVAL 5 YEAR)) old5");
    $tq->execute([$orgId, $orgId, $orgId, $orgId, $orgId, $orgId]);
    $t = $tq->fetch() ?: [];
    $avgH = (float)($t['avg_h'] ?? 0);
    smartTiles([
        ['label' => 'एकूण Assets', 'value' => (int)$t['assets'], 'sub' => '₹' . number_format((float)$t['value'])],
        ['label' => 'उघडी दुरुस्ती-Tickets', 'value' => (int)$t['open_t'], 'tone' => (int)$t['open_t'] ? 'warn' : 'ok'],
        ['label' => '30 दिवसांत दुरुस्त', 'value' => (int)$t['done30'], 'tone' => 'ok'],
        ['label' => 'सरासरी दुरुस्ती-वेळ', 'value' => !$avgH ? '—' : ($avgH >= 24 ? round($avgH / 24, 1) . ' दिवस' : round($avgH) . ' तास'), 'sub' => 'मागील 90 दिवस'],
        ['label' => '5 वर्षांपेक्षा जुने', 'value' => (int)$t['old5'], 'sub' => 'बदलण्याचा विचार'],
    ]);
    $types = ['computer' => 'Computer', 'monitor' => 'Monitor', 'keyboard' => 'Keyboard', 'mouse' => 'Mouse', 'cpu' => 'CPU', 'ups' => 'UPS', 'smartboard' => 'Smart Board', 'projector' => 'Projector', 'printer' => 'Printer', 'other' => 'इतर'];
    $ty = $pdo->prepare("SELECT asset_type, COUNT(*) n FROM it_assets WHERE organization_id = ? GROUP BY asset_type ORDER BY n DESC");
    $ty->execute([$orgId]);
    $byType = $ty->fetchAll();
    $lb = $pdo->prepare("SELECT l.lab_name, COUNT(t.id) n FROM it_repair_tickets t JOIN it_labs l ON l.id = t.lab_id WHERE t.organization_id = ? AND t.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY) GROUP BY l.id, l.lab_name ORDER BY n DESC LIMIT 8");
    $lb->execute([$orgId]);
    $byLab = $lb->fetchAll();
    if ($byType || $byLab) {
        echo '<div class="row g-3 mb-3"><div class="col-lg-6">';
        smartBars('🧩 Assets — प्रकारानुसार', '', array_map(fn($r) => ['name' => $types[$r['asset_type']] ?? $r['asset_type'], 'value' => (int)$r['n']], $byType), '#3B6FC9', 'Assets नाहीत');
        echo '</div><div class="col-lg-6">';
        smartBars('🔧 सर्वाधिक बिघाड — Labनिहाय', 'मागील 90 दिवसांची Tickets', array_map(fn($r) => ['name' => $r['lab_name'], 'value' => (int)$r['n']], $byLab), 'var(--amber)', '✅ 90 दिवसांत Ticket नाही');
        echo '</div></div>';
    }
} catch (PDOException $e) {}
