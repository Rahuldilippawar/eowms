<?php
// 📐 Room/Hall Booking — Smart आढावा (room-booking/index.php मधून)
// आज/आत्ता कोणती खोली वापरात, आठवड्याचा वापर (तास) व सर्वाधिक मागणीच्या खोल्या.
require_once __DIR__ . '/../smart-insights.php';
try {
    $st = $pdo->prepare("SELECT
            SUM(DATE(rb.start_time) = CURDATE()) today,
            SUM(NOW() BETWEEN rb.start_time AND rb.end_time) now_busy,
            SUM(rb.start_time BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)) next7,
            SUM(rb.booked_by = ? AND rb.start_time >= NOW()) mine
        FROM room_bookings rb JOIN bookable_rooms br ON br.id = rb.room_id
        WHERE br.organization_id = ? AND rb.status = 'confirmed'");
    $st->execute([$user['id'], $orgId]);
    $rc = array_map('intval', $st->fetch() ?: []);
    $st = $pdo->prepare("SELECT br.name, ROUND(SUM(TIMESTAMPDIFF(MINUTE, rb.start_time, rb.end_time)) / 60, 1) hrs, COUNT(*) cnt
        FROM room_bookings rb JOIN bookable_rooms br ON br.id = rb.room_id
        WHERE br.organization_id = ? AND rb.status = 'confirmed' AND rb.start_time >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
        GROUP BY br.id, br.name ORDER BY hrs DESC LIMIT 8");
    $st->execute([$orgId]);
    $use = $st->fetchAll();
    $roomCount = count($rooms ?? []);
    smartTiles([
        ['label' => 'खोल्या / हॉल', 'value' => $roomCount],
        ['label' => 'आत्ता वापरात', 'value' => $rc['now_busy'] ?? 0, 'sub' => max(0, $roomCount - ($rc['now_busy'] ?? 0)) . ' मोकळ्या', 'tone' => 'warn'],
        ['label' => 'आजची Bookings', 'value' => $rc['today'] ?? 0, 'href' => '?date=' . date('Y-m-d')],
        ['label' => 'पुढील ७ दिवस', 'value' => $rc['next7'] ?? 0],
        ['label' => 'माझ्या आगामी', 'value' => $rc['mine'] ?? 0, 'tone' => 'ok'],
    ]);
    if ($use) {
        echo '<div class="row g-3 mb-3"><div class="col-lg-7">';
        smartBars('📊 सर्वाधिक वापरलेल्या खोल्या — मागील ३० दिवस', 'एकूण Booked तास · कमी वापरलेल्या खोल्या इतर कामासाठी वापरता येतील',
            array_map(fn($r) => ['name' => $r['name'], 'value' => (float)$r['hrs'], 'label' => $r['hrs'] . ' तास · ' . $r['cnt'] . '×'], $use), 'var(--amber)');
        echo '</div></div>';
    }
} catch (PDOException $e) {}
