<?php
/**
 * 🌙 दैनिक व्यवस्थापन अहवाल — रोज संध्याकाळी (default 8 वाजता) सगळ्या Modules चा
 * आजचा हिशोब PDF (Charts सह) Management ला Email वर.
 * ज्या Module मध्ये संस्थेचा काहीच Data नाही, तो भाग अहवालात येत नाही.
 * Cron: cron/daily-management-report.php · Preview/Settings: reports/daily.php
 */
require_once __DIR__ . '/weekly-report.php';
require_once __DIR__ . '/ecampus-helpers.php';

// ---------- Charts (SVG — mPDF आणि Browser दोघांतही; मजकूर फक्त अंक, मराठी Labels HTML मध्ये) ----------
/** Donut — $parts = [[value, color], ...], मध्ये $center (उदा. "72%") */
function drDonut(array $parts, $center = '', $size = 110) {
    $tot = array_sum(array_column($parts, 0));
    $r = 44; $c = 55;
    $s = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $size . '" height="' . $size . '" viewBox="0 0 110 110">';
    $s .= '<circle cx="55" cy="55" r="44" fill="none" stroke="#EEE9E3" stroke-width="16"/>';
    if ($tot > 0) {
        $a = -M_PI / 2;
        foreach ($parts as [$v, $col]) {
            if ($v <= 0) { continue; }
            $b = $a + 2 * M_PI * $v / $tot;
            if ($v >= $tot) { $s .= '<circle cx="55" cy="55" r="44" fill="none" stroke="' . $col . '" stroke-width="16"/>'; break; }
            $s .= sprintf('<path d="M%.2f %.2f A%d %d 0 %d 1 %.2f %.2f" fill="none" stroke="%s" stroke-width="16"/>',
                $c + $r * cos($a), $c + $r * sin($a), $r, $r, ($b - $a) > M_PI ? 1 : 0, $c + $r * cos($b), $c + $r * sin($b), $col);
            $a = $b;
        }
    }
    if ($center !== '') { $s .= '<text x="55" y="62" font-size="19" font-weight="bold" text-anchor="middle" fill="#191C3A">' . htmlspecialchars($center) . '</text>'; }
    return $s . '</svg>';
}

/** Column Bars — शेवटचा (आजचा) Bar ठळक रंगात; खाली दिवसांचे अंक ($labels — फक्त अंक/Latin) */
function drBars(array $values, array $labels, $color = '#D9530F', $w = 330, $h = 120) {
    $n = max(1, count($values));
    $max = max(1, max($values ?: [0]));
    $slot = ($w - 10) / $n; $bw = $slot * 0.62;
    $s = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $w . '" height="' . $h . '" viewBox="0 0 ' . $w . ' ' . $h . '">';
    $s .= '<line x1="4" y1="' . ($h - 18) . '" x2="' . ($w - 4) . '" y2="' . ($h - 18) . '" stroke="#DDD6CE" stroke-width="1"/>';
    $i = 0;
    foreach ($values as $v) {
        $bh = max(1.5, ($h - 38) * $v / $max);
        $x = 5 + $i * $slot + ($slot - $bw) / 2;
        $y = $h - 18 - $bh;
        $last = $i === $n - 1;
        $s .= sprintf('<rect x="%.1f" y="%.1f" width="%.1f" height="%.1f" rx="3" fill="%s"/>', $x, $y, $bw, $bh, $last ? $color : '#E9C9B4');
        $s .= sprintf('<text x="%.1f" y="%.1f" font-size="10" font-weight="bold" text-anchor="middle" fill="#191C3A">%s</text>', $x + $bw / 2, $y - 3, (int)$v);
        $s .= sprintf('<text x="%.1f" y="%.1f" font-size="9" text-anchor="middle" fill="#6B7186">%s</text>', $x + $bw / 2, $h - 5, htmlspecialchars($labels[$i] ?? ''));
        $i++;
    }
    return $s . '</svg>';
}

function drImg($svg, $widthMm) { return '<img src="data:image/svg+xml;base64,' . base64_encode($svg) . '" style="width:' . $widthMm . 'mm;">'; }

/** रंगीत ठिपक्यासह छोटी Legend ओळ */
function drLegend(array $items) {
    $o = '';
    foreach ($items as [$label, $val, $col]) {
        $o .= '<div style="font-size:8.5pt;margin:2px 0;"><img src="' . weeklyReportPixel($col) . '" style="width:2.4mm;height:2.4mm;"> ' . htmlspecialchars($label) . ' <b>' . $val . '</b></div>';
    }
    return $o;
}

// ---------- Data ----------
function dailyReportData(PDO $pdo, $orgId, $date) {
    $q = function ($sql, $p = [], $mode = 'all') use ($pdo) {
        try { $st = $pdo->prepare($sql); $st->execute($p); return $mode === 'one' ? ($st->fetch() ?: []) : ($mode === 'col' ? $st->fetchColumn() : $st->fetchAll()); }
        catch (PDOException $e) { return $mode === 'all' ? [] : ($mode === 'one' ? [] : null); }
    };
    $o = (int)$orgId;
    $d = $date;
    $days = [];
    for ($i = 6; $i >= 0; $i--) { $days[] = date('Y-m-d', strtotime("-$i days", strtotime($d))); }
    $d6 = $days[0];
    $tomorrow = date('Y-m-d', strtotime('+1 day', strtotime($d)));
    $series = function ($sql, $p) use ($q, $days) { // [date => count] → 7 मूल्यं
        $m = [];
        foreach ($q($sql, $p) as $r) { $m[$r['d']] = (float)$r['c']; }
        return array_map(fn($x) => $m[$x] ?? 0, $days);
    };
    $org = $q("SELECT name FROM organizations WHERE id = ?", [$o], 'one');
    $S = []; $alerts = []; $good = [];

    // 1) कामकाज
    $w = $q("SELECT COUNT(*) total, COALESCE(SUM(status='completed'),0) done, COALESCE(SUM(status='in_progress'),0) prog, COALESCE(SUM(status='pending'),0) pend, COUNT(DISTINCT employee_id) people, COUNT(DISTINCT department_id) depts
             FROM work_records WHERE organization_id = ? AND work_date = ?", [$o, $d], 'one');
    $wTrend = $series("SELECT work_date d, COUNT(*) c FROM work_records WHERE organization_id = ? AND work_date BETWEEN ? AND ? GROUP BY work_date", [$o, $d6, $d]);
    $avg6 = array_sum(array_slice($wTrend, 0, 6)) / 6;
    $wDept = $q("SELECT w.department_id id, COALESCE(NULLIF(dp.name_local,''), dp.name) dept, COUNT(*) total, SUM(w.status='completed') done
                 FROM work_records w JOIN departments dp ON dp.id = w.department_id WHERE w.organization_id = ? AND w.work_date = ? GROUP BY w.department_id ORDER BY total DESC LIMIT 10", [$o, $d]);
    $idleDepts = $q("SELECT COALESCE(NULLIF(name_local,''), name) n FROM departments dp WHERE organization_id = ? AND is_active = 1
                 AND EXISTS (SELECT 1 FROM work_records w WHERE w.department_id = dp.id AND w.work_date >= DATE_SUB(?, INTERVAL 30 DAY))
                 AND NOT EXISTS (SELECT 1 FROM work_records w WHERE w.department_id = dp.id AND w.work_date = ?)", [$o, $d, $d]);
    $wTop = $q("SELECT w.title, w.status, w.priority, u.name who, COALESCE(NULLIF(dp.name_local,''), dp.name) dept FROM work_records w LEFT JOIN users u ON u.id = w.employee_id LEFT JOIN departments dp ON dp.id = w.department_id
                WHERE w.organization_id = ? AND w.work_date = ? ORDER BY FIELD(w.priority,'urgent','high','normal','low'), w.id DESC LIMIT 6", [$o, $d]);
    if ((int)($w['total'] ?? 0) > 0 || array_sum($wTrend) > 0) {
        $S['work'] = ['w' => $w, 'trend' => $wTrend, 'avg' => $avg6, 'dept' => $wDept, 'idle' => array_column($idleDepts, 'n'), 'top' => $wTop];
        if ($avg6 >= 3 && $w['total'] < 0.6 * $avg6) { $alerts[] = ['warn', 'आज कामाच्या नोंदी सरासरीपेक्षा कमी — ' . (int)$w['total'] . ' (रोजची सरासरी ' . round($avg6) . ')']; }
        elseif ($avg6 >= 1 && $w['total'] > 1.25 * $avg6) { $good[] = 'आज कामाच्या नोंदी सरासरीपेक्षा ' . round(($w['total'] - $avg6) * 100 / $avg6) . '% जास्त'; }
        if ($idleDepts) { $alerts[] = ['warn', count($idleDepts) . ' विभागांत आज एकही कामाची नोंद नाही']; }
    }

    // 2) कर्मचारी हजेरी
    $at = $q("SELECT COUNT(*) total, COALESCE(SUM(status IN ('present','half_day')),0) present, COALESCE(SUM(status='absent'),0) absent, COALESCE(SUM(status='on_leave'),0) leave_cnt,
              COALESCE(SUM(status='half_day'),0) half, COALESCE(SUM(is_late=1),0) late FROM staff_attendance WHERE organization_id = ? AND attendance_date = ?", [$o, $d], 'one');
    $staffTotal = (int)$q("SELECT COUNT(*) FROM users WHERE organization_id = ? AND is_active = 1", [$o], 'col');
    $lateList = $q("SELECT u.name, a.first_punch_time t FROM staff_attendance a JOIN users u ON u.id = a.user_id WHERE a.organization_id = ? AND a.attendance_date = ? AND a.is_late = 1 ORDER BY a.first_punch_time DESC LIMIT 8", [$o, $d]);
    $leaveToday = (int)$q("SELECT COUNT(*) FROM leave_applications WHERE organization_id = ? AND status = 'approved' AND ? BETWEEN start_date AND end_date", [$o, $d], 'col');
    $leavePending = (int)$q("SELECT COUNT(*) FROM leave_applications WHERE organization_id = ? AND status = 'pending'", [$o], 'col');
    $atTrend = $series("SELECT attendance_date d, SUM(status IN ('present','half_day')) c FROM staff_attendance WHERE organization_id = ? AND attendance_date BETWEEN ? AND ? GROUP BY attendance_date", [$o, $d6, $d]);
    if ((int)($at['total'] ?? 0) > 0 || $leaveToday || $leavePending) {
        $S['attendance'] = ['a' => $at, 'staff' => $staffTotal, 'late' => $lateList, 'leaveToday' => $leaveToday, 'leavePending' => $leavePending, 'trend' => $atTrend];
        $pct = $at['total'] ? round($at['present'] * 100 / $at['total']) : null;
        if ($pct !== null && $pct < 80) { $alerts[] = ['warn', 'आजची हजेरी ' . $pct . '% — ' . (int)$at['absent'] . ' गैरहजर']; }
        if ((int)$at['late'] >= 3) { $alerts[] = ['warn', (int)$at['late'] . ' कर्मचारी आज उशिरा आले']; }
    }

    // 3) Tasks
    $t = $q("SELECT COALESCE(SUM(DATE(created_at) = ?),0) new_cnt, COALESCE(SUM(status='completed' AND DATE(completed_at) = ?),0) done,
             COALESCE(SUM(status IN ('pending','in_progress')),0) open_cnt, COALESCE(SUM(status IN ('pending','in_progress') AND target_date < ?),0) overdue,
             COALESCE(SUM(status IN ('pending','in_progress') AND target_date = ?),0) due_tomorrow
             FROM tasks WHERE organization_id = ?", [$d, $d, $d, $tomorrow, $o], 'one');
    $tOver = $q("SELECT t.title, DATEDIFF(?, t.target_date) late, u.name who, COALESCE(NULLIF(dp.name_local,''), dp.name) dept, t.priority FROM tasks t LEFT JOIN users u ON u.id = t.assigned_to LEFT JOIN departments dp ON dp.id = t.department_id
                 WHERE t.organization_id = ? AND t.status IN ('pending','in_progress') AND t.target_date < ? ORDER BY t.target_date LIMIT 8", [$d, $o, $d]);
    if (array_sum(array_map('intval', $t ?: [])) > 0) {
        $S['tasks'] = ['t' => $t, 'over' => $tOver];
        if ((int)$t['overdue'] > 0) { $alerts[] = ['bad', (int)$t['overdue'] . ' Tasks मुदत उलटून अपूर्ण']; }
        if ((int)$t['done'] > 0) { $good[] = (int)$t['done'] . ' Tasks आज पूर्ण'; }
    }

    // 4) कामाच्या विनंत्या
    $r = $q("SELECT COALESCE(SUM(DATE(created_at) = ?),0) new_cnt, COALESCE(SUM(DATE(completed_at) = ?),0) done,
             COALESCE(SUM(status IN ('pending','in_progress')),0) open_cnt, COALESCE(SUM(status IN ('pending','in_progress') AND due_at < NOW()),0) sla
             FROM work_requests WHERE organization_id = ?", [$d, $d, $o], 'one');
    $rCat = $q("SELECT COALESCE(NULLIF(category,''),'इतर') cat, COUNT(*) c FROM work_requests WHERE organization_id = ? AND status IN ('pending','in_progress') GROUP BY cat ORDER BY c DESC LIMIT 5", [$o]);
    $rSla = $q("SELECT r.request_code, r.title, TIMESTAMPDIFF(HOUR, r.due_at, NOW()) hrs, COALESCE(NULLIF(dp.name_local,''), dp.name) dept FROM work_requests r LEFT JOIN departments dp ON dp.id = r.department_id
                WHERE r.organization_id = ? AND r.status IN ('pending','in_progress') AND r.due_at < NOW() ORDER BY r.due_at LIMIT 6", [$o]);
    if (array_sum(array_map('intval', $r ?: [])) > 0) {
        $S['requests'] = ['r' => $r, 'cat' => $rCat, 'sla' => $rSla];
        if ((int)$r['sla'] > 0) { $alerts[] = ['bad', (int)$r['sla'] . ' कामाच्या विनंत्या SLA वेळ उलटून प्रलंबित']; }
    }

    // 5) पत्रव्यवहार
    $l = $q("SELECT COALESCE(SUM(DATE(created_at) = ?),0) new_cnt, COALESCE(SUM(status='completed' AND DATE(updated_at) = ?),0) done,
             COALESCE(SUM(status IN ('in_progress','returned')),0) pending, COALESCE(SUM(status IN ('in_progress','returned') AND priority IN ('urgent','high')),0) urgent,
             COALESCE(SUM(dispatch_date = ?),0) dispatched FROM letters WHERE organization_id = ?", [$d, $d, $d, $o], 'one');
    $lHold = $q("SELECT l.subject, u.name holder, DATEDIFF(?, DATE(l.updated_at)) idle, l.priority FROM letters l LEFT JOIN users u ON u.id = l.current_holder_id
                 WHERE l.organization_id = ? AND l.status IN ('in_progress','returned') ORDER BY FIELD(l.priority,'urgent','high','normal','low'), l.updated_at LIMIT 6", [$d, $o]);
    if (array_sum(array_map('intval', $l ?: [])) > 0) {
        $S['letters'] = ['l' => $l, 'hold' => $lHold];
        if ((int)$l['urgent'] > 0) { $alerts[] = ['warn', (int)$l['urgent'] . ' तातडीची/उच्च पत्रं मंजुरीसाठी प्रलंबित']; }
    }

    // 6) खर्च
    $spend = (float)$q("SELECT COALESCE(SUM(amount),0) FROM budget_transactions WHERE organization_id = ? AND COALESCE(spend_date, DATE(created_at)) = ?", [$o, $d], 'col');
    $paid = (float)$q("SELECT COALESCE(SUM(amount),0) FROM dept_payment_ledger WHERE organization_id = ? AND payment_date = ?", [$o, $d], 'col');
    $spTrend = $series("SELECT COALESCE(spend_date, DATE(created_at)) d, SUM(amount) c FROM budget_transactions WHERE organization_id = ? AND COALESCE(spend_date, DATE(created_at)) BETWEEN ? AND ? GROUP BY d", [$o, $d6, $d]);
    $spCat = $q("SELECT category, SUM(amount) amt FROM budget_transactions WHERE organization_id = ? AND COALESCE(spend_date, DATE(created_at)) = ? GROUP BY category ORDER BY amt DESC", [$o, $d]);
    $spDept = $q("SELECT t.department_id id, COALESCE(NULLIF(dp.name_local,''), dp.name) dept, SUM(t.amount) amt FROM budget_transactions t JOIN departments dp ON dp.id = t.department_id
                  WHERE t.organization_id = ? AND COALESCE(t.spend_date, DATE(t.created_at)) = ? GROUP BY t.department_id ORDER BY amt DESC LIMIT 6", [$o, $d]);
    if ($spend > 0 || $paid > 0 || array_sum($spTrend) > 0) {
        $S['spend'] = ['spend' => $spend, 'paid' => $paid, 'trend' => $spTrend, 'cat' => $spCat, 'dept' => $spDept];
        $avgSp = array_sum(array_slice($spTrend, 0, 6)) / 6;
        if ($avgSp > 0 && $spend > 2 * $avgSp && $spend >= 10000) { $alerts[] = ['warn', 'आजचा खर्च ' . weeklyReportMoney($spend) . ' — रोजच्या सरासरीच्या ' . round($spend / $avgSp, 1) . ' पट']; }
    }

    // 7) वाहने
    $v = $q("SELECT COUNT(*) trips, COALESCE(SUM(distance_km),0) km, COUNT(DISTINCT t.vehicle_id) veh FROM vehicle_trips t JOIN vehicles v ON v.id = t.vehicle_id WHERE v.organization_id = ? AND t.trip_date = ?", [$o, $d], 'one');
    $f = $q("SELECT COALESCE(SUM(liters),0) l, COALESCE(SUM(cost),0) c FROM vehicle_fuel_log f JOIN vehicles v ON v.id = f.vehicle_id WHERE v.organization_id = ? AND f.fill_date = ?", [$o, $d], 'one');
    $vDocs = (int)$q("SELECT COUNT(*) FROM vehicles WHERE organization_id = ? AND is_active = 1 AND (LEAST(COALESCE(permit_validity,'9999-12-31'), COALESCE(puc_validity,'9999-12-31'), COALESCE(insurance_validity,'9999-12-31'), COALESCE(fitness_validity,'9999-12-31')) <= DATE_ADD(?, INTERVAL 15 DAY))", [$o, $d], 'col');
    if ((int)($v['trips'] ?? 0) > 0 || (float)($f['l'] ?? 0) > 0 || $vDocs > 0) {
        $S['vehicles'] = ['v' => $v, 'f' => $f, 'docs' => $vDocs];
        if ($vDocs > 0) { $alerts[] = ['warn', $vDocs . ' वाहनांची कागदपत्रं (PUC/विमा/परवाना) 15 दिवसांत संपत आहेत']; }
    }

    // 8) वसतिगृह
    $h = $q("SELECT COUNT(DISTINCT a.student_id) marked, COUNT(DISTINCT CASE WHEN a.status='absent' THEN a.student_id END) absent
             FROM hostel_student_attendance a JOIN hostels hs ON hs.id = a.hostel_id WHERE hs.organization_id = ? AND a.attendance_date = ?", [$o, $d], 'one');
    $hTotal = (int)$q("SELECT COUNT(*) FROM hostel_students s JOIN hostels hs ON hs.id = s.hostel_id WHERE hs.organization_id = ? AND s.is_active = 1", [$o], 'col');
    $hOut = $q("SELECT COUNT(*) out_cnt, COALESCE(SUM(l.expected_return_date < ?),0) overdue FROM hostel_leave_records l JOIN hostel_students s ON s.id = l.student_id JOIN hostels hs ON hs.id = s.hostel_id WHERE hs.organization_id = ? AND l.status = 'out'", [$d, $o], 'one');
    if ($hTotal > 0) {
        $S['hostel'] = ['h' => $h, 'total' => $hTotal, 'out' => $hOut];
        if ((int)($hOut['overdue'] ?? 0) > 0) { $alerts[] = ['bad', (int)$hOut['overdue'] . ' विद्यार्थी रजेवरून परत येण्याची तारीख उलटूनही परतले नाहीत']; }
        if ($hTotal > 0 && (int)($h['marked'] ?? 0) === 0) { $alerts[] = ['warn', 'वसतिगृहाची आजची हजेरी घेतलेली नाही']; }
    }

    // 9) पाहुणे
    $g = $q("SELECT COUNT(*) total, COALESCE(SUM(check_out_time IS NULL),0) inside FROM guests WHERE organization_id = ? AND DATE(check_in_time) = ?", [$o, $d], 'one');
    $gTrend = $series("SELECT DATE(check_in_time) d, COUNT(*) c FROM guests WHERE organization_id = ? AND DATE(check_in_time) BETWEEN ? AND ? GROUP BY d", [$o, $d6, $d]);
    if ((int)($g['total'] ?? 0) > 0 || array_sum($gTrend) > 0) { $S['guests'] = ['g' => $g, 'trend' => $gTrend]; }

    // 10) सुरक्षा व सुविधा
    $sos = $q("SELECT COUNT(*) total, COALESCE(SUM(status='active'),0) active FROM emergency_alerts WHERE organization_id = ? AND DATE(created_at) = ?", [$o, $d], 'one');
    $cctvDvr = (int)$q("SELECT COUNT(*) FROM cctv_dvrs WHERE organization_id = ? AND is_active = 1", [$o], 'col');
    $cctvChecked = (int)$q("SELECT COUNT(DISTINCT dvr_id) FROM cctv_checkups WHERE organization_id = ? AND checkup_date = ?", [$o, $d], 'col');
    $camBad = (int)$q("SELECT COUNT(*) FROM cctv_camera_status cs JOIN cctv_checkups c ON c.id = cs.checkup_id WHERE c.organization_id = ? AND c.checkup_date = ? AND cs.status <> 'working'", [$o, $d], 'col');
    $it = $q("SELECT COALESCE(SUM(DATE(created_at) = ?),0) new_cnt, COALESCE(SUM(status <> 'resolved'),0) open_cnt FROM it_repair_tickets WHERE organization_id = ?", [$d, $o], 'one');
    $gr = $q("SELECT COALESCE(SUM(DATE(created_at) = ?),0) new_cnt, COALESCE(SUM(status IN ('open','in_progress')),0) open_cnt, COALESCE(SUM(status IN ('open','in_progress') AND sla_deadline < ?),0) late FROM grievances WHERE organization_id = ?", [$d, $d, $o], 'one');
    $mt = $q("SELECT COUNT(*) n, COALESCE(SUM(cost),0) c FROM maintenance_logs WHERE organization_id = ? AND work_date = ?", [$o, $d], 'one');
    $fac = ['sos' => $sos, 'cctvDvr' => $cctvDvr, 'cctvChecked' => $cctvChecked, 'camBad' => $camBad, 'it' => $it, 'gr' => $gr, 'mt' => $mt];
    if ((int)($sos['total'] ?? 0) + $cctvDvr + (int)($it['open_cnt'] ?? 0) + (int)($gr['open_cnt'] ?? 0) + (int)($gr['new_cnt'] ?? 0) + (int)($mt['n'] ?? 0) > 0) {
        $S['facility'] = $fac;
        if ((int)($sos['total'] ?? 0) > 0) { $alerts[] = ['bad', (int)$sos['total'] . ' आणीबाणी (SOS) सूचना आज' . ((int)$sos['active'] ? ' — ' . (int)$sos['active'] . ' अजून सुरू' : '')]; }
        if ($cctvDvr > 0 && $cctvChecked < $cctvDvr) { $alerts[] = ['warn', 'CCTV तपासणी आज ' . $cctvChecked . '/' . $cctvDvr . ' DVR चीच झाली']; }
        if ($camBad > 0) { $alerts[] = ['warn', $camBad . ' CCTV कॅमेरे बंद / लक्ष देण्याजोगे']; }
        if ((int)($gr['late'] ?? 0) > 0) { $alerts[] = ['bad', (int)$gr['late'] . ' तक्रारी मुदत उलटून प्रलंबित']; }
    }

    // 11) नूतनीकरण
    $ren = $q("SELECT r.item_name, r.expiry_date, DATEDIFF(r.expiry_date, ?) left_days, COALESCE(NULLIF(dp.name_local,''), dp.name) dept FROM renewal_items r LEFT JOIN departments dp ON dp.id = r.department_id
               WHERE r.organization_id = ? AND r.status <> 'inactive' AND r.expiry_date <= DATE_ADD(?, INTERVAL 7 DAY) ORDER BY r.expiry_date LIMIT 8", [$d, $o, $d]);
    if ($ren) {
        $S['renewals'] = $ren;
        $exp = count(array_filter($ren, fn($x) => $x['left_days'] < 0));
        if ($exp) { $alerts[] = ['bad', $exp . ' परवाने/नूतनीकरण मुदत संपलेली']; }
    }

    // 12) उद्याचं नियोजन
    $ev = $q("SELECT name, start_time, venue FROM events WHERE organization_id = ? AND event_date = ? AND status <> 'cancelled' ORDER BY start_time", [$o, $tomorrow]);
    $rb = $q("SELECT b.purpose, b.start_time, br.name room FROM room_bookings b JOIN bookable_rooms br ON br.id = b.room_id WHERE br.organization_id = ? AND DATE(b.start_time) = ? AND b.status = 'confirmed' ORDER BY b.start_time LIMIT 6", [$o, $tomorrow]);
    $renTomorrow = (int)$q("SELECT COUNT(*) FROM renewal_items WHERE organization_id = ? AND status <> 'inactive' AND expiry_date = ?", [$o, $tomorrow], 'col');
    if ($ev || $rb || (int)($t['due_tomorrow'] ?? 0) || $renTomorrow) { $S['tomorrow'] = ['events' => $ev, 'rooms' => $rb, 'tasks' => (int)($t['due_tomorrow'] ?? 0), 'ren' => $renTomorrow]; }

    // 13) E-Campus — त्या महिन्याची प्रगती (Super Admin ने कामं नेमली असतील तर)
    $ecPeriod = substr($d, 0, 7);
    $ecHtml = ecampusPdfSection($pdo, $o, $ecPeriod, 'brief', $d . ' 00:00:00');
    if ($ecHtml !== '') {
        $ecS = ecampusSummary(ecampusRows($pdo, $o, $ecPeriod), $ecPeriod);
        $S['ecampus'] = ['html' => $ecHtml, 'overall' => $ecS['overall'], 'done' => $ecS['done'], 'work' => $ecS['work']];
        foreach (ecampusAlerts($pdo, $o, $ecPeriod) as $a) { $alerts[] = $a; }
    }

    // दिवसाचा गुण (0–100) — कामाची पूर्णता, हजेरी, मुदतबाह्य, SLA, SOS
    $score = 100;
    if (!empty($S['work']) && $w['total'] > 0) { $score -= (int)round((100 - $w['done'] * 100 / $w['total']) * 0.25); }
    if (!empty($S['attendance']) && $at['total'] > 0) { $score -= (int)round((100 - $at['present'] * 100 / $at['total']) * 0.3); }
    $score -= min(20, (int)($t['overdue'] ?? 0) * 2);
    $score -= min(15, (int)($r['sla'] ?? 0) * 3);
    $score -= min(15, (int)($sos['total'] ?? 0) * 5);
    $score = max(0, min(100, $score));

    return ['org' => $org['name'] ?? 'E-Admin', 'orgId' => $o, 'date' => $d, 'days' => $days, 'sections' => $S, 'alerts' => $alerts, 'good' => $good,
        'score' => $score, 'level' => $score >= 75 ? 'good' : ($score >= 50 ? 'warn' : 'bad'), 'generated' => date('Y-m-d H:i')];
}

// ---------- PDF HTML ----------
function dailyReportPdfHtml(array $D) {
    global $pdo;
    require_once __DIR__ . '/dept-groups.php';   // विभाग-तक्ते Campus नुसार
    $orgId = (int)$D['orgId'];
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    $S = $D['sections'];
    $C = ['good' => '#137A4B', 'warn' => '#B45309', 'bad' => '#B42318'];
    $BG = ['good' => '#E7F6EF', 'warn' => '#FEF3C7', 'bad' => '#FDECEC'];
    $dayLbl = array_map(fn($x) => date('d/m', strtotime($x)), $D['days']);
    $dn = ['Mon' => 'सोमवार', 'Tue' => 'मंगळवार', 'Wed' => 'बुधवार', 'Thu' => 'गुरुवार', 'Fri' => 'शुक्रवार', 'Sat' => 'शनिवार', 'Sun' => 'रविवार'];
    $kpi = fn($l, $v, $s = '', $col = '#191C3A') => '<td class="kpi"><div class="kl">' . $l . '</div><div class="kv" style="color:' . $col . ';">' . $v . '</div><div class="ks">' . $s . '</div></td>';
    $kpis = fn(array $cells) => '<table class="kp"><tr>' . implode('', $cells) . '</tr></table>';
    $sec = fn($n, $title, $sub = '') => '<h2><span class="num">' . $n . '</span> ' . $title . ($sub ? ' <span class="h2s">' . $sub . '</span>' : '') . '</h2>';
    $pct = fn($a, $b) => $b > 0 ? (int)round($a * 100 / $b) : 0;
    $n = 0;

    $html = '<style>
      body{font-family:freesans;color:#1A1D29;font-size:9.6pt;}
      .hdr{background:#191C3A;color:#fff;padding:13px 18px;border-bottom:4px solid #D9530F;}
      .hdr .o{font-size:16pt;font-weight:bold;} .hdr .t{font-size:11pt;color:#FDBA8C;margin-top:2px;} .hdr .p{font-size:8.5pt;color:#C9CCE0;margin-top:3px;}
      h2{font-size:12pt;color:#191C3A;margin:14px 0 6px;padding-bottom:3px;border-bottom:1.5px solid #EAE5DF;}
      h2 .num{background:#D9530F;color:#fff;padding:0 6px;font-size:10pt;} .h2s{font-size:8.5pt;color:#8A90AC;font-weight:normal;}
      table.kp{width:100%;border-collapse:separate;border-spacing:5px;margin:0 -5px;}
      td.kpi{background:#F7F5F2;border:1px solid #EAE5DF;padding:7px 9px;vertical-align:top;}
      .kl{font-size:8pt;color:#64708A;} .kv{font-size:15pt;font-weight:bold;margin:1px 0;} .ks{font-size:7.6pt;color:#64708A;}
      table.g{width:100%;border-collapse:collapse;font-size:8.8pt;}
      table.g th{background:#F3F1EE;color:#475069;font-weight:bold;text-align:left;padding:4px 6px;border-bottom:1px solid #DDD6CE;font-size:8pt;}
      table.g td{padding:4px 6px;border-bottom:1px solid #EEE9E3;vertical-align:middle;}
      .num-r{text-align:right;} .muted{color:#8A90AC;font-size:8pt;}
      .al{padding:5px 9px;margin:3px 0;font-size:9.2pt;}
      table.split{width:100%;border-collapse:collapse;} table.split td{vertical-align:top;padding:0;}
      .pill{padding:1px 5px;font-size:7.6pt;font-weight:bold;}
    </style>';

    $html .= '<div class="hdr"><div class="o">' . $h($D['org']) . '</div><div class="t">दैनिक व्यवस्थापन अहवाल</div><div class="p">'
        . $h(weeklyReportMarathiDate($D['date']) . ', ' . $dn[date('D', strtotime($D['date']))]) . ' &nbsp;·&nbsp; तयार: ' . $h(date('d-m-Y, h:i A', strtotime($D['generated']))) . '</div></div>';

    // --- सारांश ---
    $w = $S['work']['w'] ?? null; $at = $S['attendance']['a'] ?? null;
    $scoreSub = ['good' => 'चांगला दिवस', 'warn' => 'लक्ष द्या', 'bad' => 'गंभीर'][$D['level']];
    if (isset($D['prevScore'])) {   // कालच्या तुलनेत
        $dd = $D['score'] - $D['prevScore'];
        $scoreSub .= ' · काल ' . $D['prevScore'] . ' <b style="color:' . ($dd > 0 ? '#137A4B' : ($dd < 0 ? '#B42318' : '#8A90AC')) . ';">' . ($dd > 0 ? '▲' . $dd : ($dd < 0 ? '▼' . abs($dd) : '=')) . '</b>';
    }
    $cells = [$kpi('आजचा गुण', $D['score'] . '<span style="font-size:9pt;color:#8A90AC;">/100</span>', $scoreSub, $C[$D['level']])];
    if ($w) { $cells[] = $kpi('कामाच्या नोंदी', (int)$w['total'], $pct($w['done'], $w['total']) . '% पूर्ण · सरासरी ' . round($S['work']['avg'])); }
    if ($at && $at['total']) { $cells[] = $kpi('हजेरी', $pct($at['present'], $at['total']) . '%', (int)$at['present'] . '/' . (int)$at['total'] . ' उपस्थित · ' . (int)$at['late'] . ' उशिरा', $pct($at['present'], $at['total']) >= 85 ? '#137A4B' : '#B45309'); }
    if (!empty($S['tasks'])) { $cells[] = $kpi('मुदतबाह्य Tasks', (int)$S['tasks']['t']['overdue'], (int)$S['tasks']['t']['done'] . ' आज पूर्ण', $S['tasks']['t']['overdue'] ? '#B42318' : '#137A4B'); }
    if (!empty($S['spend'])) { $cells[] = $kpi('आजचा खर्च', weeklyReportMoney($S['spend']['spend']), $S['spend']['paid'] ? 'Payments: ' . weeklyReportMoney($S['spend']['paid']) : ''); }
    $html .= $kpis(array_slice($cells, 0, 5));

    if ($D['alerts'] || $D['good']) {
        $html .= '<h2>आजचे ठळक मुद्दे</h2>';
        foreach (array_slice($D['alerts'], 0, 10) as [$lv, $msg]) { $html .= '<div class="al" style="background:' . $BG[$lv] . ';border-left:3px solid ' . $C[$lv] . ';"><span style="color:' . $C[$lv] . ';">●</span> ' . $h($msg) . '</div>'; }
        foreach ($D['good'] as $msg) { $html .= '<div class="al" style="background:#E7F6EF;border-left:3px solid #137A4B;"><span style="color:#137A4B;">●</span> ' . $h($msg) . '</div>'; }
    }

    // --- कामकाज ---
    if (!empty($S['work'])) {
        $x = $S['work']; $w = $x['w'];
        $html .= $sec(++$n, 'दैनंदिन कामकाज', (int)$w['people'] . ' कर्मचारी · ' . (int)$w['depts'] . ' विभाग');
        $html .= '<table class="split"><tr><td style="width:30%;">' . drImg(drDonut([[(int)$w['done'], '#16A34A'], [(int)$w['prog'], '#F59E0B'], [(int)$w['pend'], '#DC2626']], $pct($w['done'], $w['total']) . '%'), 32)
            . drLegend([['पूर्ण', (int)$w['done'], '#16A34A'], ['सुरू', (int)$w['prog'], '#F59E0B'], ['प्रलंबित', (int)$w['pend'], '#DC2626']]) . '</td>'
            . '<td><div class="muted">गेले 7 दिवस — कामाच्या नोंदी (आज ठळक)</div>' . drImg(drBars($x['trend'], $dayLbl), 112) . '</td></tr></table>';
        if ($x['dept']) {
            $max = max(1, max(array_column($x['dept'], 'total')));
            $html .= '<table class="g" style="margin-top:6px;"><thead><tr><th style="width:30%;">विभाग</th><th style="width:44%;">नोंदी</th><th class="num-r" style="width:12%;">संख्या</th><th class="num-r" style="width:14%;">पूर्ण</th></tr></thead><tbody>';
            $html .= deptGroupRowsHtml($pdo, $orgId, $x['dept'], 4, fn($r) => '<tr><td>' . $h($r['dept']) . '</td><td>' . weeklyReportBar($r['total'] / $max * 100, '#D9530F', 70) . '</td><td class="num-r"><b>' . (int)$r['total'] . '</b></td><td class="num-r">' . $pct($r['done'], $r['total']) . '%</td></tr>');   // Campus नुसार
            $html .= '</tbody></table>';
        }
        if ($x['idle']) { $html .= '<div class="muted" style="margin-top:4px;">आज नोंद नसलेले विभाग: ' . $h(implode(', ', array_slice($x['idle'], 0, 10))) . '</div>'; }
        if ($x['top']) {
            $html .= '<table class="g" style="margin-top:6px;"><thead><tr><th style="width:46%;">आजची महत्त्वाची कामं</th><th style="width:20%;">विभाग</th><th style="width:20%;">कोण</th><th style="width:14%;">स्थिती</th></tr></thead><tbody>';
            $st = ['completed' => ['पूर्ण', '#137A4B'], 'in_progress' => ['सुरू', '#B45309'], 'pending' => ['प्रलंबित', '#B42318']];
            foreach ($x['top'] as $r) { [$sl, $sc] = $st[$r['status']] ?? ['—', '#666']; $html .= '<tr><td>' . $h(mb_strimwidth($r['title'], 0, 60, '…')) . '</td><td>' . $h($r['dept']) . '</td><td>' . $h($r['who']) . '</td><td style="color:' . $sc . ';font-weight:bold;">' . $sl . '</td></tr>'; }
            $html .= '</tbody></table>';
        }
    }

    // --- हजेरी ---
    if (!empty($S['attendance'])) {
        $x = $S['attendance']; $a = $x['a'];
        $html .= $sec(++$n, 'कर्मचारी हजेरी', 'एकूण सक्रिय कर्मचारी ' . (int)$x['staff']);
        $html .= '<table class="split"><tr><td style="width:30%;">' . drImg(drDonut([[(int)$a['present'], '#16A34A'], [(int)$a['leave_cnt'], '#3B82F6'], [(int)$a['absent'], '#DC2626']], $pct($a['present'], $a['total']) . '%'), 32)
            . drLegend([['उपस्थित', (int)$a['present'], '#16A34A'], ['रजा', (int)$a['leave_cnt'] . ($x['leaveToday'] ? ' (मंजूर ' . $x['leaveToday'] . ')' : ''), '#3B82F6'], ['गैरहजर', (int)$a['absent'], '#DC2626']]) . '</td>'
            . '<td><div class="muted">गेले 7 दिवस — उपस्थित कर्मचारी</div>' . drImg(drBars($x['trend'], $dayLbl, '#16A34A'), 112) . '</td></tr></table>';
        $html .= $kpis([$kpi('उशिरा आलेले', (int)$a['late'], '', $a['late'] ? '#B45309' : '#137A4B'), $kpi('अर्धा दिवस', (int)$a['half']), $kpi('रजा अर्ज प्रलंबित', $x['leavePending'], 'मंजुरीची वाट')]);
        if ($x['late']) { $html .= '<div class="muted">उशिरा: ' . $h(implode(', ', array_map(fn($r) => $r['name'] . ' (' . substr($r['t'], 0, 5) . ')', $x['late']))) . '</div>'; }
    }

    // --- Tasks ---
    if (!empty($S['tasks'])) {
        $t = $S['tasks']['t'];
        $html .= $sec(++$n, 'Tasks');
        $html .= $kpis([$kpi('आज नवीन', (int)$t['new_cnt']), $kpi('आज पूर्ण', (int)$t['done'], '', '#137A4B'), $kpi('एकूण प्रलंबित', (int)$t['open_cnt']), $kpi('मुदतबाह्य', (int)$t['overdue'], '', $t['overdue'] ? '#B42318' : '#137A4B'), $kpi('उद्याची मुदत', (int)$t['due_tomorrow'])]);
        if ($S['tasks']['over']) {
            $html .= '<table class="g"><thead><tr><th style="width:44%;">मुदतबाह्य Task</th><th style="width:20%;">विभाग</th><th style="width:22%;">कोणाकडे</th><th class="num-r" style="width:14%;">उशीर</th></tr></thead><tbody>';
            foreach ($S['tasks']['over'] as $r) { $html .= '<tr><td>' . $h(mb_strimwidth($r['title'], 0, 60, '…')) . '</td><td>' . $h($r['dept']) . '</td><td>' . $h($r['who']) . '</td><td class="num-r" style="color:#B42318;font-weight:bold;">' . (int)$r['late'] . ' दिवस</td></tr>'; }
            $html .= '</tbody></table>';
        }
    }

    // --- विनंत्या ---
    if (!empty($S['requests'])) {
        $r = $S['requests']['r'];
        $html .= $sec(++$n, 'कामाच्या विनंत्या (Work Requests)');
        $html .= $kpis([$kpi('आज आल्या', (int)$r['new_cnt']), $kpi('आज पूर्ण', (int)$r['done'], '', '#137A4B'), $kpi('प्रलंबित', (int)$r['open_cnt']), $kpi('SLA उलटलेल्या', (int)$r['sla'], '', $r['sla'] ? '#B42318' : '#137A4B')]);
        if ($S['requests']['cat']) {
            $cols = ['#D9530F', '#7B3F9E', '#2563EB', '#16A34A', '#F59E0B'];
            $parts = []; $leg = [];
            foreach ($S['requests']['cat'] as $i => $c) { $parts[] = [(int)$c['c'], $cols[$i % 5]]; $leg[] = [$c['cat'], (int)$c['c'], $cols[$i % 5]]; }
            $html .= '<table class="split"><tr><td style="width:30%;">' . drImg(drDonut($parts, (string)(int)$r['open_cnt']), 30) . '</td><td style="padding-top:8px;"><div class="muted">प्रलंबित विनंत्या — प्रकारानुसार</div>' . drLegend($leg) . '</td></tr></table>';
        }
        if ($S['requests']['sla']) {
            $html .= '<table class="g"><thead><tr><th style="width:16%;">क्रमांक</th><th style="width:48%;">विनंती (SLA उलटलेली)</th><th style="width:22%;">विभाग</th><th class="num-r" style="width:14%;">उशीर</th></tr></thead><tbody>';
            foreach ($S['requests']['sla'] as $x) { $hrs = (int)$x['hrs']; $html .= '<tr><td>' . $h($x['request_code']) . '</td><td>' . $h(mb_strimwidth($x['title'], 0, 60, '…')) . '</td><td>' . $h($x['dept']) . '</td><td class="num-r" style="color:#B42318;font-weight:bold;">' . ($hrs >= 48 ? round($hrs / 24) . ' दिवस' : $hrs . ' तास') . '</td></tr>'; }
            $html .= '</tbody></table>';
        }
    }

    // --- पत्रव्यवहार ---
    if (!empty($S['letters'])) {
        $l = $S['letters']['l'];
        $html .= $sec(++$n, 'पत्रव्यवहार व मंजुऱ्या');
        $html .= $kpis([$kpi('आज नवीन पत्रं', (int)$l['new_cnt']), $kpi('आज पूर्ण', (int)$l['done'], '', '#137A4B'), $kpi('आज रवाना', (int)$l['dispatched']), $kpi('मंजुरीसाठी प्रलंबित', (int)$l['pending'], (int)$l['urgent'] . ' तातडीची/उच्च', $l['urgent'] ? '#B45309' : '#191C3A')]);
        if ($S['letters']['hold']) {
            $html .= '<table class="g"><thead><tr><th style="width:52%;">प्रलंबित पत्र</th><th style="width:28%;">सध्या कोणाकडे</th><th class="num-r" style="width:20%;">थांबलेलं</th></tr></thead><tbody>';
            foreach ($S['letters']['hold'] as $x) { $html .= '<tr><td>' . $h(mb_strimwidth($x['subject'], 0, 70, '…')) . (in_array($x['priority'], ['urgent', 'high'], true) ? ' <span class="pill" style="background:#FDECEC;color:#B42318;">' . ($x['priority'] === 'urgent' ? 'तातडीचं' : 'उच्च') . '</span>' : '') . '</td><td>' . $h($x['holder'] ?: '—') . '</td><td class="num-r">' . max(0, (int)$x['idle']) . ' दिवस</td></tr>'; }
            $html .= '</tbody></table>';
        }
    }

    // --- खर्च ---
    if (!empty($S['spend'])) {
        $x = $S['spend'];
        $html .= $sec(++$n, 'खर्च', 'Budget व्यवहार');
        $html .= '<table class="split"><tr><td style="width:38%;padding-right:8px;">' . $kpis([$kpi('आजचा खर्च', weeklyReportMoney($x['spend']), $x['paid'] ? 'Payments: ' . weeklyReportMoney($x['paid']) : '')]);
        if ($x['cat']) { foreach ($x['cat'] as $c) { $html .= '<div style="font-size:8.8pt;margin:2px 0;">' . $h($c['category']) . ' — <b>' . weeklyReportMoney($c['amt']) . '</b></div>'; } }
        $html .= '</td><td><div class="muted">गेले 7 दिवस — खर्च (₹ हजारांत)</div>' . drImg(drBars(array_map(fn($v) => round($v / 1000), $x['trend']), $dayLbl, '#7B3F9E'), 100) . '</td></tr></table>';
        if ($x['dept']) {
            $max = max(1, max(array_column($x['dept'], 'amt')));
            $html .= '<table class="g" style="margin-top:4px;"><thead><tr><th style="width:30%;">विभाग</th><th style="width:48%;"></th><th class="num-r" style="width:22%;">आज</th></tr></thead><tbody>';
            $html .= deptGroupRowsHtml($pdo, $orgId, $x['dept'], 3, fn($r) => '<tr><td>' . $h($r['dept']) . '</td><td>' . weeklyReportBar($r['amt'] / $max * 100, '#7B3F9E', 76) . '</td><td class="num-r"><b>' . weeklyReportMoney($r['amt']) . '</b></td></tr>');   // Campus नुसार
            $html .= '</tbody></table>';
        }
    }

    // --- वाहने / वसतिगृह / पाहुणे (एकत्र छोटे भाग) ---
    if (!empty($S['vehicles'])) {
        $x = $S['vehicles'];
        $html .= $sec(++$n, 'वाहने');
        $html .= $kpis([$kpi('आजच्या फेऱ्या', (int)$x['v']['trips'], (int)$x['v']['veh'] . ' वाहने'), $kpi('अंतर', number_format((float)$x['v']['km']) . ' km'), $kpi('इंधन', round((float)$x['f']['l'], 1) . ' L', weeklyReportMoney($x['f']['c'])), $kpi('कागदपत्रं संपत आहेत', $x['docs'], '15 दिवसांत', $x['docs'] ? '#B45309' : '#137A4B')]);
    }
    if (!empty($S['hostel'])) {
        $x = $S['hostel']; $hh = $x['h']; $present = max(0, (int)$hh['marked'] - (int)$hh['absent']);
        $html .= $sec(++$n, 'वसतिगृह', 'एकूण विद्यार्थी ' . $x['total']);
        $html .= $kpis([$kpi('आजची हजेरी', (int)$hh['marked'] ? $pct($present, (int)$hh['marked']) . '%' : '—', (int)$hh['marked'] ? $present . '/' . (int)$hh['marked'] . ' उपस्थित' : 'हजेरी घेतलेली नाही', (int)$hh['marked'] ? '#137A4B' : '#B45309'), $kpi('गैरहजर', (int)$hh['absent'], '', $hh['absent'] ? '#B45309' : '#137A4B'), $kpi('रजेवर बाहेर', (int)$x['out']['out_cnt']), $kpi('परत येणं बाकी (उशीर)', (int)$x['out']['overdue'], '', $x['out']['overdue'] ? '#B42318' : '#137A4B')]);
    }
    if (!empty($S['guests'])) {
        $x = $S['guests'];
        $html .= $sec(++$n, 'पाहुणे / अभ्यागत');
        $html .= '<table class="split"><tr><td style="width:38%;padding-right:8px;">' . $kpis([$kpi('आज आलेले', (int)$x['g']['total'], (int)$x['g']['inside'] . ' अजून आत (Check-out नाही)', $x['g']['inside'] ? '#B45309' : '#191C3A')]) . '</td><td><div class="muted">गेले 7 दिवस — अभ्यागत</div>' . drImg(drBars($x['trend'], $dayLbl, '#2563EB'), 100) . '</td></tr></table>';
    }

    // --- सुरक्षा व सुविधा ---
    if (!empty($S['facility'])) {
        $x = $S['facility'];
        $html .= $sec(++$n, 'सुरक्षा व सुविधा');
        $cells = [$kpi('SOS सूचना', (int)$x['sos']['total'], (int)$x['sos']['active'] ? (int)$x['sos']['active'] . ' अजून सुरू' : 'आज', $x['sos']['total'] ? '#B42318' : '#137A4B')];
        if ($x['cctvDvr']) { $cells[] = $kpi('CCTV तपासणी', $x['cctvChecked'] . '/' . $x['cctvDvr'], $x['camBad'] . ' कॅमेरे बंद/लक्ष द्या', $x['cctvChecked'] < $x['cctvDvr'] || $x['camBad'] ? '#B45309' : '#137A4B'); }
        $cells[] = $kpi('IT दुरुस्ती', (int)$x['it']['open_cnt'], (int)$x['it']['new_cnt'] . ' आज नवीन');
        $cells[] = $kpi('तक्रारी', (int)$x['gr']['open_cnt'], (int)$x['gr']['new_cnt'] . ' आज · ' . (int)$x['gr']['late'] . ' उशीर', $x['gr']['late'] ? '#B42318' : '#191C3A');
        $cells[] = $kpi('देखभाल नोंदी', (int)$x['mt']['n'], $x['mt']['c'] > 0 ? weeklyReportMoney($x['mt']['c']) : '');
        $html .= $kpis($cells);
    }

    // --- E-Campus ---
    if (!empty($S['ecampus'])) {
        $html .= $sec(++$n, 'E-Campus प्रगती', ecampusPeriodLabel(substr($D['date'], 0, 7)));
        $html .= $S['ecampus']['html'];
    }

    // --- नूतनीकरण ---
    if (!empty($S['renewals'])) {
        $html .= $sec(++$n, 'नूतनीकरण', 'मुदत संपलेली / 7 दिवसांत संपणारी');
        $html .= '<table class="g"><thead><tr><th style="width:46%;">परवाना / नूतनीकरण</th><th style="width:22%;">विभाग</th><th class="num-r" style="width:14%;">मुदत</th><th class="num-r" style="width:18%;">स्थिती</th></tr></thead><tbody>';
        foreach ($S['renewals'] as $x) { $late = $x['left_days'] < 0; $html .= '<tr><td>' . $h($x['item_name']) . '</td><td>' . $h($x['dept'] ?? '—') . '</td><td class="num-r">' . date('d/m/Y', strtotime($x['expiry_date'])) . '</td><td class="num-r" style="font-weight:bold;color:' . ($late ? '#B42318' : '#B45309') . ';">' . ($late ? abs($x['left_days']) . ' दिवस उलटले' : ($x['left_days'] == 0 ? 'आज' : $x['left_days'] . ' दिवस बाकी')) . '</td></tr>'; }
        $html .= '</tbody></table>';
    }

    // --- उद्याचं नियोजन ---
    if (!empty($S['tomorrow'])) {
        $x = $S['tomorrow'];
        $html .= $sec(++$n, 'उद्याचं नियोजन', weeklyReportMarathiDate(date('Y-m-d', strtotime('+1 day', strtotime($D['date'])))));
        $items = [];
        foreach ($x['events'] as $e) { $items[] = 'कार्यक्रम: <b>' . $h($e['name']) . '</b>' . ($e['start_time'] ? ' — ' . substr($e['start_time'], 0, 5) : '') . ($e['venue'] ? ', ' . $h($e['venue']) : ''); }
        foreach ($x['rooms'] as $b) { $items[] = 'खोली आरक्षण: ' . $h($b['room']) . ' — ' . date('H:i', strtotime($b['start_time'])) . ' (' . $h($b['purpose']) . ')'; }
        if ($x['tasks']) { $items[] = '<b>' . $x['tasks'] . '</b> Tasks ची उद्या मुदत'; }
        if ($x['ren']) { $items[] = '<b>' . $x['ren'] . '</b> नूतनीकरणांची उद्या मुदत संपते'; }
        foreach ($items as $it) { $html .= '<div class="al" style="background:#F3F1EE;border-left:3px solid #7B3F9E;">' . $it . '</div>'; }
    }

    // 👤 व्यक्तीनिहाय जबाबदारी
    require_once __DIR__ . '/report-smart.php';
    $html .= mgmtAccountabilityHtml(mgmtAccountability($pdo, $orgId, $D['date'], 10));

    if (!$S) { $html .= '<div class="al" style="background:#F3F1EE;margin-top:14px;">आज प्रणालीत कोणतीही नोंद झाली नाही.</div>'; }
    $html .= '<div class="muted" style="margin-top:16px;text-align:center;">!! आत्मा मालिक !! — E-Admin ने आपोआप तयार केलेला दैनिक अहवाल · ' . $h($D['org']) . '</div>';
    return $html;
}

function dailyReportPdf(array $D) {
    global $pdo;
    if (!isset($D['prevScore']) && $pdo instanceof PDO) {   // कालचा गुण — तुलनेसाठी
        try { $D['prevScore'] = (int)dailyReportData($pdo, (int)$D['orgId'], date('Y-m-d', strtotime($D['date'] . ' -1 day')))['score']; } catch (Throwable $e) {}
    }
    return mgmtReportPdf(dailyReportPdfHtml($D), 'दैनिक अहवाल — ' . $D['org'] . ' — ' . $D['date'], $D['org'], 'A4', [
        'link' => 'reports/daily.php?date=' . $D['date'], 'orgId' => $D['orgId'] ?? null,
        'subtitle' => 'दैनिक व्यवस्थापन अहवाल · ' . weeklyReportMarathiDate($D['date']),
    ]);
}

function dailyReportFileName(array $D) {
    return 'daily-report-' . (trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $D['org']), '-') ?: 'org') . '-' . $D['date'] . '.pdf';
}

function dailyReportEmailHtml(array $D) {
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES, 'UTF-8');
    $S = $D['sections'];
    $col = ['good' => '#137A4B', 'warn' => '#B45309', 'bad' => '#B42318'];
    $cell = fn($v, $l, $c = '#191C3A') => "<td style='width:25%;background:#F7F5F2;border-radius:8px;padding:10px 6px;text-align:center;'><div style='font-size:19px;font-weight:bold;color:$c;'>$v</div><div style='font-size:11px;color:#64708A;'>$l</div></td>";
    $w = $S['work']['w'] ?? ['total' => 0, 'done' => 0]; $a = $S['attendance']['a'] ?? null;
    $alerts = '';
    foreach (array_slice($D['alerts'], 0, 6) as [$lv, $msg]) { $alerts .= "<li style='margin:3px 0;color:" . $col[$lv] . ";'><span style='color:#1A1D29;'>" . $h($msg) . "</span></li>"; }
    foreach (array_slice($D['good'], 0, 2) as $msg) { $alerts .= "<li style='margin:3px 0;color:#137A4B;'><span style='color:#1A1D29;'>" . $h($msg) . "</span></li>"; }
    $url = siteBaseUrl();
    return "<div style='font-family:Arial,sans-serif;max-width:600px;margin:0 auto;padding:18px;background:#F3F4F7;'>
      <div style='background:#191C3A;padding:16px 20px;border-radius:10px 10px 0 0;border-bottom:4px solid #D9530F;'>
        <div style='color:#fff;font-size:18px;font-weight:bold;'>" . $h($D['org']) . "</div>
        <div style='color:#FDBA8C;font-size:13px;'>🌙 दैनिक अहवाल · " . $h(weeklyReportMarathiDate($D['date'])) . "</div></div>
      <div style='background:#fff;padding:18px 20px;border-radius:0 0 10px 10px;'>
        <p style='font-size:14px;color:#1A1D29;margin-top:0;'>नमस्कार,<br>आजचा सारांश खाली आहे. सगळ्या विभागांचा सविस्तर अहवाल Charts सह सोबतच्या <b>PDF</b> मध्ये आहे.</p>
        <table style='width:100%;border-collapse:separate;border-spacing:5px;margin:0 -5px;'><tr>"
        . $cell($D['score'] . '/100', 'आजचा गुण', $col[$D['level']])
        . $cell((int)$w['total'], 'कामाच्या नोंदी')
        . $cell($a && $a['total'] ? round($a['present'] * 100 / $a['total']) . '%' : '—', 'हजेरी')
        . $cell((int)($S['tasks']['t']['overdue'] ?? 0), 'मुदतबाह्य Tasks', ($S['tasks']['t']['overdue'] ?? 0) ? '#B42318' : '#137A4B')
        . "</tr></table>"
        . (!empty($S['ecampus']) ? "<div style='margin-top:12px;font-size:13px;background:#F7F5F2;border-radius:8px;padding:8px 12px;'>🎓 <b>E-Campus:</b> " . (int)$S['ecampus']['overall'] . "% पूर्ण — " . (int)$S['ecampus']['done'] . '/' . (int)$S['ecampus']['work'] .  " कामं पूर्ण — सविस्तर E-Campus अहवाल सोबतच्या दुसऱ्या PDF मध्ये</div>" : '')
        . ($alerts ? "<div style='font-weight:bold;font-size:14px;margin:14px 0 4px;color:#191C3A;'>आजचे ठळक मुद्दे</div><ul style='padding-left:18px;margin:0;font-size:13px;line-height:1.6;'>$alerts</ul>" : '')
        . ($url ? "<a href='{$url}reports/daily.php' style='display:inline-block;margin-top:16px;background:#D9530F;color:#fff;text-decoration:none;padding:10px 20px;border-radius:8px;font-size:13px;font-weight:bold;'>E-Admin मध्ये पाहा</a>" : '')
        . "<p style='font-size:11.5px;color:#8A90AC;margin-top:18px;'>!! आत्मा मालिक !! — हा अहवाल रोज संध्याकाळी आपोआप पाठवला जातो.</p>
      </div></div>";
}

function dailyReportEnabled(PDO $pdo, $orgId) { return orgSetting($pdo, $orgId, 'daily_report_enabled', '1') === '1'; }
function dailyReportHour(PDO $pdo, $orgId) { $h = (int)orgSetting($pdo, $orgId, 'daily_report_hour', '20'); return ($h >= 12 && $h <= 23) ? $h : 20; }

/** दैनिक अहवाल पाठवतो. $to दिलं तर फक्त त्यांना (Test). */
function dailyReportSend(PDO $pdo, $orgId, $date = null, ?array $to = null) {
    $recips = $to ?? weeklyReportRecipients($pdo, $orgId);
    if (!$recips) { return ['ok' => false, 'msg' => 'एकही Email पत्ता नाही (Management पदावरच्यांचे Email / जादा पत्ते तपासा)', 'sent' => 0]; }
    try {
        $D = dailyReportData($pdo, $orgId, $date ?: date('Y-m-d'));
        $pdf = dailyReportPdf($D);
    } catch (Throwable $e) {
        return ['ok' => false, 'msg' => 'अहवाल/PDF तयार करताना त्रुटी: ' . mb_substr($e->getMessage(), 0, 200), 'sent' => 0];
    }
    $files = [['name' => dailyReportFileName($D), 'data' => $pdf, 'type' => 'application/pdf']];
    require_once __DIR__ . '/ecampus-helpers.php';
    if ($ec = ecampusReportAttachment($pdo, $orgId, $D['org'])) { $files[] = $ec; } // 🎓 पूर्ण E-Campus अहवाल — वेगळी PDF
    $sent = eowmsSendMail($recips, '🌙 दैनिक अहवाल — ' . $D['org'] . ' (' . weeklyReportMarathiDate($D['date']) . ')', dailyReportEmailHtml($D), $files, $D['org'] . ' — E-Admin');
    $res = ['ok' => $sent > 0, 'msg' => $sent > 0 ? "दैनिक अहवाल $sent पत्त्यांवर पाठवला" : 'Server वरून Email जाऊ शकला नाही (mail() अयशस्वी)', 'sent' => $sent];
    if ($to === null) {
        try {
            orgSettingSet($pdo, $orgId, 'daily_report_last_date', $D['date']);
            orgSettingSet($pdo, $orgId, 'daily_report_last_at', date('Y-m-d H:i:s'));
            orgSettingSet($pdo, $orgId, 'daily_report_last_msg', ($res['ok'] ? '✓ ' : '✗ ') . $res['msg']);
        } catch (PDOException $e) {}
    }
    return $res;
}
