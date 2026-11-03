<?php
// 🏠 रहिवासी — Smart आढावा (residency/index.php मधून; $rooms आधीच आहेत)
// भरती %, प्रलंबित वाटप-विनंत्या, तपासणी न झालेल्या/खराब खोल्या, मुदत संपलेले पाहुणे,
// व मागील महिन्याचं बिल न झालेल्या भरलेल्या खोल्या.
require_once __DIR__ . '/../smart-insights.php';
try {
    $occupied = array_filter($rooms, fn($r) => $r['occupant_count'] > 0);
    $residents = array_sum(array_map(fn($r) => (int)$r['occupant_count'], $rooms));
    $occPct = $rooms ? (int)round(count($occupied) * 100 / count($rooms)) : 0;
    $q = function ($sql) use ($pdo, $orgId) { $st = $pdo->prepare($sql); $st->execute([$orgId]); return (int)$st->fetchColumn(); };
    $pendingReq = 0; try { $pendingReq = $q("SELECT COUNT(*) FROM residency_allotment_requests WHERE organization_id = ? AND status IN ('pending','waitlisted')"); } catch (PDOException $e) {}
    $noInspect = $q("SELECT COUNT(*) FROM maintenance_locations ml WHERE ml.organization_id = ? AND ml.is_active = 1 AND ml.is_residential = 1
        AND NOT EXISTS (SELECT 1 FROM residency_inspections i WHERE i.location_id = ml.id AND i.inspection_date >= DATE_SUB(CURDATE(), INTERVAL 180 DAY))");
    $poor = $q("SELECT COUNT(*) FROM residency_inspections i WHERE i.organization_id = ? AND i.condition_rating = 'poor'
        AND i.inspection_date = (SELECT MAX(i2.inspection_date) FROM residency_inspections i2 WHERE i2.location_id = i.location_id)");
    $guestOver = $q("SELECT COUNT(*) FROM residency_guests WHERE organization_id = ? AND status = 'checked_in' AND expected_departure_date < CURDATE()");
    $guestsIn = $q("SELECT COUNT(*) FROM residency_guests WHERE organization_id = ? AND status = 'checked_in'");
    $lastMonth = date('Y-m', strtotime('first day of last month'));
    $st = $pdo->prepare("SELECT COUNT(DISTINCT location_id) FROM residency_bills WHERE organization_id = ? AND billing_month = ?");
    $st->execute([$orgId, $lastMonth]);
    $billed = (int)$st->fetchColumn();
    $unbilled = max(0, count($occupied) - $billed);

    smartTiles([
        ['label' => 'भरती', 'value' => $occPct . '%', 'sub' => count($occupied) . ' / ' . count($rooms) . ' खोल्या · ' . $residents . ' रहिवासी', 'tone' => $occPct >= 90 ? 'warn' : 'ok'],
        ['label' => 'वाटप-विनंत्या प्रलंबित', 'value' => $pendingReq, 'tone' => $pendingReq ? 'warn' : '', 'href' => 'manage-requests.php'],
        ['label' => 'सध्या पाहुणे', 'value' => $guestsIn, 'sub' => $guestOver ? $guestOver . ' मुदतीपेक्षा जास्त' : '', 'href' => 'guests.php'],
        ['label' => '६ महिन्यांत तपासणी नाही', 'value' => $noInspect, 'tone' => $noInspect ? 'warn' : 'ok', 'href' => 'inspections.php'],
    ]);
    $al = [];
    if ($pendingReq && count($rooms) - count($occupied) > 0) $al[] = ['warn', $pendingReq . ' वाटप-विनंत्या प्रलंबित आणि ' . (count($rooms) - count($occupied)) . ' खोल्या रिकाम्या — वाटप करता येईल', 'manage-requests.php'];
    if ($poor) $al[] = ['bad', $poor . ' खोल्यांची शेवटची तपासणी "खराब" — दुरुस्ती आवश्यक', 'inspections.php'];
    if ($guestOver) $al[] = ['warn', $guestOver . ' पाहुणे जाण्याच्या अपेक्षित तारखेनंतरही राहत आहेत', 'guests.php'];
    if ($unbilled && (int)date('j') >= 5) $al[] = ['info', date('M Y', strtotime($lastMonth . '-01')) . ' — ' . $unbilled . ' भरलेल्या खोल्यांचं बिल अजून तयार केलेलं नाही', 'bills.php'];
    smartAlerts($al);
} catch (PDOException $e) {}
