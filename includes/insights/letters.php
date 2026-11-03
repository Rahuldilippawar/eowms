<?php
// 📨 पत्र व्यवहार — Smart आढावा (letters/index.php मधून). स्वतःचा Inbox/पाठवलेले,
// आणि manage_letters_org_wide असल्यास संस्थेतील "कुठे अडकलं" (Bottleneck) चित्र.
require_once __DIR__ . '/../smart-insights.php';
try {
    $st = $pdo->prepare("SELECT
            SUM(current_holder_id = ? AND status IN ('in_progress','returned')) inbox,
            SUM(current_holder_id = ? AND status IN ('in_progress','returned') AND priority IN ('urgent','high')) inbox_urgent,
            SUM(current_holder_id = ? AND status IN ('in_progress','returned') AND current_holder_seen_at IS NULL) inbox_unseen,
            SUM(current_holder_id = ? AND status IN ('in_progress','returned') AND updated_at < DATE_SUB(NOW(), INTERVAL 3 DAY)) inbox_old,
            SUM(current_holder_id = ? AND status = 'returned') inbox_returned,
            SUM(created_by = ? AND status IN ('in_progress','returned')) sent_open,
            SUM(created_by = ? AND status = 'completed' AND updated_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) sent_done_month
        FROM letters WHERE organization_id = ?");
    $uid = $user['id'];
    $st->execute([$uid, $uid, $uid, $uid, $uid, $uid, $uid, $orgId]);
    $lc = array_map('intval', $st->fetch() ?: []);
    smartTiles([
        ['label' => 'माझ्याकडे प्रलंबित', 'value' => $lc['inbox'] ?? 0, 'sub' => ($lc['inbox_unseen'] ?? 0) . ' न उघडलेली', 'href' => '?view=inbox'],
        ['label' => 'तातडीची / जास्त', 'value' => $lc['inbox_urgent'] ?? 0, 'tone' => !empty($lc['inbox_urgent']) ? 'bad' : '', 'href' => '?view=inbox'],
        ['label' => '३ दिवसांपेक्षा जुनी', 'value' => $lc['inbox_old'] ?? 0, 'tone' => !empty($lc['inbox_old']) ? 'warn' : '', 'sub' => 'माझ्याकडे पडून'],
        ['label' => 'मी पाठवलेली — चालू', 'value' => $lc['sent_open'] ?? 0, 'href' => '?view=sent'],
        ['label' => 'या महिन्यात पूर्ण', 'value' => $lc['sent_done_month'] ?? 0, 'tone' => 'ok', 'sub' => 'मी सुरू केलेली'],
    ]);
    $al = [];
    if (!empty($lc['inbox_urgent'])) $al[] = ['bad', $lc['inbox_urgent'] . ' तातडीची पत्रं तुमच्या कृतीची वाट पाहत आहेत', '?view=inbox'];
    if (!empty($lc['inbox_returned'])) $al[] = ['warn', $lc['inbox_returned'] . ' पत्रं दुरुस्तीसाठी परत आली आहेत', '?view=inbox'];
    if (!empty($lc['inbox_old'])) $al[] = ['warn', $lc['inbox_old'] . ' पत्रं ३ दिवसांपेक्षा जास्त तुमच्याकडे पडून आहेत', '?view=inbox'];

    if (hasPermission('manage_letters_org_wide')) {
        $st = $pdo->prepare("SELECT u.name, COUNT(*) cnt, SUM(l.updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY)) stale
            FROM letters l JOIN users u ON u.id = l.current_holder_id
            WHERE l.organization_id = ? AND l.status IN ('in_progress','returned')
            GROUP BY l.current_holder_id, u.name ORDER BY cnt DESC LIMIT 8");
        $st->execute([$orgId]);
        $holders = $st->fetchAll();
        $stale = array_sum(array_map(fn($r) => (int)$r['stale'], $holders));
        if ($stale) $al[] = ['bad', 'संस्थेत ' . $stale . ' पत्रं ७ दिवसांपेक्षा जास्त एकाच टेबलावर अडकली आहेत', '?view=all'];
        smartAlerts($al);
        if ($holders) {
            echo '<div class="row g-3 mb-3"><div class="col-lg-7">';
            smartBars('🧭 पत्रं सध्या कोणाकडे — Bottleneck', 'उघडी पत्रं धारकानुसार · लाल = ७+ दिवस अडकलेली',
                array_map(fn($r) => ['name' => $r['name'], 'value' => (int)$r['cnt'], 'label' => $r['cnt'] . ((int)$r['stale'] ? ' (' . $r['stale'] . '⏰)' : ''), 'color' => (int)$r['stale'] ? 'var(--rose)' : 'var(--rail)'], $holders));
            echo '</div></div>';
        }
    } else {
        smartAlerts($al);
    }
} catch (PDOException $e) {}
