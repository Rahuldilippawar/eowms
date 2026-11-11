<?php
// 🚐 वाहन — Smart आढावा (vehicles/index.php मधून; $vehicles त्याच पानाच्या Scope प्रमाणे)
// कागदपत्रांची मुदत (PUC/विमा/Fitness/Permit/कर), देखभाल-मुदत, या महिन्याचे किमी,
// इंधन-खर्च व वाहननिहाय सरासरी (किमी/लिटर).
require_once __DIR__ . '/../smart-insights.php';
if ($vehicles) {
    $vIds = array_map(fn($v) => (int)$v['id'], $vehicles);
    $ph = implode(',', array_fill(0, count($vIds), '?'));
    $docFields = ['puc_validity' => 'PUC', 'insurance_validity' => 'विमा', 'fitness_validity' => 'Fitness', 'permit_validity' => 'Permit', 'road_tax_validity' => 'Road Tax'];
    $expired = []; $soon = [];
    foreach ($vehicles as $v) {
        foreach ($docFields as $f => $lbl) {
            if (empty($v[$f])) continue;
            $d = (int)floor((strtotime($v[$f]) - strtotime(date('Y-m-d'))) / 86400);
            if ($d < 0) $expired[] = $v['vehicle_number'] . ' — ' . $lbl;
            elseif ($d <= 15) $soon[] = $v['vehicle_number'] . ' — ' . $lbl . ' (' . $d . ' दिवस)';
        }
    }
    $kmMonth = 0; $fuelCost = 0; $maintDue = 0; $eff = [];
    try {
        $st = $pdo->prepare("SELECT COALESCE(SUM(distance_km),0) FROM vehicle_trips WHERE vehicle_id IN ($ph) AND trip_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')");
        $st->execute($vIds); $kmMonth = (float)$st->fetchColumn();
        $st = $pdo->prepare("SELECT COALESCE(SUM(cost),0) FROM vehicle_fuel_log WHERE vehicle_id IN ($ph) AND fill_date >= DATE_FORMAT(CURDATE(), '%Y-%m-01')");
        $st->execute($vIds); $fuelCost = (float)$st->fetchColumn();
        // सर्व्हिस — तारीख (७ दिवस) किंवा km (५०० km) नुसार जवळ / ओलांडलेली
        require_once __DIR__ . '/../vehicle-helpers.php';
        $maintDue = count(array_filter(vehicleServiceStatus($pdo, array_filter($vehicles, fn($v) => $v['is_active'])), fn($x) => $x['state'] !== 'ok'));
        // मागील ९० दिवसांची सरासरी — किमी ÷ लिटर
        $st = $pdo->prepare("SELECT v.vehicle_number,
                (SELECT COALESCE(SUM(distance_km),0) FROM vehicle_trips t WHERE t.vehicle_id = v.id AND t.trip_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)) km,
                (SELECT COALESCE(SUM(liters),0) FROM vehicle_fuel_log f WHERE f.vehicle_id = v.id AND f.fill_date >= DATE_SUB(CURDATE(), INTERVAL 90 DAY)) lit
            FROM vehicles v WHERE v.id IN ($ph)");
        $st->execute($vIds);
        foreach ($st->fetchAll() as $r) { if ((float)$r['lit'] > 0 && (float)$r['km'] > 0) $eff[] = ['name' => $r['vehicle_number'], 'value' => round($r['km'] / $r['lit'], 1)]; }
    } catch (PDOException $e) {}
    usort($eff, fn($a, $b) => $a['value'] <=> $b['value']);
    $avgEff = $eff ? array_sum(array_column($eff, 'value')) / count($eff) : 0;
    smartTiles([
        ['label' => 'वाहनं', 'value' => count($vehicles)],
        ['label' => 'या महिन्यात प्रवास', 'value' => number_format($kmMonth) . ' किमी'],
        ['label' => 'या महिन्यात इंधन-खर्च', 'value' => '₹' . number_format($fuelCost)],
        ['label' => 'कागदपत्रांची मुदत संपली', 'value' => count($expired), 'tone' => $expired ? 'bad' : 'ok', 'sub' => count($soon) . ' पुढील १५ दिवसांत'],
        ['label' => 'सर्व्हिस बाकी / जवळ', 'value' => $maintDue, 'tone' => $maintDue ? 'warn' : '', 'sub' => '७ दिवस किंवा ५०० km आत'],
    ]);
    $al = [];
    foreach (array_slice($expired, 0, 4) as $x) $al[] = ['bad', '⛔ ' . $x . ' — मुदत संपली', ''];
    foreach (array_slice($soon, 0, 4) as $x) $al[] = ['warn', '⏳ ' . $x, ''];
    if (count($expired) + count($soon) > 8) $al[] = ['info', 'आणखी ' . (count($expired) + count($soon) - 8) . ' कागदपत्रं — वाहननिहाय तपशील पहा', ''];
    foreach ($eff as $e) { if ($avgEff > 0 && $e['value'] < $avgEff * 0.75) $al[] = ['warn', '⛽ ' . $e['name'] . ' — सरासरी फक्त ' . $e['value'] . ' किमी/लि (इतरांपेक्षा कमी — तपासणी करा)', '']; }
    smartAlerts($al);
    if (count($eff) > 1) {
        echo '<div class="row g-3 mb-3"><div class="col-lg-7">';
        smartBars('⛽ इंधन-कार्यक्षमता — मागील ९० दिवस', 'किमी प्रति लिटर · कमी असलेली वाहनं आधी', array_map(fn($e) => ['name' => $e['name'], 'value' => $e['value'], 'label' => $e['value'] . ' किमी/लि', 'color' => $e['value'] < $avgEff * 0.75 ? 'var(--rose)' : 'var(--rail)'], $eff));
        echo '</div></div>';
    }
}
