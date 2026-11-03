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
    smartAlerts($al);
} catch (PDOException $e) {}
